<?php
require_once 'header.php';

$error = '';
$success = '';

// Обработка POST-запроса
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Обновление заголовка
    if (isset($_POST['app_title'])) {
        $app_title = trim($_POST['app_title']);
        $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = 'app_title'";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $app_title);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $success .= "Заголовок приложения обновлен. ";
            log_event($link, $_SESSION['id'], "Изменен заголовок приложения на '{$app_title}'");
        }
    }

    // Обновление цветовой схемы
    if (isset($_POST['color_scheme'])) {
        $color_scheme = $_POST['color_scheme'];
        $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = 'color_scheme'";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $color_scheme);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $success .= "Цветовая схема обновлена. ";
             log_event($link, $_SESSION['id'], "Изменена цветовая схема на '{$color_scheme}'");
        }
    }

    // Загрузка логотипа
    if (isset($_FILES["app_logo"]) && $_FILES["app_logo"]["error"] == 0) {
        $target_dir = "../assets/";
        // Создаем уникальное имя файла, чтобы избежать кеширования
        $file_extension = strtolower(pathinfo($_FILES["app_logo"]["name"], PATHINFO_EXTENSION));
        $target_file = $target_dir . "logo." . $file_extension;
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'svg'];

        if (in_array($file_extension, $allowed_types)) {
            if (move_uploaded_file($_FILES["app_logo"]["tmp_name"], $target_file)) {
                $logo_path = "assets/logo." . $file_extension;
                $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = 'app_logo'";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "s", $logo_path);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                    $success .= "Логотип успешно загружен. ";
                    log_event($link, $_SESSION['id'], "Загружен новый логотип.");
                }
            } else {
                $error .= "Ошибка при загрузке файла. ";
            }
        } else {
            $error .= "Недопустимый тип файла. Разрешены только JPG, PNG, GIF, SVG. ";
        }
    }

    if(empty($success) && empty($error)){
        $error = "Не было отправлено данных для обновления.";
    }
}


// Получение текущих настроек
$settings = [];
$result = mysqli_query($link, "SELECT * FROM settings");
while ($row = mysqli_fetch_assoc($result)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<h3>Настройки приложения</h3>
<p>Здесь вы можете настроить внешний вид и основные параметры приложения.</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="row">
    <!-- Общие настройки -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Общие настройки</div>
            <div class="card-body">
                <form action="settings.php" method="post">
                    <div class="form-group">
                        <label for="app_title">Заголовок приложения</label>
                        <input type="text" name="app_title" id="app_title" class="form-control" value="<?php echo htmlspecialchars($settings['app_title'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Сохранить заголовок</button>
                </form>
                <hr>
                <form action="settings.php" method="post" enctype="multipart/form-data">
                     <div class="form-group">
                        <label for="app_logo">Логотип приложения</label>
                        <input type="file" name="app_logo" id="app_logo" class="form-control-file">
                        <small class="form-text text-muted">Загрузите файл (PNG, JPG, SVG). Текущий логотип:
                            <?php if(!empty($settings['app_logo']) && file_exists('../'.$settings['app_logo'])): ?>
                                <img src="../<?php echo $settings['app_logo']; ?>?t=<?php echo time();?>" alt="logo" style="max-height: 30px; background: #eee; padding: 2px;">
                            <?php else: echo "не установлен"; endif; ?>
                        </small>
                    </div>
                    <button type="submit" class="btn btn-primary">Загрузить логотип</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Настройки цветовой схемы -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Цветовая схема</div>
             <div class="card-body">
                <form action="settings.php" method="post">
                    <p>Выберите одну из предустановленных схем. (Настраиваемая схема - в будущих версиях).</p>
                    <?php
                    $schemes = [
                        'default' => 'По умолчанию (Темно-синий)',
                        'light' => 'Светлая (Голубой)',
                        'success' => 'Зеленая',
                        'danger' => 'Красная',
                        'warning' => 'Желтая'
                    ];
                    ?>
                    <div class="form-group">
                        <?php foreach($schemes as $key => $name): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="color_scheme" id="scheme_<?php echo $key; ?>" value="<?php echo $key; ?>" <?php echo (($settings['color_scheme'] ?? 'default') == $key) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="scheme_<?php echo $key; ?>">
                                <?php echo $name; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                     <button type="submit" class="btn btn-primary">Применить схему</button>
                </form>
            </div>
        </div>
    </div>
</div>


<?php
require_once 'footer.php';
?>
