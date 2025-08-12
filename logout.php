<?php
// Инициализируем сессию
session_start();

// Подключаем зависимости
require_once "config.php";
require_once "includes/functions.php";

// Запись в лог перед выходом
if(isset($_SESSION["id"]) && isset($_SESSION["username"])){
    log_event($link, $_SESSION["id"], "Пользователь '" . $_SESSION["username"] . "' вышел из системы.");
}

// Уничтожаем все сессионные переменные
$_SESSION = array();

// Уничтожаем сессию
session_destroy();

// Закрываем соединение с БД
mysqli_close($link);

// Перенаправляем на страницу входа
header("location: login.php");
exit;
?>
