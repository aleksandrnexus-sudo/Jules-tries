<?php
// Make sure this script is not run on a production server
if (getenv('APP_ENV') === 'production') {
    die("This script cannot be run in a production environment.");
}

header('Content-Type: text/plain; charset=utf-8');

require_once 'config.php';

try {
    echo "Starting database installation for PostgreSQL...\n\n";

    // SQL statements
    $sql = "
        CREATE TABLE IF NOT EXISTS departments (
            id SERIAL PRIMARY KEY,
            name VARCHAR(255) UNIQUE NOT NULL
        );

        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            role VARCHAR(50) NOT NULL CHECK (role IN ('admin', 'department')),
            department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL
        );

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
            UNIQUE (department_id, report_date)
        );

        CREATE TABLE IF NOT EXISTS logs (
            id SERIAL PRIMARY KEY,
            log_time TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            username VARCHAR(100),
            action TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT
        );
    ";

    // Execute the table creation
    $pdo->exec($sql);
    echo "SUCCESS: All tables created successfully or already exist.\n";

    // --- Insert Default Data ---

    // Add default admins
    echo "\nProcessing default administrators...\n";
    $admins = ['as-biserov', 'as-karpov'];
    $stmt_admins = $pdo->prepare("INSERT INTO users (username, role) VALUES (:username, 'admin') ON CONFLICT (username) DO NOTHING");

    foreach ($admins as $admin) {
        $stmt_admins->execute(['username' => $admin]);
        if ($stmt_admins->rowCount() > 0) {
            echo "  - Admin '{$admin}' created.\n";
        } else {
            echo "  - Admin '{$admin}' already exists.\n";
        }
    }

    // Add default settings
    echo "\nProcessing default settings...\n";
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
            echo "  - Setting '{$key}' created.\n";
        } else {
            echo "  - Setting '{$key}' already exists.\n";
        }
    }

    echo "\n--------------------------------------------------\n";
    echo "SUCCESS: Database installation complete.\n";
    echo "IMPORTANT: Please delete this file (install.php) from your server for security reasons.\n";

} catch (PDOException $e) {
    die("DATABASE INSTALLATION FAILED: " . $e->getMessage());
}
?>
