<?php
$host = "sql106.infinityfree.com";
$user = "if0_42904484";
$pass = "K1@7n#U5"; 
$dbname = "if0_42904484_warnings";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>