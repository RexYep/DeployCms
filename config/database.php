<?php

// config/database.php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();


// Database Configuration Constants
define('DB_HOST', $_ENV['DB_HOST']);   
define('DB_USER', $_ENV['DB_USER']);           
define('DB_PASS', $_ENV['DB_PASS']);             
define('DB_NAME', $_ENV['DB_NAME']); 

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4 for proper character support
$conn->set_charset("utf8mb4");

// Function to close database connection
function closeConnection() {
    global $conn;
    if ($conn) {
        $conn->close();
    }
}

// Optional: Set timezone
date_default_timezone_set('Asia/Manila'); // Change to your timezone

?>