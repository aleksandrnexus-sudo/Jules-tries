<?php
// Мы предполагаем, что сессия уже запущена в файле, который подключает этот header.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Загружаем настройки приложения
// Мы не можем переподключить config.php, если он уже был подключен.
// Но нам нужен $link для запроса. Убедимся, что он доступен.
if(!isset($link) || !$link){
    require_once "config.php";
}

$app_settings = [];
$result = mysqli_query($link, "SELECT * FROM settings");
if($result){
    while ($row = mysqli_fetch_assoc($result)) {
        $app_settings[$row['setting_key']] = $row['setting_value'];
    }
}
$app_title = $app_settings['app_title'] ?? 'Учет Статуса Сотрудников';
$app_logo = $app_settings['app_logo'] ?? '';
$color_scheme = $app_settings['color_scheme'] ?? 'default';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($app_title); ?></title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-icons.css">
    <?php
    // Подключаем файл CSS для цветовой схемы
    if ($color_scheme === 'custom') {
        $custom_colors = json_decode($app_settings['custom_colors'] ?? '{}', true);
        $navbar_bg = $custom_colors['navbar_bg'] ?? '#003366';
        $navbar_link_color = $custom_colors['navbar_link_color'] ?? '#ffffff';
        $btn_primary_bg = $custom_colors['btn_primary_bg'] ?? '#004080';

        echo "<style>
            .navbar.bg-primary { background-color: {$navbar_bg} !important; }
            .navbar.bg-primary .nav-link, .navbar.bg-primary .navbar-brand, .navbar.bg-primary .navbar-text, .navbar.bg-primary .btn-outline-light { color: {$navbar_link_color} !important; border-color: {$navbar_link_color} !important; }
            .btn-primary { background-color: {$btn_primary_bg}; border-color: {$btn_primary_bg}; }
        </style>";

    } else {
        $scheme_css_path = "css/schemes/{$color_scheme}.css";
        if (file_exists($scheme_css_path)) {
            echo '<link rel="stylesheet" href="' . $scheme_css_path . '?v=' . time() . '">';
        }
    }
    ?>
    <style>
        .footer {
            position: fixed; bottom: 0; width: 100%; height: 60px; line-height: 60px; background-color: #f5f5f5;
        }
        body { padding-bottom: 70px; }
        .navbar-brand img { max-height: 30px; margin-right: 10px; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <?php if (!empty($app_logo) && file_exists($app_logo)): ?>
                <img src="<?php echo $app_logo; ?>?t=<?php echo time();?>" alt="logo">
            <?php endif; ?>
            <?php echo htmlspecialchars($app_title); ?>
        </a>
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
