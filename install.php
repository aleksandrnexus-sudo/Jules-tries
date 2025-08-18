<?php
// Устанавливаем заголовок, чтобы браузер правильно отображал кириллицу.
header('Content-Type: text/plain; charset=utf-8');

// Подключаем файл конфигурации для доступа к $pdo.
require_once 'config.php';

try {
    echo "Запуск установки базы данных для PostgreSQL...\n\n";

    // Единый блок SQL-команд для создания всех таблиц.
    // Используется IF NOT EXISTS для безопасности, чтобы не удалить существующие таблицы.
    $sql = "
        -- Таблица для хранения отделов
        CREATE TABLE IF NOT EXISTS departments (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) UNIQUE NOT NULL
        );

        -- Таблица для хранения пользователей и их прав
        -- Пароли не хранятся, так как используется внешняя аутентификация (Kerberos)
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            role VARCHAR(50) NOT NULL CHECK (role IN ('admin', 'department')),
            department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL
        );

        -- Таблица для хранения ежедневных статусов по отделам
        CREATE TABLE IF NOT EXISTS statuses (
            id SERIAL PRIMARY KEY,
            department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
            report_date DATE NOT NULL,
            present INTEGER NOT NULL DEFAULT 0,
            on_duty INTEGER NOT NULL DEFAULT 0,
            trip INTEGER NOT NULL DEFAULT 0,
            vacation INTEGER NOT NULL DEFAULT 0,
            sick INTEGER NOT NULL DEFAULT 0,
            other INTEGER NOT NULL DEFAULT 0,
            notes TEXT,
            UNIQUE (department_id, report_date) -- Гарантирует только одну запись на отдел в день
        );

        -- Таблица для логирования действий пользователей
        CREATE TABLE IF NOT EXISTS logs (
            id SERIAL PRIMARY KEY,
            log_time TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            username VARCHAR(100),
            action TEXT NOT NULL
        );

        -- Таблица для хранения настроек приложения
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT
        );
    ";

    // Выполняем создание таблиц
    $pdo->exec($sql);
    echo "УСПЕШНО: Все таблицы были созданы или уже существуют.\n";

    // --- Вставка данных по умолчанию ---

    // Добавляем администраторов по умолчанию
    echo "\nОбработка администраторов по умолчанию...\n";
    $admins = ['as-biserov', 'as-karpov'];
    // ON CONFLICT DO NOTHING предотвращает ошибку, если пользователь уже существует.
    $stmt_admins = $pdo->prepare("INSERT INTO users (username, role) VALUES (:username, 'admin') ON CONFLICT (username) DO NOTHING");

    foreach ($admins as $admin) {
        $stmt_admins->execute(['username' => $admin]);
        if ($stmt_admins->rowCount() > 0) {
            echo "  - Администратор '{$admin}' создан.\n";
        } else {
            echo "  - Администратор '{$admin}' уже существует.\n";
        }
    }

    // Добавляем настройки по умолчанию
    echo "\nОбработка настроек по умолчанию...\n";
    $settings = [
        'app_title' => 'Учет Статуса Сотрудников',
        'app_logo' => '',
        'color_scheme' => 'default',
        'custom_colors' => '{}'
    ];
    $stmt_settings = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON CONFLICT (setting_key) DO NOTHING");

    foreach ($settings as $key => $value) {
        $stmt_settings->execute(['key' => $key, 'value' => $value]);
         if ($stmt_settings->rowCount() > 0) {
            echo "  - Настройка '{$key}' создана.\n";
        } else {
            echo "  - Настройка '{$key}' уже существует.\n";
        }
    }

    echo "\n--------------------------------------------------\n";
    echo "УСПЕШНО: Установка базы данных завершена.\n";
    echo "ВАЖНО: Пожалуйста, удалите этот файл (install.php) с вашего сервера в целях безопасности.\n";

} catch (PDOException $e) {
    die("ОШИБКА УСТАНОВКИ БАЗЫ ДАННЫХ: " . $e->getMessage());
}
?>
