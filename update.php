<?php
// Подключаем файл конфигурации
require_once "config.php";

if(!$link){
    die("Ошибка подключения к базе данных. Проверьте config.php");
}

echo "<h1>Скрипт обновления базы данных</h1>";
echo "<p>Этот скрипт обновит структуру вашей базы данных до последней версии.</p>";

// --- 1. Добавляем столбец 'примечание' в таблицу 'statuses' ---
echo "<h2>1. Обновление таблицы `statuses`</h2>";
$sql_check_column = "SHOW COLUMNS FROM `statuses` LIKE 'примечание'";
$result = mysqli_query($link, $sql_check_column);
if (mysqli_num_rows($result) == 0) {
    $sql_add_column = "ALTER TABLE `statuses` ADD `примечание` TEXT NULL DEFAULT NULL AFTER `inoe`";
    if (mysqli_query($link, $sql_add_column)) {
        echo "<p style='color:green;'>Успешно: Столбец 'примечание' добавлен в таблицу 'statuses'.</p>";
    } else {
        echo "<p style='color:red;'>Ошибка: Не удалось добавить столбец 'примечание': " . mysqli_error($link) . "</p>";
    }
} else {
    echo "<p style='color:blue;'>Информация: Столбец 'примечание' уже существует.</p>";
}

// --- 2. Создаем таблицу 'settings' ---
echo "<h2>2. Создание таблицы `settings`</h2>";
$sql_create_settings_table = "
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(50) NOT NULL PRIMARY KEY,
    `setting_value` TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if (mysqli_query($link, $sql_create_settings_table)) {
    echo "<p style='color:green;'>Успешно: Таблица 'settings' создана или уже существует.</p>";
} else {
    echo "<p style='color:red;'>Ошибка: Не удалось создать таблицу 'settings': " . mysqli_error($link) . "</p>";
}

// --- 3. Добавляем настройки по умолчанию ---
echo "<h2>3. Добавление настроек по умолчанию</h2>";
$default_settings = [
    'app_title' => 'Учет Статуса Сотрудников',
    'app_logo' => 'assets/logo.png',
    'color_scheme' => 'default'
];

$sql_check_setting = "SELECT setting_key FROM settings WHERE setting_key = ?";
$sql_insert_setting = "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)";

$stmt_check = mysqli_prepare($link, $sql_check_setting);
$stmt_insert = mysqli_prepare($link, $sql_insert_setting);

foreach ($default_settings as $key => $value) {
    mysqli_stmt_bind_param($stmt_check, "s", $key);
    mysqli_stmt_execute($stmt_check);
    mysqli_stmt_store_result($stmt_check);

    if (mysqli_stmt_num_rows($stmt_check) == 0) {
        mysqli_stmt_bind_param($stmt_insert, "ss", $key, $value);
        if(mysqli_stmt_execute($stmt_insert)){
            echo "<p style='color:green;'>Успешно: Добавлена настройка по умолчанию '{$key}'.</p>";
        } else {
             echo "<p style='color:red;'>Ошибка: Не удалось добавить настройку '{$key}'.</p>";
        }
    } else {
        echo "<p style='color:blue;'>Информация: Настройка '{$key}' уже существует.</p>";
    }
}

mysqli_stmt_close($stmt_check);
mysqli_stmt_close($stmt_insert);


echo "<hr>";
echo "<h3>Обновление базы данных завершено.</h3>";
echo "<p style='color:red; font-weight:bold;'>Не забудьте удалить файл `update.php` с сервера из соображений безопасности.</p>";

mysqli_close($link);
?>
