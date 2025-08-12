<?php
// Мы предполагаем, что сессия уже запущена в файле, который подключает этот header.
// Например, в index.php

// Проверяем, вошел ли пользователь в систему. Если нет, перенаправляем на страницу входа.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учет Статуса Сотрудников</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">
    <style>
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            height: 60px;
            line-height: 60px;
            background-color: #f5f5f5;
        }
        body {
            padding-bottom: 70px; /* Высота футера */
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">Учет Статуса Сотрудников</a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mr-auto">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="admin/departments.php">Панель администратора</a>
                    </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                 <li class="nav-item">
                    <span class="navbar-text mr-3">
                        <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?>
                    </span>
                </li>
                <li class="nav-item">
                    <a class="btn btn-outline-light" href="logout.php">Выход <i class="bi bi-box-arrow-right"></i></a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container">
