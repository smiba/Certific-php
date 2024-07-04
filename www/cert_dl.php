<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

// Download a certificate from the database by ID (as DER)

if(isset($_GET['id']) && is_numeric($_GET['id'])){
    $mysql = mysqli_connect("127.0.0.1", "", "", "certs");

    if (mysqli_connect_errno()) {
        die("Failure, error while connecting to database");
    }

    $query_str = "SELECT ders.der, ders.id
                  FROM certs
                  JOIN ders ON certs.derid = ders.id
                  WHERE certs.id = '" . $mysql->real_escape_string($_GET['id']) . "' LIMIT 1;";

    if(!$query = mysqli_query($mysql, $query_str)){
        die("Query error: " . mysqli_error($mysql));
    }

    if($query->num_rows > 0){
        $row = mysqli_fetch_array($query, MYSQLI_ASSOC);

        header('Content-type: application/x-x509-cert');
        header('Content-Disposition: attachment; filename="' . $row['id']  . '.der"');

        die($row['der']); //End our output here
    }else{
        die("No results for this id");
    }
}else{
    die("Invalid id or none given");
}
?>