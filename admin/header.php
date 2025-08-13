<?php
// Centralized authentication and authorization check
require_once dirname(__DIR__) . '/includes/auth.php';

// This is an admin-only area. Check for the 'admin' role.
if ($_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    die('403 Forbidden: You do not have permission to access this page.');
}

// Load application settings from the database
// The $pdo object is available from config.php, which is included by auth.php
$app_settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $stmt->fetch()) {
        $app_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // If settings can't be loaded, use sane defaults
    error_log("Could not load settings from database: " . $e->getMessage());
}
$app_title = $app_settings['app_title'] ?? 'Staff Status Tracker';
$app_logo = $app_settings['app_logo'] ?? '';
$color_scheme = $app_settings['color_scheme'] ?? 'default';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора - <?php echo htmlspecialchars($app_title); ?></title>
    <link rel="stylesheet" href="../css/bootstrap.min.css">
    <link rel="stylesheet" href="../css/bootstrap-icons.css">
    <?php
    if ($color_scheme === 'custom') {
        $custom_colors = json_decode($app_settings['custom_colors'] ?? '{}', true);
        $navbar_bg = $custom_colors['navbar_bg'] ?? '#343a40';
        $navbar_link_color = $custom_colors['navbar_link_color'] ?? '#ffffff';
        $btn_primary_bg = $custom_colors['btn_primary_bg'] ?? '#007bff';

        echo "<style>
            .navbar.bg-dark { background-color: {$navbar_bg} !important; }
            .navbar.bg-dark .nav-link, .navbar.bg-dark .navbar-brand, .navbar.bg-dark .navbar-text, .navbar.bg-dark .btn-outline-light { color: {$navbar_link_color} !important; }
            .navbar.bg-dark .btn-outline-light { border-color: {$navbar_link_color}; }
            .btn-primary { background-color: {$btn_primary_bg}; border-color: {$btn_primary_bg}; }
        </style>";

    } else {
        $scheme_css_path = "../css/schemes/{$color_scheme}.css";
        if (file_exists($scheme_css_path)) {
            echo '<link rel="stylesheet" href="' . $scheme_css_path . '?v=' . filemtime($scheme_css_path) . '">';
        }
    }
    ?>
    <style>
        .admin-nav .nav-item:not(:last-child) { border-right: 1px solid #555; }
        .admin-nav .nav-link { padding-left: 1rem; padding-right: 1rem; }
        .navbar-brand img { max-height: 30px; margin-right: 10px; vertical-align: middle; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php">
            <?php if (!empty($app_logo) && file_exists(dirname(__DIR__) . '/' . $app_logo)): ?>
                <img src="../<?php echo $app_logo; ?>?t=<?php echo filemtime(dirname(__DIR__) . '/' . $app_logo);?>" alt="logo">
            <?php endif; ?>
            <?php echo htmlspecialchars($app_title); ?>
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav admin-nav mr-auto">
                <li class="nav-item"><a class="nav-link" href="../index.php"><i class="bi bi-house-door"></i> Главная</a></li>
                <li class="nav-item"><a class="nav-link" href="departments.php"><i class="bi bi-building"></i> Отделы</a></li>
                <li class="nav-item"><a class="nav-link" href="permissions.php"><i class="bi bi-people"></i> Права доступа</a></li>
                <li class="nav-item"><a class="nav-link" href="edit_status.php"><i class="bi bi-pencil-square"></i> Редактор статусов</a></li>
                <li class="nav-item"><a class="nav-link" href="logs.php"><i class="bi bi-journal-text"></i> Логи</a></li>
                <li class="nav-item"><a class="nav-link" href="settings.php"><i class="bi bi-gear"></i> Настройки</a></li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item"><span class="navbar-text mr-3"><i class="bi bi-clock"></i> <span id="clock"></span></span></li>
                <li class="nav-item"><span class="navbar-text mr-3"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?></span></li>
                <!-- Кнопка выхода удалена, т.к. аутентификация управляется сервером -->
            </ul>
        </div>
    </div>
</nav>

<div class="container">
<script>
// Clock script
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const year = now.getFullYear();
    const clockElement = document.getElementById('clock');
    if (clockElement) {
        clockElement.textContent = `${h}:${m}:${s} ${day}.${month}.${year}`;
    }
}
// Run the clock once on load, then every second
if (document.getElementById('clock')) {
    updateClock();
    setInterval(updateClock, 1000);
}
</script>
