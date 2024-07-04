<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

// Wroker script for Certific, this script picks up jobs and processes them.
// This script is intended to be ran through the shell and will provide it's status through stdout

// Script NEEDS PHP7.3 or higher!

set_time_limit(0); //As this script is intended to run in a loop, explicitly state we don't want a time_limit

//Settings
$sql_host = '127.0.0.1';
$sql_user = '';
$sql_pass = '';
$sql_db = 'certs';

$sql_db_cert = 'certs';
$sql_db_der = 'ders';
$sql_db_log = 'logs';
$sql_db_url = 'urls';
$sql_db_job = 'jobs';
$sql_db_search_job = 'search_jobs';

//Set to true to log performance values for monitoring
$sql_want_perf = false;
$sql_db_perf = 'performance';

//Set to false to not have this process complete search jobs
$want_search_jobs = true;

//Script's internal variabels - These get modified and populated by the script as we go
$want_end = false; //Gets set to true when SIGINT is called, this allows for the script to carefully stop execution
$stats_loopcycles = 0;
$stats_jobs_processed = 0;
$start_time = time();

// Fuctions

function end_execution(){
    global $want_end;
    $want_end = true;
    echo "\r\nSIGINT received, stopping script when possible\r\n"; //Might want to spice this up with $want_end_level, so if SIGINT gets received multiple times it might do a faster shutdown (which would include dropping the work, instead of finishing its current job)
}

//DER to PEM Function
function der2pem($der_data) {
   $pem = chunk_split(base64_encode($der_data), 64, "\n");
   $pem = "-----BEGIN CERTIFICATE-----\n".$pem."-----END CERTIFICATE-----\n";
   return $pem;
}

function output_certificate($leaf_input, $extra_data, $padding = true, $split = true){
    //Credits to https://github.com/mk-j/PHP_CT_Reader
    $merkleTreeLeaf = base64_decode( substr($leaf_input, 0, 16) );
    $entryType = ord(substr($merkleTreeLeaf, 10, 1)) *256 +ord(substr($merkleTreeLeaf, 11, 1));
    if($entryType==0){ // x509_entry
        $length_bytes = base64_decode( substr($leaf_input, 16, 4) );
        $cert_length = current(unpack("N", "\x00".$length_bytes));
        $bin = base64_decode( substr($leaf_input, 20) );
        $leaf_cert = base64_encode( substr($bin, 0, $cert_length) );
    }else if($entryType==1){ // precertEntry
        $xtra = base64_decode($extra_data);//extract full leaf cert from extra_data
        $length_bytes = substr($xtra, 0, 3);
        $cert_length = current(unpack("N", "\x00".$length_bytes));
        $leaf_cert = base64_encode( substr($xtra, 3, $cert_length) );
    }else{
        return false;
    }

    $cert_pem = "";
    if($padding){
        $cert_pem = "-----BEGIN CERTIFICATE-----" . "\r\n";
    }
    if($split){
        $cert_pem .= chunk_split($leaf_cert, 64);
    }else{
        $cert_pem .= $leaf_cert;
    }
    if($padding){
        $cert_pem .= "-----END CERTIFICATE-----" . "\r\n";
        //DEBUG
        //echo("\r\n" . $cert_pem . "\r\n");
        //echo(print_r(openssl_x509_parse($cert_pem)) . "\r\n");
    }

    return $cert_pem;
}

function get_domains_from_cert($certificate){
    $x509_cert = openssl_x509_parse($certificate);

    if(!$x509_cert){
        return false; //OpenSSL couldn't parse the certificate
    }

    $domains = [];

    if(isset($x509_cert['subject']['CN']) && $x509_cert['subject']['CN'] != ""){
        if(is_array($x509_cert['subject']['CN'])){
            //Wtf? Apperently a VERY rare amount (Less then 1 in 1 million from what I've seen) lists multiple CN's in their certificate
            foreach($x509_cert['subject']['CN'] as $domain){
                if(!in_array($domain, $domains)){
                    $domains[] = $domain;
                }
            }
        }else{
            $domains[] = $x509_cert['subject']['CN'];
        }
    }

    if(isset($x509_cert['extensions']['subjectAltName'])){
        foreach(explode(', ', $x509_cert['extensions']['subjectAltName']) as $domain){
            if(!in_array(str_replace('DNS:', '', $domain), $domains)){
                $domains[] = str_replace('DNS:', '', $domain);
            }
        }
    }

    if(!empty($domains)){
        return $domains;
    }else{
        if($x509_cert['subject']['O'] != ""){
            $domains[] = $x509_cert['subject']['O'];
            return $domains;
        }else{
            return false; //No domains found, return false
        }
    }
}

