<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

//Use: php debug_job.php <Job ID>
//Example: php debug_job.php 25

//Dump a job from the database to stdout 

// Settings
$sql_host = '127.0.0.1';
$sql_user = '';
$sql_pass = '';
$sql_db = 'certs';

$sql_db_job = 'jobs';
$sql_db_log = 'logs';

// Checks
// Make sure we have a second argument (The Job ID)
if($argc <= 1){
    die("Failure, missing parameters.\r\nSyntax: \"" . $argv[0] . " \e[4mJob ID\e[0m\"\r\n");
}

// Verify that the Job ID we've been given is numeric
if(!is_numeric($argv[1])){
    die("Job ID Invalid (Not numeric)\r\n");
}

// Main

$mysql = mysqli_connect("127.0.0.1", "", "", "");
if (mysqli_connect_errno()) {
    die("Failure, error while connecting to database\r\n");
}

// Format SQL Query
$query_str = "SELECT $sql_db_job.id, $sql_db_job.start, $sql_db_job.end, $sql_db_job.added_time, $sql_db_job.claimed, $sql_db_job.claim_time, $sql_db_job.complete, $sql_db_job.complete_time, $sql_db_log.log FROM $sql_db_job JOIN $sql_db_log ON $sql_db_job.logid = $sql_db_log.id WHERE jobs.id='" . $argv[1] . "'";

// Run the query and die() on error
if(!$query = mysqli_query($mysql, $query_str)){
        die("<br/>Query error: " . mysqli_error($mysql));
    }

// If we got any results  display them, otherwise show error.
if($query->num_rows > 0){
    $row = mysqli_fetch_array($query, MYSQLI_ASSOC);
    
    echo("/----------------------------------\r\n");
    echo("| Results for Job ID: '" . $row['id'] . "'\r\n");
    echo("|----------------------------------\r\n");
    echo("| Added_time: '" . $row['added_time'] . "'\r\n");
    echo("| Log: '" . $row['log'] . "'\r\n");
    echo("| Range: '" . $row['start'] . " - " . $row['end'] . "'\r\n");
    echo("| Total size: '" . ($row['end'] - $row['start']) . "'\r\n");
    
    // Check job claim
    
    if($row['claimed'] == "1"){
        echo("| Claimed: Yes\r\n");
        if(strlen($row['claim_time']) > 1){
            echo("| Claim Date: '" . $row['claim_time'] . "'\r\n");
        }else{
            echo("| Claim Date: \e[4mUnknown??\e[0m\r\n");
        }
    }else if($row['claimed'] == "0"){
        echo("| Claimed: \e[4mNo\e[0m\r\n");
    }else{
        echo("| Claimed: \e[4mUnexpected claim state?!\e[0m\r\n");
    }
    
    // Check job completation
    
    if($row['complete'] == "1"){
        echo("| Completed: Yes\r\n");
        if(strlen($row['complete_time']) > 1){
            echo("| Completation Date: '" . $row['complete_time'] . "'\r\n");
        }else{
            echo("| Completation Date: \e[4mUnknown??\e[0m\r\n");
        }
    }else if($row['complete'] == "0"){
        echo("| Completed: \e[4mNo\e[0m\r\n");
    }else if($row['complete'] == "99"){
        echo("| Completed: \e[4mNo\e[0m, Failed\r\n");
        if(strlen($row['complete_time']) > 1){
            echo("| Failure Date: '" . $row['complete_time'] . "'\r\n");
        }else{
            echo("| Failure Date: Unspecified\r\n");
        }
    }else{
        echo("| Completed: \e[4mUnexpected Completation state?!\e[0m\r\n");
    }
    
    echo("\----------------------------------\r\n");
    
}else{
    die("Error: No results found for Job ID\r\n");
}
