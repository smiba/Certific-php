<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

// List information about a certificate in the database by ID

if(isset($_GET['id']) && is_numeric($_GET['id'])){
    $mysql = mysqli_connect("127.0.0.1", "", "", "certs");

    if (mysqli_connect_errno()) {
        die("Failure, error while connecting to database");
    }

    $query_str = "SELECT HIGH_PRIORITY certs.*, urls.url, HEX(ders.hash) AS der_hash, ders.validfrom, ders.validto, logs.log
                  FROM certs
                  JOIN urls ON certs.urlid = urls.id
                  JOIN ders ON certs.derid = ders.id
                  JOIN logs ON certs.logid = logs.id
                  WHERE certs.id = '" . $mysql->real_escape_string($_GET['id']) . "' LIMIT 1;";

    echo "Query: " . $query_str  . "<br/>";

    if(!$query = mysqli_query($mysql, $query_str)){
        die("<br/>Query error: " . mysqli_error($mysql));
    }

    if($query->num_rows > 0){
        $row = mysqli_fetch_array($query, MYSQLI_ASSOC);

        $daysleft = round(($row['validto'] - time()) / (60 * 60 * 24));

        $prepared_table =  "<table>";
        $prepared_table .=  "<tr><th>Internal ID:</th><td>" . $row['id'] . "</td></tr>";
        $prepared_table .= "<tr><th>URL:</th><td>" . $row['url'] . "</td></tr>";
        $prepared_table .= "<tr><th>SHA256 Hash:</th><td>" . $row['der_hash'] . "<br/><a href=\"cert_dl.php?id=" . $row['id'] . "\">(Click to download certificate)</a></td></tr>";
        $prepared_table .= "<tr><th>Valid from:</th><td>" . gmdate("c", $row['validfrom']) . "</td></tr>";
        $prepared_table .= "<tr><th>Valid to:</th><td>" . gmdate("c", $row['validto']) . "<br/>(" . $daysleft  . " days from now)</td></tr>";
        $prepared_table .= "<tr><th>Log URL:</th><td>" . $row['log'] . "</td></tr>";
        $prepared_table .= "</table>";

    }else{
        $prepared_table = "<b>No results found for this id</b>";
    }
}else{
    $prepared_table = "<b>No id given or id invalid</b>";
}

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
<br/>
<center>
<?php
echo $prepared_table;
?>
</center>
</body>
</html>