function update_cli_status($status){
    echo("\033[2K\rStatus: " . $status);
}

// Main

update_cli_status("Connecting to database");

pcntl_async_signals(true); // Handle syscalls
pcntl_signal(SIGINT,"end_execution"); //If SIGINT gets called, run end_execution

$mysql = mysqli_init();
$mysql->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
$mysql->real_connect($sql_host, $sql_user, $sql_pass, $sql_db);

if (mysqli_connect_errno()) {
    die("\r\nFailure, error while connecting to database: " . mysqli_connect_error() . "\r\n");
}

mysqli_query($mysql, "SET @@low_priority_updates=1;"); //Make UPDATE and INSERT be low priority, so it doesn't block SELECT statements from other services (like the frontend)

//
// MAIN LOOP
//

do{
    if($want_search_jobs){
        //
        // WARNING: Search still happens with the php_rw (DB Read-Write) user in this script, meaning we are (to some degree) at risk of damage! 
        //          As search accepts queries from the outside (web interface), a set of security mistakes may lead to devestating effects
        //          We should really look into moving this part to a php_ro (DB Read-Only) script to prevent possible mistakes.
        //
    
        // Part 1. Get avaliable jobs (search)
        update_cli_status("Checking avaliable jobs (search)");
        $query = mysqli_query($mysql, "SELECT " . $sql_db_search_job . ".* FROM " . $sql_db_search_job . " WHERE claimed = '0' LIMIT 1;");
        if($query->num_rows != 0){ //Process search job
            $results = $query->fetch_array();
            if($want_end){ break; } //We can prematurely break here if the end is called, as haven't claimed anything yet
            
            update_cli_status("Claiming search job with id (" . $results['id'] . ")");
            if($query = mysqli_query($mysql, "UPDATE " . $sql_db_search_job . " SET `claimed`='1', `claim_time`=current_timestamp() WHERE `id`='" . $results['id'] . "';")){
                if($mysql->affected_rows == 0){ // Since this can race condition, the UPDATE /may/ not succeed because another thread already grabbed the job, in that case sleep for a very tiny bit and try again
                    usleep(5000);
                    continue; //Go back to the start of the loop
                }
                
                // Part 2. Mark the job as taken
                // (We got the job! Time to get to work)
                
                $curr_id = $results['id'];
                $curr_search = $results['search'];
                
                try{ //Massive try catch, maybe questionable design? I'd say its approperiate in a job based system, as long as its limited to single jobs themselves. Fail the job and pick up the next one as usual on errors.
                    update_cli_status("Job [S](" . $curr_id . ") - Running \"" . $curr_search . "\" search query...");
                    
                    // Part 3. Prepare the query 
                    // Specify this is a HIGH PRIORITY query as there is a user waiting on it's result, this will go before ANY other SELECT queries that might be waiting (As long as they're not HIGH PRIORITY either)
                    $query_str = "SELECT HIGH_PRIORITY certs.*, urls.url, HEX(ders.hash) AS der_hash, ders.validfrom, ders.validto, logs.log
                                    FROM certs
                                    JOIN urls ON certs.urlid = urls.id
                                    STRAIGHT_JOIN ders ON certs.derid = ders.id
                                    STRAIGHT_JOIN logs ON certs.logid = logs.id
                                    WHERE urlid IN (SELECT id FROM urls ";
                                        
                    if(substr_count($curr_search, '%') > 1){
                        // We can't deal with multiple wildcards as this would require a full DB scan, reject these requests. (Frontend shoudn't have accepted this input in the first place)
                        throw new Exception("Search query contained multiple wildcards - Aborted job");
                    }elseif(substr_count($curr_search, '%') > 0){
                        if(strlen($curr_search) == 1){ //Only received a wildcard character, don't accept this tomfoolery and reject the query. 
                            throw new Exception("Search query contained just a single wildcard(!!!) - Aborted job");
                        }elseif(mb_substr($curr_search, 0, 1) == '%'){ //Wildcard at start of query
                            // Use the vurl VIRTUAL column to deal with start of query records. 
                            $query_str .= "WHERE vurl LIKE REVERSE('%" . $mysql->real_escape_string(mb_substr($curr_search, 1)) . "')";
                        }elseif(mb_substr($curr_search, (strlen($curr_search) - 1), 1) == '%'){ //Wildcard at end of query
                            $query_str .= "WHERE url LIKE('" . $mysql->real_escape_string(mb_substr($curr_search, 0, (strlen($curr_search) - 1))) . "%')";
                        }else{
                            // We can't deal with wildcards in any other spot than the start or end, wildcards in other places will result in extremely expensive full table scans.
                            throw new Exception("Search query contained wildcard, but it was not found at the end or the start (middle wildcard) - Aborted job");
                        }
                    }else{
                        // Regular query without any wildcards
                        $query_str .= "WHERE url = '" . $mysql->real_escape_string($curr_search) . "'";
                    }
                    $query_str .= ") ORDER BY validfrom DESC;";

                    // Part 4. Execute the query
                    $query = mysqli_query($mysql, $query_str);
                    $json_output = "";
                    
                    // Part 5. Process the query and prepare it for storage in DB
                    if($query->num_rows != 0){
                        $result_array = array();
                        
                        while($row = $query->fetch_assoc()){
                            $result_array[]=$row;
                        }
                        
                        //Convert the returned rows into json
                        $json_output = json_encode($result_array);
                    }
                    
                    
                    // Part 6. Store the results in the database
                    update_cli_status("Job [S](" . $curr_id . ") - Finishing job");
                    if($json_output == ""){
                        // No results were found, just mark the job als complete without putting in any results.
                        if($query = mysqli_query($mysql, "UPDATE " . $sql_db_search_job . " SET `complete`='1', `complete_time`=current_timestamp() WHERE `id`='" . $curr_id . "';")){
                            if($mysql->affected_rows == 0){
                                echo "\r\nError, coudn't UPDATE search jobs table to set the job to completed, this has to be manually corrected...\r\n";
                            }
                        }
                    }else{
                        if($query = mysqli_query($mysql, "UPDATE " . $sql_db_search_job . " SET `result`='" . $mysql->real_escape_string($json_output) . "', `complete`='1', `complete_time`=current_timestamp() WHERE `id`='" . $curr_id . "';")){
                            if($mysql->affected_rows == 0){
                                echo "\r\nError, coudn't UPDATE search jobs table to set the job to completed, this has to be manually corrected...\r\n";
                            }
                        }
                    }
                    

                }catch(Exception $e){
                    // Something went wrong during execution, mark the job as failed. This could be because of bugs, or because the job is invalid.
                    echo "\r\nCaught exception: ",  $e->getMessage(), " - Setting search job id '" . $curr_id . "' to failed\r\n";

                    try{
                        if($query = mysqli_query($mysql, "UPDATE " . $sql_db_search_job . " SET `complete`='99', `complete_time`=current_timestamp() WHERE `id`='" . $curr_id . "';")){
                            if($mysql->affected_rows == 0){
                                echo "\r\nError, coudn't UPDATE jobs table to set the search job to completed, this has to be manually corrected...\r\n";
                            }
                        }
                    }catch(Exception $e){
                        // Double Exception! Happens when the database has gone away or the job row has dissapeared from the DB. In that case just echo the error and move on?
                        echo("\r\nFAIL: Coudn't update search job table to set complete to failed (99)...\r\n");
                    }

                    update_cli_status("Waiting after Exception");

                    sleep(1); //1s - May remove this in the future and give it just a few miliseconds of sleep, I don't think there is a lot of benefit to sleeping (for the most part) and it just puts the worker out of commission for a second. 
                }
            }
        }
    }
    
    // Part 1. Get avaliable jobs (ctlog)
    update_cli_status("Checking avaliable jobs (ctlog)");
    $query = mysqli_query($mysql, "SELECT " . $sql_db_job . ".*, logs.log FROM " . $sql_db_job . " LEFT JOIN " . $sql_db_log . " ON " . $sql_db_job . ".logid = " . $sql_db_log . ".id  WHERE claimed = '0' LIMIT 1;");
    if($query->num_rows != 0){ //Process ctlog job
        $results = $query->fetch_array();
        if($want_end){ break; } //We can prematurely break here if the end is called, as haven't claimed anything yet

        // Part 2. Mark job as taken, if that doesn't succeed go to end of loop
        // It might be beneficial to integrate this in Part 1, make it a SELECT and Update so there is no race condition going on. Should definitly look into, this can become problematic when having a lot of workers running
            update_cli_status("Claiming ctlog job with id (" . $results['id'] . ")");
        if($query = mysqli_query($mysql, "UPDATE " . $sql_db_job . " SET `claimed`='1', `claim_time`=current_timestamp() WHERE `id`='" . $results['id'] . "';")){
            if($mysql->affected_rows == 0){ // Since this can race condition, the UPDATE /may/ not succeed because another thread already snatched the job, in that case sleep for a very tiny bit and try again
                usleep(5000);
                continue; //Go back to the start of the loop
            }

            //We got the job! Time to get to work

            //For performance measurment -- Happens regardless of $want_performance, difference is that if $want_performance is false, it's results are not stored.
            $start = microtime(true);

            $curr_id = $results['id'];
            $curr_logid = $results['logid'];
            $curr_log = $results['log'];
            $curr_start = $results['start'];
            $curr_end = $results['end'];

            try{ //Massive try catch, maybe questionable design? I'd say its approperiate in a job based system, as long as its limited to single jobs themselves. Fail the job and pick up the next one as usual on errors.
                // Part 3. Download the entries from the log
                update_cli_status("Job [CT](" . $curr_id . ") - Downloading entries...");
                $result = file_get_contents("https://" . $curr_log . "/ct/v1/get-entries?start=" . $curr_start . "&end=" . $curr_end);
                if($result === false){
                    throw new Exception('Failed to execute file_get_contents() on get-entries');
                }

                $result = json_decode($result, true, 8, JSON_THROW_ON_ERROR);

                //Making sure we received the expected amount of results
                if(($curr_end - $curr_start + 1) != count($result['entries'])){
                    throw new Exception("Didn't receive the expected amount of entries (Expecting " . ($curr_end - $curr_start + 1) . ", Received " . count($result['entries']) . ")\r\n URL: https://" . $curr_log . "/ct/v1/get-entries?start=" . $curr_start . "&end=" . $curr_end);
                }

                // Part 4. Process the entries
                update_cli_status("Job [CT](" . $curr_id . ") - Processing entries");
                $url_array = []; //Empty URL array
                $url_unresolved_array = []; //Empty array to store unresolved URLs in so we can insert them
                $der_array = []; //Empty DER array
                $der_unresolved_array = []; //Unresolved DERs go here

                foreach($result['entries'] as $certificate){
                    $cert_full = output_certificate($certificate['leaf_input'], $certificate['extra_data']);
                    $cert_parsed = openssl_x509_parse($cert_full);

                    // Part 4.1 Add certificate to der_array
                    $der = output_certificate($certificate['leaf_input'], $certificate['extra_data'], false, false);
                    $der_hash = hash("sha256",  base64_decode($der));

                    $query = mysqli_query($mysql, "SELECT id FROM " . $sql_db_der . " WHERE `hash`=UNHEX('" . $der_hash . "') LIMIT 1;");
                    if($query->num_rows != 0){ //Does exist, note it
                        $der_array[$der_hash] = $query->fetch_object()->id;
                    }else{
                        $der_array[$der_hash] = "-1"; //We haven't resolved the position yet, mark this as -1
                        $der_unresolved_array[] = $der;
                    }

                    // Part 4.2. Add resolved urls to url_array and mark unresolved urls for insertion

                    $cert_domains = get_domains_from_cert($cert_full);
                    if($cert_domains == FALSE){
                        throw new Exception('Tried to process certificate, but no domains could be found in it');
                    }

                    foreach($cert_domains as $domain){
                        if(!isset($url_array[$domain])){ //Only process domains that aren't resolved already
                            $query = mysqli_query($mysql, "SELECT id FROM " . $sql_db_url . " WHERE `url`='" . $mysql->real_escape_string($domain) . "' LIMIT 1;");
                            if($query->num_rows != 0){ //Does exist, note it
                                $url_array[$domain] = $query->fetch_object()->id;
                            }else{
                                $url_array[$domain] = "-1"; //We haven't resolved the position yet, mark this as -1
                                $url_unresolved_array[] = $domain;
                            }
                        }
                    }
                }

                // Part 4.3. Process unresolved ders into database
                update_cli_status("Job [CT](" . $curr_id . ") - Processing unresolved ders");

                if(!empty($der_unresolved_array)){
                    $built_values = ""; //VALUES for the INSERT query that will follow

                    foreach($der_unresolved_array as $der){
                        $der_raw = base64_decode($der);

                        $cert_parsed = openssl_x509_parse(der2pem($der_raw));

                        if($cert_parsed == FALSE){
                            throw new Exception("Parsing certificate failed while processing ders");
                        }

                        if($built_values == ""){ //First
                            $built_values = "('" . $mysql->real_escape_string($der_raw) . "', '" . $cert_parsed['validFrom_time_t'] . "', '" . $cert_parsed['validTo_time_t'] . "')";
                        }else{
                            $built_values .= ",('" . $mysql->real_escape_string($der_raw) . "', '" . $cert_parsed['validFrom_time_t'] . "', '" . $cert_parsed['validTo_time_t'] . "')";
                        }
                    }

                    //Mass insert new DERs
                    $warning_count = $mysql->warning_count; //Note the current warning count

                    if($query = mysqli_query($mysql, "INSERT IGNORE INTO " . $sql_db_der . "(`der`, `validfrom`, `validto`) VALUES " . $built_values . ";")){
                        //Check if we had any warnings, this likely indicates one or more DERs were inserted in between our SELECT and INSERT statement, resolve ALL DERs again and don't rely on the "assumed ID" method.
                        //This for example can happen when multiple scripts are running which are processing the same DERs, meaning they both want to insert the DER but only one can succeed (race condition)
                        //Making this code "safe" from this somewhat rare race condition incurs a much more expensive performance loss. "Assumed ID" method is a safe and very effective shortcut if the conditions are right for it though.
                        if($warning_count < $mysql->warning_count){
                            foreach($der_unresolved_array as $der){
                                //Check all DER's again, by now they should all exist. If not (and the warnings came for example from a whole different error), die().
                                $der_hash = hash("sha256", base64_decode($der));

                                $query = mysqli_query($mysql, "SELECT id FROM " . $sql_db_der . " WHERE `hash`=UNHEX('" . $der_hash . "') LIMIT 1;");
                                if($query->num_rows > 0){
                                    $der_array[$der_hash] = $query->fetch_object()->id;
                                }else{
                                    throw new Exception("DER is not inserted into database. Can't continue...");
                                }
                            }
                        }else{ //Assumed ID (very fast)
                            $count = 0;
                            foreach($der_unresolved_array as $der){
                                $der_hash = hash("sha256", base64_decode($der));

                                $der_array[$der_hash] = ($mysql->insert_id + $count);
                                $count++;
                            }
                        }
                    }else{
                        throw new Exception($mysql->error);
                    }

                    //Sanity check - Although this one is mostly for the assumed ID method, it can't hurt.
                    $der_hash = hash("sha256", base64_decode(end($der_unresolved_array)));
                    $query = mysqli_query($mysql, "SELECT id FROM " . $sql_db_der . " WHERE `hash`=UNHEX('" . $der_hash . "') LIMIT 1;");

                    if($query->num_rows != 0){
                        $returned_id = $query->fetch_object()->id;
                        if($returned_id != $der_array[$der_hash]){
                            throw new Exception("DER insert sanity check failed, wrong result received - Hash: " . $der_hash . ", id received: " . $returned_id . ", id expected: " . $der_array[$der_hash]);
                        }
                    }else{
                        throw new Exception("DER insert sanity check failed with no results");
                    }
                }

                // Part 4.4. Process unresolved urls into database
                update_cli_status("Job [CT](" . $curr_id . ") - Processing unresolved urls");

                if(!empty($url_unresolved_array)){
                    $built_values = ""; //VALUES for the INSERT query that will follow

                    foreach($url_unresolved_array as $domain){
                        if($built_values == ""){ //First
                            $built_values = "('" . $mysql->real_escape_string($domain) . "')";
                        }else{
                            $built_values .= ",('" . $mysql->real_escape_string($domain) . "')";
                        }
                    }

                    //Mass insert new URLs
                    $warning_count = $mysql->warning_count; //Note the current warning count
                    if($query = mysqli_query($mysql, "INSERT IGNORE INTO " . $sql_db_url . "(`url`) VALUES " . $built_values . ";")){
                        //Check if we had any warnings, this likely indicates one or more URLs were inserted in between our SELECT and INSERT statement, resolve ALL URLs again and don't rely on the "assumed ID" method.
                        //This for example can happen when multiple scripts are running which are processing the same URLs, meaning they both want to insert the URL but only one can succeed (race condition)
                        //Making this code "safe" from this somewhat rare race condition incurs a much more expensive performance loss. "Assumed ID" method is a safe and very effective shortcut if the conditions are right for it though.
                        
                        if($warning_count < $mysql->warning_count){
                            foreach($url_unresolved_array as $domain){
                                //Check all domains's again, by now they should all exist. If not (and the warnings came for example from a whole different error), die().
                                $query = mysqli_query($mysql, "SELECT id FROM " . $sql_db_url . " WHERE `url`='" . $domain . "' LIMIT 1;");
                                if($query->num_rows > 0){
                                    $url_array[$domain] = $query->fetch_object()->id;
                                }else{
                                    throw new Exception("URL is not inserted into database. Can't continue with URLid... Domain: " . $domain);
                                }
                            }
                        }else{ //Assumed ID (very fast)
                            $count = 0;
                            foreach($url_unresolved_array as $domain){
                                $url_array[$domain] = ($mysql->insert_id + $count);
                                $count++;
                            }
                        }
                    }else{
                        throw new Exception($mysql->error);
                    }

                    //Sanity check - Although this one is mostly for the assumed ID method, it can't hurt.
                    $query = mysqli_query($mysql, "SELECT id FROM " . $sql_db_url . " WHERE `url`='" . end($url_unresolved_array) . "' LIMIT 1;");

                   if($query->num_rows != 0){
                        $returned_id = $query->fetch_object()->id;
                        if($returned_id != $url_array[end($url_unresolved_array)]){
                            throw new Exception("URL insert sanity check failed, wrong result received - URL: " . end($url_unresolved_array) . ", id received: " . $returned_id . ", id expected: " . $url_array[end($url_unresolved_array)]);
                        }
                    }else{
                        throw new Exception("URL insert sanity check failed with no results");
                    }
                }

                // Part 5. Prepare Cert entries
                update_cli_status("Job [CT](" . $curr_id . ") - Preparing cert entries");

                $built_values = ""; //VALUES for the INSERT query that will follow
                $position = $curr_start;

                foreach($result['entries'] as $certificate){
                    $cert_full = output_certificate($certificate['leaf_input'], $certificate['extra_data']);
                    $cert_parsed = openssl_x509_parse($cert_full);

                    $der_hash = hash("sha256",  base64_decode(output_certificate($certificate['leaf_input'], $certificate['extra_data'], false, false)));

                    $cert_domains = get_domains_from_cert($cert_full);
                    if($cert_domains == FALSE){
                        throw new Exception('Tried to process certificate, but no domains could be found in it');
                    }

                    foreach($cert_domains as $domain){
                        if($built_values != ""){ //Not the first, add a seperation character
                            $built_values .= ",";

                        }
                        $built_values .= "('" . $position . "', '" . $curr_logid . "', '" . $url_array[$domain] . "', '" . $der_array[$der_hash] . "')";
                    }
                    $position++;
                }

                // Part 5.1. Insert Cert entries
                update_cli_status("Job [CT](" . $curr_id . ") - Inserting cert entries");

                $warning_count = $mysql->warning_count; //Note the current warning count
                $position = $curr_start;

                $query = mysqli_query($mysql, "INSERT IGNORE INTO " . $sql_db_cert . "(`entryid`, `logid`, `urlid`, `derid`) VALUES " . $built_values . ";");
                if($warning_count < $mysql->warning_count){
                    foreach($result['entries'] as $certificate){ //As we've had a warner we need to run some checks to verify all certs are in fact inserted. This can happen if we have overlap
                        $cert_full = output_certificate($certificate['leaf_input'], $certificate['extra_data']);
                        $cert_domains = get_domains_from_cert($cert_full);

                        foreach($cert_domains as $domain){
                            $query_string = "SELECT id FROM " . $sql_db_cert . " WHERE entryid = '" . $position . "' AND logid = '" . $curr_logid . "' AND urlid = '" . $url_array[$domain] . "' LIMIT 1;";
                            if($query = mysqli_query($mysql, $query_string)){
                                if($query->num_rows == 0){
                                    throw new Exception('Cert insertion has been unsuccesful, no results on "' . $query_string . '"');
                                }
                            }else{
                                throw new Exception($mysql->error);
                             }
                        }

                        $position++;
                    }
                }

                update_cli_status("Job [CT](" . $curr_id . ") - Finishing job");
                if($query = mysqli_query($mysql, "UPDATE " . $sql_db_job . " SET `complete`='1', `complete_time`=current_timestamp() WHERE `id`='" . $curr_id . "';")){
                    if($mysql->affected_rows == 0){
                        echo "\r\nError, coudn't UPDATE jobs table to set the job to completed, this has to be manually corrected...\r\n";
                    }
                }

                $end = microtime(true);

                $stats_jobs_processed++;

                if($sql_want_perf){
                    $finishtimemiliseconds = round((microtime(true) - $start) * 1000);
                    $timestamp = time();

                    $certs          = count($result['entries']);
                    $urls           = count($url_array) - 1;
                    $urls_inserted  = count($url_unresolved_array);
                    $ders_inserted  = count($der_unresolved_array);

                    mysqli_query($mysql, "INSERT IGNORE INTO " . $sql_db_perf . "(`jobid`, `timestamp`, `der_inserted`, `url`, `url_inserted`, `cert`, `time`) VALUES('" . $curr_id . "', '" . $timestamp . "', '" . $ders_inserted . "', '" . $urls . "', '" . $urls_inserted . "', '" . $certs . "', '" . $finishtimemiliseconds . "');");
                }
            }catch(Exception $e){
                echo "\r\nCaught exception: ",  $e->getMessage(), " - Setting ctlog job id '" . $curr_id . "' to failed\r\n";
                try{
                    if($query = mysqli_query($mysql, "UPDATE " . $sql_db_job . " SET `complete`='99', `complete_time`=current_timestamp() WHERE `id`='" . $curr_id . "';")){
                        if($mysql->affected_rows == 0){
                            echo "\r\nError, coudn't UPDATE ctlog jobs table to set the job to completed, this has to be manually corrected...\r\n";
                        }
                    }
                }catch(Exception $e){
                     echo("\r\nFAIL: Coudn't update job table to set complete to failed (99)...\r\n");
                }

                update_cli_status("Waiting after Exception");

                sleep(2); //2s - Just wait for a second (or two). Might help if we're being ratelimited - Optimally we want to reduce this from happening by switching between different jobs for logs.
            }
        }else{
            usleep(5000); //0.005s
        }
    }else{ // No jobs avaliable, just sleep
        update_cli_status("Idle");
        sleep(2); //Sleep for 2 seconds, lowers load on SQL database
    }

    $stats_loopcycles++;
} while(!$want_end);


die("\r\nStopped. (runtime of " . (time() - $start_time) . "s, " . $stats_loopcycles . " cycles having processed " . $stats_jobs_processed . " jobs)\r\n");

//
// END OF MAIN LOOP
//
