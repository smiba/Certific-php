<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

// Job generation for PHP based CT Log downloader - This will populate the jobs table for workers to pick-up

//Use: php generate_jobs.php <CT Log> [Start ID] [End ID]
//Example: php generate_jobs.php ct.googleapis.com/logs/us1/argon2024 

set_time_limit(900); //Kill script after 900 seconds (15 min)

// Base values - These get populated as the code runs through its routines
$logurl = "";
$logid = -1;
$start = -1;
$end = -1;

$position = -1;
$max_per_request = -1;

// Settings
$insert_rows_max = 250; //Maximum rows to insert in a single query, this can be as large as you want as long as it does not make the queries bigger then max_allowed_packet (MySQL). 250 is a fair tradeoff

$safety_magin = 2; //Logs are weird? They may not always like getting their maximum value constantly queried and will sometimes return one certificate less, so query $safety_magin less than the maximum

$sql_host = '127.0.0.1';
$sql_user = '';
$sql_pass = '';
$sql_db = 'certs';

$sql_db_job = 'jobs';
$sql_db_log = 'logs';

// Checks
if($argc <= 1){ //Make sure we have atleast the LOG argument
    die("Failure, missing parameters.\r\nSyntax: \"" . $argv[0] . " \e[4mLOG\e[0m [\e[4mSTARTID\e[0m] [\e[4mENDID\e[0m]\"\r\n");
}

if(isset($argv[2])){ //If STARTID is set, run the numeric check
    if(!is_numeric($argv[2])){
        die("STARTID invalid\r\n");
    }else{
        $start = $argv[2];
    }
}

if(isset($argv[3])){ //If ENDID is set, run the numeric check
    if(!is_numeric($argv[3])){
        die("ENDID invalid\r\n");
    }else{
        $end = $argv[3];

        if($start >= $end){
        die("Failure, START equal or larger then END\r\n");
        }
    }
}

// Init - Setting up MySQL
$mysql = mysqli_init();
$mysql->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
$mysql->real_connect($sql_host, $sql_user, $sql_pass, $sql_db);

if (mysqli_connect_errno()) {
    die("Failure, error while connecting to database: " . mysqli_connect_error() . "\r\n");
}

// Part 1. Resolving logID

$query = mysqli_query($mysql, "SELECT id, log FROM " . $sql_db_log . " WHERE log = '" . $mysql->real_escape_string($argv[1]) . "' LIMIT 1;");
if($query->num_rows != 0){
    $result = $query->fetch_object();

    $logid = $result->id;
    $log = $result->log;
    echo "Using logid: " . $logid . "\r\n";
}else{
    die("Failure, log not found in database\r\n");
}

// Part 2. Finding log maximum per request

//Try to get 10000 results from a log, this should be too big and we will receive a truncated result, count this result and assume this is the maximum.
$result = file_get_contents("https://" . $log . "/ct/v1/get-entries?start=0&end=10000");
if($result === false){
    die("Failure while contacting log: " . $log . "\r\n");
}

$max_per_request = count(json_decode($result, TRUE)["entries"]) - $safety_magin;

if($max_per_request > 0){
    echo("Log maximum results per request: " . $max_per_request . " (safety_magin of " . $safety_magin . ")\r\n");
}else{
    die("Failure, safety_magin equal or higher then max_requests or maximum per request query failed\r\n");
}


// Part 3. Calculating start / end if required

if($start == -1){
    $query = mysqli_query($mysql, "SELECT last FROM " . $sql_db_log . " WHERE log = '" . $mysql->real_escape_string($argv[1]) . "' LIMIT 1;"); //This is the same as in Part 1, it might be benefical to combine the $start resolve

    if($query->num_rows != 0){
        $start = $query->fetch_object()->last;

    echo "Using start: " . $start . "\r\n";
    }else{
        die("Failure, log disappeared(?) from database\r\n");
    }
}

if($end == -1){
    //As we have no end target, get the log's tree_size and use that as our defined end target
    $result = file_get_contents("https://" . $log . "/ct/v1/get-sth");

    if($result === false){
        die("Failure while getting sth from log: " . $log . "\r\n");
    }

    $end = json_decode($result, TRUE)["tree_size"];

    if(!is_numeric($end)){
        die("Failure, tree_size received not numeric from log: " . $log . "\r\n");
    }else{
        $end = $end - 1; //Since we start counting at 0, reduce it with 1
        echo "Using end: " . $end . "\r\n";
    }
}

//If the defined end target is smaller than the defined start value, die(). This either means the input to the script (if $start and $end was provided in the arugments) was wrong, or the log is already up to date.
if($start >= $end){
    die("Failure, START equal or larger then END (Invalid input or log already up to date\r\n");
}

// Part 4. Populating jobs table

$position = $start;

$insert_size = 0;
$insert_start = true; //Tell our loop that this is the start of the INSERT query, this so it can put the INSERT INTO header in $query before populating it.
$query = "";

echo("Done: "); //We're going to echo every job batch to show the script is still working, This "Done:" is going to be followed by numbers output by the insert job below

do{

    //Check if we need to submit our INSERT query as we've research our row limit
    if($insert_size >= $insert_rows_max){ //Submit and rotate our insert query
        $query .= ";"; //Close query

        if($result = mysqli_query($mysql, $query)){
            echo($position . " ");
        }else{
        echo("Failure, coudn't execute SQL query:\r\n" . $query . "\r\n\r\n");
            die($mysql->error . "\r\n");
        }

        $insert_size = 0;
        $insert_start = true; //We've submitted our query, a new header ("INSERT INTO [..]") needs to be put in $query before populating it. This activates that logic
    }

    //Prepare $query
    if($insert_start){
        $query = "INSERT INTO " . $sql_db_job . "(`logid`, `start`, `end`) VALUES ";

        $insert_start = false; //We've written our query header (INSERT INTO), deactivate this logic for future runs
    }else{
        $query .= ","; //Add seperation character
    }

    if(($position + $max_per_request) > $end){
        $query .= "('" . $logid . "', '" . $position . "', '" . $end . "')";
        $position = $end;
    }else{
        $query .= "('" . $logid . "', '" . $position . "', '" . ($position + $max_per_request - 1) . "')";
        $position += $max_per_request;
    }

    $insert_size++;

} while ($position < $end);

//Flush the last query to the SQL server 
if($result = mysqli_query($mysql, $query)){
    echo($position . " ");
}else{
    echo("\r\nFailure, coudn't execute SQL query:\r\n" . $query . "\r\n\r\n");
    die($mysql->error . "\r\n");
}

// Update the database and write until where ($end) the log has been updated to. This tells us where we left off for future runs 
if($result = mysqli_query($mysql, "UPDATE " . $sql_db_log . " SET `last`='" . $end . "' WHERE `id`='" . $logid . "' LIMIT 1;")){
    if($mysql->affected_rows == 0){
        echo("\r\nError, coudn't UPDATE log table (no affected rows) when updating log last status to: " . $end . ", Please correct this issue manually when possible to prevent duplicate job generation");
    }
}else{
    echo("\r\nCoudn't update log last status to: " . $end . ", Please correct this issue manually when possible");
}

echo("!\r\n"); //Finish the echo Done: we've started prior. 
echo("Finished.\r\n");
