<?php
//(c) github.com/smiba
// LICENSED UNDER AGPL 3.0

//Use: php dump_single_cert.php <CT Log> <CT Entry ID>
//Example: php dump_single_cert.php ct.googleapis.com/logs/us1/argon2024 100

//Dump a single certificate to stdout as PEM

// Checks

// Make sure we have the arguments we need, 2 or less results in an error. 
if($argc <= 2){
    die("Failure, missing parameters.\r\nSyntax: \"" . $argv[0] . " \e[4mLOG\e[0m \e[4mID\e[0m\"\r\n");
}

// Reject non-numeric IDs (invalid)
if(!is_numeric($argv[2])){
    die("ID Invalid (Not numeric)\r\n");
}

// Reject Log names containing the protocol (http(s))
if(strpos($argv[1], 'http') !== false){
    die("Invalid log format, only use base url without http(s)\r\n");
}

// Functions

function output_certificate($leaf_input, $extra_data, $padding = true, $split = true){
    //Original credits to https://github.com/mk-j/PHP_CT_Reader
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
    }


    return $cert_pem;
}

// Main

// Format URL and do http call to CT Log
$result = file_get_contents("https://" . $argv[1] . "/ct/v1/get-entries?start=" . $argv[2] . "&end=" . $argv[2]);
if($result === false){
    die("Failure while contacting log: " . $argv[1] . "\r\n");
}

$result = json_decode($result, true, 8, JSON_THROW_ON_ERROR);

// We should not be receiving more then one entry, as we only requested one. Reject these results.
if(count($result['entries']) != "1"){
	die("Unexpected amount of results received, \"" . count($result['entries']) . "\" results\r\n");
}

// Output the result we just got
foreach($result['entries'] as $certificate){
    die("\r\n" . output_certificate($certificate['leaf_input'], $certificate['extra_data']) . "\r\n");
}