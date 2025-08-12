<?php
require_once 'header.php';

// Инициализация переменных
$department_id = $_REQUEST['department_id'] ?? null;
$report_date = $_REQUEST['report_date'] ?? date('Y-m-d');
$error = '';
$success = '';

// Получение списка всех отделов для селектора
$departments_result = mysqli_query($link, "SELECT id, name FROM departments ORDER BY name");
$departments = mysqli_fetch_all($departments_result, MYSQLI_ASSOC);

// Обработка сохранения данных
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_status'])) {
    $department_id = $_POST['department_id'];
    $report_date = $_POST['report_date'];

    $nalico = (int)$_POST['nalico'];
    $naryad = (int)$_POST['naryad'];
    $komandirovka = (int)$_POST['komandirovka'];
    $otpusk = (int)$_POST['otpusk'];
    $bolen = (int)$_POST['bolen'];
    $inoe = (int)$_POST['inoe'];
    $примечание = trim($_POST['примечание']);

    $sql = "INSERT INTO statuses (department_id, report_date, nalico, naryad, komandirovka, otpusk, bolen, inoe, примечание)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            nalico = VALUES(nalico), naryad = VALUES(naryad), komandirovka = VALUES(komandirovka),
            otpusk = VALUES(otpusk), bolen = VALUES(bolen), inoe = VALUES(inoe), примечание = VALUES(примечание)";

    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "isiiiiiis", $department_id, $report_date, $nalico, $naryad, $komandirovka, $otpusk, $bolen, $inoe, $примечание);
        if (mysqli_stmt_execute($stmt)) {
            log_event($link, $_SESSION['id'], "Администратор отредактировал данные для отдела ID {$department_id} за {$report_date}");
            $success = "Данные успешно сохранены.";
        } else {
            $error = "Ошибка при сохранении данных: " . mysqli_error($link);
        }
        mysqli_stmt_close($stmt);
    }
}

// Загрузка данных для редактирования, если выбран отдел
$status_data = null;
if ($department_id) {
    $sql_fetch = "SELECT * FROM statuses WHERE department_id = ? AND report_date = ?";
    if ($stmt_fetch = mysqli_prepare($link, $sql_fetch)) {
        mysqli_stmt_bind_param($stmt_fetch, "is", $department_id, $report_date);
        mysqli_stmt_execute($stmt_fetch);
        $result = mysqli_stmt_get_result($stmt_fetch);
        $status_data = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt_fetch);
    }
}

// Если данных нет, инициализируем пустыми значениями
if (!$status_data) {
    $status_data = [
        'nalico' => 0, 'naryad' => 0, 'komandirovka' => 0,
        'otpusk' => 0, 'bolen' => 0, 'inoe' => 0, 'примечание' => ''
    ];
}
?>

<h3>Редактирование данных о статусе</h3>
<p>Выберите отдел и дату для загрузки и редактирования данных.</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<!-- Форма выбора -->
<div class="card mb-4">
    <div class="card-body">
        <form action="edit_status.php" method="get" class="form-inline">
            <div class="form-group mr-3">
                <label for="department_id" class="mr-2">Отдел:</label>
                <select name="department_id" id="department_id" class="form-control" required>
                    <option value="">-- Выберите отдел --</option>
                    <?php foreach ($departments as $dep): ?>
                        <option value="<?php echo $dep['id']; ?>" <?php echo ($department_id == $dep['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dep['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-3">
                <label for="report_date" class="mr-2">Дата:</label>
                <input type="date" id="report_date" name="report_date" class="form-control" value="<?php echo $report_date; ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">Загрузить данные</button>
        </form>
    </div>
</div>

<!-- Форма редактирования (показывается, если выбран отдел) -->
<?php if ($department_id): ?>
<div class="card">
    <div class="card-header">
        <h4>
            Редактирование данных для отдела "<?php echo htmlspecialchars(array_column($departments, 'name', 'id')[$department_id]); ?>"
            за <?php echo date('d.m.Y', strtotime($report_date)); ?>
        </h4>
    </div>
    <div class="card-body">
        <form action="edit_status.php" method="post">
            <input type="hidden" name="department_id" value="<?php echo $department_id; ?>">
            <input type="hidden" name="report_date" value="<?php echo $report_date; ?>">

            <div class="form-row">
                <div class="form-group col-md-2"><label>Налицо</label><input type="number" class="form-control" name="nalico" value="<?php echo $status_data['nalico']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Наряд</label><input type="number" class="form-control" name="naryad" value="<?php echo $status_data['naryad']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Командировка</label><input type="number" class="form-control" name="komandirovka" value="<?php echo $status_data['komandirovka']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Отпуск</label><input type="number" class="form-control" name="otpusk" value="<?php echo $status_data['otpusk']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Болен</label><input type="number" class="form-control" name="bolen" value="<?php echo $status_data['bolen']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Иное</label><input type="number" class="form-control" name="inoe" value="<?php echo $status_data['inoe']; ?>" required min="0"></div>
            </div>
            <div class="form-group">
                <label for="примечание">Примечание</label>
                <textarea name="примечание" class="form-control" rows="3"><?php echo htmlspecialchars($status_data['примечание']); ?></textarea>
            </div>
            <button type="submit" name="save_status" class="btn btn-success">Сохранить изменения</button>
            <a href="edit_status.php" class="btn btn-secondary">Сбросить</a>
        </form>
    </div>
</div>
<?php endif; ?>


<?php
require_once 'footer.php';
?>
