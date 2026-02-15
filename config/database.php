<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Load .env only if exists (local testing)
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv->load();
}

// DB config
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'mydb');
define('DB_PORT', $_ENV['DB_PORT'] ?? 3306);
define('DB_SSL_CA', $_ENV['DB_SSL_CA'] ?? '');
define('USE_SSL', ($_ENV['USE_SSL'] ?? 'false') === 'true');

// Initialize connection
$conn = null;

if (USE_SSL && DB_SSL_CA && file_exists(DB_SSL_CA)) {
    $conn = mysqli_init();
    $conn->ssl_set(NULL, NULL, DB_SSL_CA, NULL, NULL);
    $conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, NULL, MYSQLI_CLIENT_SSL);
} else {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Charset
$conn->set_charset("utf8mb4");

// Close function
function closeConnection() {
    global $conn;
    if ($conn) $conn->close();
}

// Timezone
date_default_timezone_set('Asia/Manila');
?>