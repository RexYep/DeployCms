<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// DB config from environment
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_PORT', $_ENV['DB_PORT'] ?? 3306);
define('DB_SSL_CA', $_ENV['DB_SSL_CA'] ?? '');
define('USE_SSL', ($_ENV['USE_SSL'] ?? 'false') === 'true');

// Initialize connection
$conn = null;

if (USE_SSL && DB_SSL_CA && file_exists(DB_SSL_CA)) {
    // SSL connection
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, DB_SSL_CA, NULL, NULL);
    $conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, NULL, MYSQLI_CLIENT_SSL);
} else {
    // Local / non-SSL connection
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8mb4");

// Close function
function closeConnection() {
    global $conn;
    if ($conn) $conn->close();
}

// Optional: timezone
date_default_timezone_set('Asia/Manila');
?>