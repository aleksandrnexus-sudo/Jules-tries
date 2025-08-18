<?php
/**
 * Файл конфигурации базы данных.
 * Используется для подключения к PostgreSQL через PDO.
 *
 * Пожалуйста, заполните данные для подключения к вашей базе данных.
 */

// Имя хоста сервера базы данных.
define('DB_HOST', 'localhost');

// Номер порта для сервера PostgreSQL. По умолчанию 5432.
define('DB_PORT', '5432');

// Имя базы данных.
define('DB_NAME', 'staff_status');

// Имя пользователя для подключения к базе данных.
define('DB_USER', 'postgres');

// Пароль для подключения к базе данных.
define('DB_PASSWORD', 'password');


// --- Не редактировать ниже этой строки ---

// DSN (Data Source Name) - строка подключения для PDO.
$dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;

// Опции для PDO.
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Выбрасывать исключения при ошибках.
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Получать результаты в виде ассоциативных массивов.
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Использовать настоящие подготовленные выражения.
];

try {
    // Создание экземпляра PDO для подключения к БД.
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
} catch (PDOException $e) {
    // В случае сбоя подключения, выводим сообщение об ошибке.
    // В рабочей среде эту ошибку следует логировать, а не выводить на экран.
    header('Content-Type: text/plain; charset=utf-8');
    die("Ошибка подключения к базе данных. Пожалуйста, проверьте настройки в файле config.php и убедитесь, что сервер PostgreSQL доступен.\n\n" . $e->getMessage());
}
?>
