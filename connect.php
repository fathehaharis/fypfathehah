<?php
$host = '127.0.0.1';      // or 127.0.0.1
$db   = 'timelesscarrental';     // your database name
$user = 'root';           // your MySQL username
$pass = '';               // your MySQL password ("" if none)
$port = 3306;             // default MySQL port

$conn = mysqli_connect($host, $user, $pass, $db, $port);

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>