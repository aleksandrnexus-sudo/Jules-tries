<?php
// Инициализируем сессию
session_start();

// Проверяем, вошел ли пользователь в систему, если нет, перенаправляем на страницу входа
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Подключаем конфигурацию и общие файлы
require_once "config.php";
require_once "includes/functions.php";

$error = '';
$success = '';

// --- Логика для пользователей отделов (отправка/обновление статуса) ---
if ($_SESSION['role'] === 'department') {
    $department_id = $_SESSION['department_id'];
    $report_date = date('Y-m-d');

    // Обработка отправки формы
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_status'])) {
        // Проверяем, что ID отдела действителен
        if(empty($department_id)){
            $error = "За вашей учетной записью не закреплен отдел.";
        } else {
             // Собираем данные из формы
            $nalico = (int)$_POST['nalico'];
            $naryad = (int)$_POST['naryad'];
            $komandirovka = (int)$_POST['komandirovka'];
            $otpusk = (int)$_POST['otpusk'];
            $bolen = (int)$_POST['bolen'];
            $inoe = (int)$_POST['inoe'];

            // Используем INSERT ... ON DUPLICATE KEY UPDATE для атомарности
            $sql = "INSERT INTO statuses (department_id, report_date, nalico, naryad, komandirovka, otpusk, bolen, inoe)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    nalico = VALUES(nalico), naryad = VALUES(naryad), komandirovka = VALUES(komandirovka),
                    otpusk = VALUES(otpusk), bolen = VALUES(bolen), inoe = VALUES(inoe)";

            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "isiiiiii", $department_id, $report_date, $nalico, $naryad, $komandirovka, $otpusk, $bolen, $inoe);
                if (mysqli_stmt_execute($stmt)) {
                    $log_action = "Пользователь '" . $_SESSION['username'] . "' обновил/добавил данные за " . $report_date;
                    log_event($link, $_SESSION['id'], $log_action);
                    $success = "Данные за сегодня успешно сохранены.";
                } else {
                    $error = "Ошибка при сохранении данных: " . mysqli_error($link);
                }
                mysqli_stmt_close($stmt);
            }
        }
    }

    // Получаем текущие данные за сегодня для предзаполнения формы
    $current_status = [
        'nalico' => 0, 'naryad' => 0, 'komandirovka' => 0,
        'otpusk' => 0, 'bolen' => 0, 'inoe' => 0
    ];
    if(!empty($department_id)){
        $sql_fetch = "SELECT nalico, naryad, komandirovka, otpusk, bolen, inoe FROM statuses WHERE department_id = ? AND report_date = ?";
        if ($stmt_fetch = mysqli_prepare($link, $sql_fetch)) {
            mysqli_stmt_bind_param($stmt_fetch, "is", $department_id, $report_date);
            mysqli_stmt_execute($stmt_fetch);
            $result = mysqli_stmt_get_result($stmt_fetch);
            if ($row = mysqli_fetch_assoc($result)) {
                $current_status = $row;
            }
            mysqli_stmt_close($stmt_fetch);
        }
    }
}

// --- Логика для всех пользователей (отображение сводной таблицы) ---
$today_date_formatted = date('d.m.Y');
$grand_total = [
    'total' => 0, 'nalico' => 0, 'naryad' => 0, 'komandirovka' => 0,
    'otpusk' => 0, 'bolen' => 0, 'inoe' => 0
];
$sql_summary = "
    SELECT
        d.name AS department_name,
        s.report_date,
        s.nalico, s.naryad, s.komandirovka, s.otpusk, s.bolen, s.inoe
    FROM departments d
    LEFT JOIN (
        SELECT *, ROW_NUMBER() OVER(PARTITION BY department_id ORDER BY report_date DESC) as rn
        FROM statuses
    ) s ON d.id = s.department_id AND s.rn = 1
    ORDER BY d.name;
";
$summary_result = mysqli_query($link, $sql_summary);


