<?php
/*
 * Database configuration for PostgreSQL using PDO.
 *
 * Please fill in your database connection details below.
 */

// Hostname for the database server.
define('DB_HOST', 'localhost');

// Port number for the PostgreSQL server. Default is 5432.
define('DB_PORT', '5432');

// The name of the database.
define('DB_NAME', 'staff_status');

// The username for the database connection.
define('DB_USER', 'postgres');

// The password for the database connection.
define('DB_PASSWORD', 'password');


// --- Do not edit below this line ---

// DSN (Data Source Name) for PDO
$dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;

// PDO options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Fetch results as associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real prepared statements
];

try {
    // Create a PDO instance
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
} catch (PDOException $e) {
    // If connection fails, display a generic error message.
    // In a production environment, you should log this error instead of displaying it.
    header('Content-Type: text/plain; charset=utf-8');
    die("Ошибка подключения к базе данных. Пожалуйста, проверьте настройки в файле config.php и убедитесь, что сервер PostgreSQL доступен.\n\n" . $e->getMessage());
}
?>
