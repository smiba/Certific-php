<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

// Search page / Home Page
// -- Rather messy code that is quickly written years ago as an example, sorry!

ob_start();
?>

<html>
<head>
<link rel="stylesheet" href="walter.css"/>
<style>
table, th, td {
  border: 1px solid black;
}
</style>
</head>
<body>
<h2>Search cert database:</h2>
(Wildcards are accepted like %.foo or foo.%)<br/><br/>
<form action="" method="POST">
  <label for="query">Query:</label><br>

<?php
$mysql = mysqli_connect("127.0.0.1", "", "", "certs");
if(isset($_POST['query'])){
    $failure = false;
    $request = $_POST['query'];


    if (mysqli_connect_errno()) {
        die("Failure, error while connecting to database");
    }

    if(substr_count($request, '%') > 1){
        $prepared_table = "<b>More then 1 wildcard is not supported</b>";
        $failure = true;
        // Handle more then 1 wildcard (Not supported)
    }elseif(substr_count($request, '%') > 0){
        if(strlen($request) == 1){ //Just a wildcard
            $prepared_table = "<b>Just a single wildcard (and nothing else) is obviously not supported</b>";
            $failure = true;
        }elseif((mb_substr($request, 0, 1) == '%') || (mb_substr($request, (strlen($request) - 1), 1) == '%')){ //We will allow these (May change if statements so an empty elseif is not required, this is part of old code)
        }else{
            $prepared_table = "<b>Having the wildcard at somewhere else then the start or the end is not supported</b>";
            $failure = true;
            // Handle wildcard not at start or end (Not supported)
        }
    }

    if(!$failure){
        $search_key = bin2hex(random_bytes(4));
        $query_str = "INSERT INTO search_jobs(`search`,`key`) VALUES ('" . $mysql->real_escape_string($request) . "','" . $search_key . "');";

        $query = mysqli_query($mysql, $query_str);
        if(!$query){
            die("<br/>Query error: " . mysqli_error($mysql));
        }
        $search_id = $mysql->insert_id;

        ob_end_clean(); //Flush initial http output
        sleep(1);
        header("Location: index.php?id=" . $search_id . "&key=" . $search_key); //Redirect to the search_collect page
        die(); //Die prematurely, as we're not going to need any future output
    }
}elseif(isset($_GET['id']) && isset($_GET['key'])){
    $query_str = "SELECT `search`,`result`,`complete` FROM search_jobs WHERE `id`='" . $mysql->real_escape_string($_GET['id']) . "' AND `key`='" . $mysql->real_escape_string($_GET['key']) . "' LIMIT 1;";

    if(!$query = mysqli_query($mysql, $query_str)){
        die("<br/>Query error: " . mysqli_error($mysql));
    }

    ob_end_flush();
    if($query->num_rows > 0){
        $row = mysqli_fetch_array($query, MYSQLI_ASSOC);
        $request = $row['search'];
        if($row['complete'] == 0){
            $prepared_table = "<b>Result not ready yet... (reloading in 2 seconds)</b><meta http-equiv=\"refresh\" content=\"2\">";
        }elseif($row['complete'] == 99){
            $prepared_table = "<b>Error while gathering results (Query failed)</b>";
        }elseif($row['result'] == ""){
            $prepared_table = "<b>No results...</b>";
        }else{
            $results = (array)json_decode($row['result'], true);
            $row = null;
            ob_start();

            echo "<table style=\"width:100%\"><tr><td><b>ID</b></td><td><b>URL</b></td><td><b>SHA224 hash</b></td><td><b>Log URL</b></td></tr>";

            foreach($results as $row){
                echo "<tr><td><a href=\"id.php?id=" . $row["id"] . "\">" . $row["id"] . "</a></td><td>" . $row["url"] . "</td><td>" . $row["der_hash"] . "</td><td>" . $row["log"] . "</td></tr>";
            }
            echo "</table>";
            $prepared_table = ob_get_contents();
            ob_end_clean(); //Do we really need to use Output Buffering in this whole instance? Why not just use a string in the first place?
        }
    }else{
        $prepared_table = "<b>Not found...</b>";
    }

}else{
    ob_end_flush();
}

if(isset($request)){
    echo("<input type=\"text\" id=\"query\" name=\"query\" value=\"" . $request . "\">");
}else{
    echo("<input type=\"text\" id=\"query\" name=\"query\" value=\"%.google.nl\">");
}
?>
  <br/>
  <input type="submit" value="Submit">
</form>
<?php
if(isset($prepared_table)){
    echo "<br/>---------<br/><br/>";
    echo $prepared_table;
}
?>
</body>

</html>