// Подключаем header
require_once "includes/header.php";
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<!-- Секция для пользователей отделов -->
<?php if ($_SESSION['role'] === 'department'): ?>
<div class="card mb-4">
    <div class="card-header">
        <h4>Ввод данных за сегодня (<?php echo $today_date_formatted; ?>)</h4>
    </div>
    <div class="card-body">
         <?php if(empty($department_id)): ?>
            <div class="alert alert-warning">За вашей учетной записью не закреплен отдел. Вы не можете вводить данные.</div>
        <?php else: ?>
        <form action="index.php" method="post">
            <div class="form-row">
                <div class="form-group col-md-2">
                    <label for="nalico">Налицо</label>
                    <input type="number" class="form-control" name="nalico" value="<?php echo $current_status['nalico']; ?>" required min="0">
                </div>
                <div class="form-group col-md-2">
                    <label for="naryad">Наряд</label>
                    <input type="number" class="form-control" name="naryad" value="<?php echo $current_status['naryad']; ?>" required min="0">
                </div>
                <div class="form-group col-md-2">
                    <label for="komandirovka">Командировка</label>
                    <input type="number" class="form-control" name="komandirovka" value="<?php echo $current_status['komandirovka']; ?>" required min="0">
                </div>
                <div class="form-group col-md-2">
                    <label for="otpusk">Отпуск</label>
                    <input type="number" class="form-control" name="otpusk" value="<?php echo $current_status['otpusk']; ?>" required min="0">
                </div>
                <div class="form-group col-md-2">
                    <label for="bolen">Болен</label>
                    <input type="number" class="form-control" name="bolen" value="<?php echo $current_status['bolen']; ?>" required min="0">
                </div>
                 <div class="form-group col-md-2">
                    <label for="inoe">Иное</label>
                    <input type="number" class="form-control" name="inoe" value="<?php echo $current_status['inoe']; ?>" required min="0">
                </div>
            </div>
            <button type="submit" name="submit_status" class="btn btn-primary">Сохранить данные</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>


<!-- Сводная таблица для всех -->
<div class="card">
    <div class="card-header">
        <h4>Сводная информация по управлению (последние данные)</h4>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm text-center">
                <thead class="thead-light">
                    <tr>
                        <th class="align-middle">Подразделение</th>
                        <th class="align-middle">По списку</th>
                        <th class="align-middle">Налицо</th>
                        <th class="align-middle">Наряд</th>
                        <th class="align-middle">Командировка</th>
                        <th class="align-middle">Отпуск</th>
                        <th class="align-middle">Болен</th>
                        <th class="align-middle">Иное</th>
                        <th class="align-middle">Дата обновления</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($summary_result && mysqli_num_rows($summary_result) > 0) {
                        while ($row = mysqli_fetch_assoc($summary_result)) {
                            $total = $row['nalico'] + $row['naryad'] + $row['komandirovka'] + $row['otpusk'] + $row['bolen'] + $row['inoe'];
                            // Суммируем для итоговой строки
                            $grand_total['total'] += $total;
                            $grand_total['nalico'] += $row['nalico'];
                            $grand_total['naryad'] += $row['naryad'];
                            $grand_total['komandirovka'] += $row['komandirovka'];
                            $grand_total['otpusk'] += $row['otpusk'];
                            $grand_total['bolen'] += $row['bolen'];
                            $grand_total['inoe'] += $row['inoe'];
                            ?>
                            <tr>
                                <td class="text-left"><?php echo htmlspecialchars($row['department_name']); ?></td>
                                <td><strong><?php echo $total; ?></strong></td>
                                <td><?php echo $row['nalico'] ?? 0; ?></td>
                                <td><?php echo $row['naryad'] ?? 0; ?></td>
                                <td><?php echo $row['komandirovka'] ?? 0; ?></td>
                                <td><?php echo $row['otpusk'] ?? 0; ?></td>
                                <td><?php echo $row['bolen'] ?? 0; ?></td>
                                <td><?php echo $row['inoe'] ?? 0; ?></td>
                                <td><?php echo $row['report_date'] ? date('d.m.Y', strtotime($row['report_date'])) : 'Нет данных'; ?></td>
                            </tr>
                        <?php }
                    } else {
                        echo "<tr><td colspan='9' class='text-center'>Нет данных для отображения. Добавьте отделы в панели администратора.</td></tr>";
                    }
                    ?>
                </tbody>
                <tfoot class="bg-secondary text-white font-weight-bold">
                    <tr>
                        <td class="text-right">ИТОГО:</td>
                        <td><?php echo $grand_total['total']; ?></td>
                        <td><?php echo $grand_total['nalico']; ?></td>
                        <td><?php echo $grand_total['naryad']; ?></td>
                        <td><?php echo $grand_total['komandirovka']; ?></td>
                        <td><?php echo $grand_total['otpusk']; ?></td>
                        <td><?php echo $grand_total['bolen']; ?></td>
                        <td><?php echo $grand_total['inoe']; ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>


<?php
// Подключаем footer
require_once "includes/footer.php";
?>
