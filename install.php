<?php
// Подключаем файл конфигурации
require_once "config.php";

echo "Начинаем установку...<br>";

// Убедимся, что соединение установлено
if ($link) {
    // SQL для создания таблиц
    $sql = "
    CREATE TABLE IF NOT EXISTS `departments` (
        `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
        `name` VARCHAR(255) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `users` (
        `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `department_id` INT,
        `role` ENUM('admin', 'department') NOT NULL DEFAULT 'department',
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `statuses` (
        `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
        `department_id` INT NOT NULL,
        `report_date` DATE NOT NULL,
        `nalico` INT NOT NULL DEFAULT 0,
        `naryad` INT NOT NULL DEFAULT 0,
        `komandirovka` INT NOT NULL DEFAULT 0,
        `otpusk` INT NOT NULL DEFAULT 0,
        `bolen` INT NOT NULL DEFAULT 0,
        `inoe` INT NOT NULL DEFAULT 0,
        `примечание` TEXT,
        UNIQUE KEY `department_date` (`department_id`, `report_date`),
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `logs` (
        `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
        `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `user_id` INT,
        `action` VARCHAR(255) NOT NULL,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `settings` (
        `setting_key` VARCHAR(50) NOT NULL PRIMARY KEY,
        `setting_value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    // Выполняем multi_query
    if (mysqli_multi_query($link, $sql)) {
        // Очищаем результаты для каждого запроса
        while (mysqli_next_result($link)) {
            if ($result = mysqli_store_result($link)) {
                mysqli_free_result($result);
            }
        }
        echo "Таблицы успешно созданы.<br>";
    } else {
        echo "Ошибка при создании таблиц: " . mysqli_error($link) . "<br>";
    }

    // Добавление настроек по умолчанию
    $sql_settings = "
    INSERT INTO settings (setting_key, setting_value) VALUES
    ('app_title', 'Учет Статуса Сотрудников'),
    ('app_logo', 'assets/logo.png'),
    ('color_scheme', 'default')
    ON DUPLICATE KEY UPDATE setting_key=setting_key;
    ";
    if(mysqli_query($link, $sql_settings)){
        echo "Настройки по умолчанию успешно добавлены.<br>";
    } else {
        echo "Ошибка при добавлении настроек по умолчанию: " . mysqli_error($link) . "<br>";
    }


    // Создание пользователя admin, если он еще не существует
    $admin_user = 'Admin';
    $admin_pass = 'password'; // В реальном проекте используйте более надежный пароль
    $hashed_password = password_hash($admin_pass, PASSWORD_DEFAULT);
    $admin_role = 'admin';

    $check_admin_sql = "SELECT id FROM users WHERE username = ?";
    if($stmt = mysqli_prepare($link, $check_admin_sql)){
        mysqli_stmt_bind_param($stmt, "s", $admin_user);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        if(mysqli_stmt_num_rows($stmt) == 0){
            $insert_admin_sql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
            if($stmt_insert = mysqli_prepare($link, $insert_admin_sql)){
                mysqli_stmt_bind_param($stmt_insert, "sss", $admin_user, $hashed_password, $admin_role);
                if(mysqli_stmt_execute($stmt_insert)){
                    echo "Пользователь 'Admin' успешно создан с паролем 'password'.<br>";
                } else{
                    echo "Ошибка при создании пользователя 'Admin'.<br>";
                }
                mysqli_stmt_close($stmt_insert);
            }
        } else {
            echo "Пользователь 'Admin' уже существует.<br>";
        }
        mysqli_stmt_close($stmt);
    }

    echo "Установка завершена. <strong>Удалите файл install.php из соображений безопасности.</strong>";

    // Закрываем соединение
    mysqli_close($link);

} else {
    echo "Не удалось установить соединение с базой данных. Проверьте ваш config.php";
}
?>
