<?php
// Файл конфигурации базы данных

// ** Настройки MySQL ** //
/** Имя базы данных для WordPress */
define('DB_NAME', 'staff_status');

/** Имя пользователя MySQL */
define('DB_USER', 'root');

/** Пароль к базе данных MySQL */
define('DB_PASSWORD', 'password');

/** Имя хоста MySQL */
define('DB_HOST', 'localhost');

/** Кодировка базы данных для создания таблиц. */
define('DB_CHARSET', 'utf8mb4');

/* Попытка подключения к базе данных MySQL */
$link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Проверка подключения
if($link === false){
    // Если база данных не существует, попробуем ее создать
    $conn_temp = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD);
    if($conn_temp){
        $sql_create_db = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci";
        if(mysqli_query($conn_temp, $sql_create_db)){
            echo "База данных " . DB_NAME . " успешно создана. Пожалуйста, обновите страницу.<br>";
            mysqli_close($conn_temp);
            // Повторное подключение после создания БД
            $link = mysqli_connect(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
            if($link === false){
                 die("ОШИБКА: Не удалось подключиться к созданной базе данных. " . mysqli_connect_error());
            }
        } else {
            die("ОШИБКА: Не удалось создать базу данных. " . mysqli_error($conn_temp));
        }
    } else {
        die("ОШИБКА: Не удалось подключиться к MySQL. " . mysqli_connect_error());
    }
}
?>
