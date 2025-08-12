<?php
// Инициализируем сессию
session_start();

// Проверяем, вошел ли пользователь в систему и является ли он администратором
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin'){
    // Если нет, перенаправляем на страницу входа
    header("location: ../login.php");
    exit;
}

// Подключаем файлы конфигурации и функций
// Путь к файлам указывается относительно текущего файла (header.php)
require_once dirname(__DIR__) . "/config.php";
require_once dirname(__DIR__) . "/includes/functions.php";

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="../index.php">Учет Статуса Сотрудников</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mr-auto">
                <li class="nav-item">
                    <a class="nav-link" href="../index.php">Главная</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="departments.php">Управление отделами</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users.php">Управление пользователями</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="logs.php">Просмотр логов</a>
                </li>
            </ul>
            <ul class="navbar-nav">
                 <li class="nav-item">
                    <span class="navbar-text mr-3">
                        Пользователь: <?php echo htmlspecialchars($_SESSION["username"]); ?>
                    </span>
                </li>
                <li class="nav-item">
                    <a class="btn btn-outline-light" href="../logout.php">Выход</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
