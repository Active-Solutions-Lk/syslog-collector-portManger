<?php
// Database connection to remote server
$host = '192.168.0.53';
$database = 'remote_admin';
$username = 'Radmin';
$password = 'Radmin@39';

$remote_db = new mysqli($host, $username, $password, $database);
if ($remote_db->connect_error) {
    die("Connection failed: {$remote_db->connect_error}");
}
?>