<?php
$servername = "127.0.0.1"; // Use IP instead of localhost
$username = "root";
$password = "";
$dbname = "event";
$port = 3306; // Add explicit port number

try {
    // Create connection with explicit port and socket settings
    $conn = mysqli_init();
    
    if (!$conn) {
        throw new Exception("mysqli_init failed");
    }
    
    // Set timeout options
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    
    // Connect with explicit parameters
    if (!mysqli_real_connect($conn, $servername, $username, $password, $dbname, $port)) {
        throw new Exception("Connect Error: " . mysqli_connect_error());
    }
    
    // Set charset
    $conn->set_charset("utf8mb4");
    
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}
?>