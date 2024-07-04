<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

// Page for displaying some really basic performance values

$mysql = mysqli_connect("127.0.0.1", "", "", "certs");

if (mysqli_connect_errno()) {
        die("Failure, error while connecting to database");
}

$minute = time() - 60 + 1;
$hour = time() - (60 * 60) + 1;
//$days3 = time() - (60 * 60 * 24 * 3) + 1;

$now = time();

$query = "SELECT SUM(der_inserted) AS der_ins, SUM(url) AS url, SUM(url_inserted) AS url_ins, SUM(cert) AS cert FROM performance WHERE `timestamp` > '" . $hour . "' AND `timestamp` < '" . $now . "';";
echo("\r\n" . $query . "<br>\r\n");
$result = mysqli_query($mysql, $query);

if($result->num_rows != 0){
    echo("<br>Performance in the last hour:<br>");
    $row = mysqli_fetch_array($result);

    if($row['der_ins'] != "" && $row['cert'] != ""){
        $calculated = round((100 - (($row['der_ins'] / $row['cert']) * 100)), 5);
        echo("<b>DERs (inserted/processed)</b>: " . $row['der_ins'] . "/" . $row['cert'] . " --> Savings: <b>" . $calculated . "%</b><br>");
    }else{
        echo("<b>DER insertions</b>: N/A.<br>");
    }

    if($row['url'] != "" && $row['url_ins'] != ""){
        $calculated = round((100 - (($row['url_ins'] / $row['url']) * 100)), 5);
        echo("<b>URLs (inserted/processed)</b>: " . $row['url_ins'] . "/" . $row['url'] . " --> Savings: <b>" . $calculated . "%</b><br>");
    }else{
        echo("<b>URLs (inserted/processed)</b>: N/A.<br>");
    }


}else{
        die("Failure while getting data from database\r\n");
}

?>
