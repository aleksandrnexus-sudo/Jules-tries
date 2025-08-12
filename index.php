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

// Определяем дату для отображения. По умолчанию сегодня.
$view_date = $_GET['view_date'] ?? date('Y-m-d');

// --- Логика для пользователей отделов (отправка/обновление статуса) ---
if ($_SESSION['role'] === 'department') {
    $department_id = $_SESSION['department_id'];
    // Пользователь может отправлять данные только за СЕГОДНЯ
    $report_date_today = date('Y-m-d');

    // Обработка отправки формы
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_status'])) {
        if(empty($department_id)){
            $error = "За вашей учетной записью не закреплен отдел.";
        } else {
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
                mysqli_stmt_bind_param($stmt, "isiiiiiis", $department_id, $report_date_today, $nalico, $naryad, $komandirovka, $otpusk, $bolen, $inoe, $примечание);
                if (mysqli_stmt_execute($stmt)) {
                    log_event($link, $_SESSION['id'], "Обновлены данные за " . $report_date_today);
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
        'otpusk' => 0, 'bolen' => 0, 'inoe' => 0, 'примечание' => ''
    ];
    if(!empty($department_id)){
        $sql_fetch = "SELECT nalico, naryad, komandirovka, otpusk, bolen, inoe, примечание FROM statuses WHERE department_id = ? AND report_date = ?";
        if ($stmt_fetch = mysqli_prepare($link, $sql_fetch)) {
            mysqli_stmt_bind_param($stmt_fetch, "is", $department_id, $report_date_today);
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
$grand_total = [
    'total' => 0, 'nalico' => 0, 'naryad' => 0, 'komandirovka' => 0,
    'otpusk' => 0, 'bolen' => 0, 'inoe' => 0
];
$sql_summary = "
    SELECT
        d.name AS department_name,
        s.report_date,
        s.nalico, s.naryad, s.komandirovka, s.otpusk, s.bolen, s.inoe, s.примечание
    FROM departments d
    LEFT JOIN statuses s ON d.id = s.department_id AND s.report_date = ?
    ORDER BY d.name;
";
$stmt_summary = mysqli_prepare($link, $sql_summary);
mysqli_stmt_bind_param($stmt_summary, "s", $view_date);
mysqli_stmt_execute($stmt_summary);
$summary_result = mysqli_stmt_get_result($stmt_summary);


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
        <h4>Ввод данных за сегодня (<?php echo date('d.m.Y'); ?>)</h4>
    </div>
    <div class="card-body">
         <?php if(empty($department_id)): ?>
            <div class="alert alert-warning">За вашей учетной записью не закреплен отдел. Вы не можете вводить данные.</div>
        <?php else: ?>
        <form action="index.php" method="post">
            <div class="form-row">
                <div class="form-group col-md-2"><label>Налицо</label><input type="number" class="form-control" name="nalico" value="<?php echo $current_status['nalico']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Наряд</label><input type="number" class="form-control" name="naryad" value="<?php echo $current_status['naryad']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Командировка</label><input type="number" class="form-control" name="komandirovka" value="<?php echo $current_status['komandirovka']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Отпуск</label><input type="number" class="form-control" name="otpusk" value="<?php echo $current_status['otpusk']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Болен</label><input type="number" class="form-control" name="bolen" value="<?php echo $current_status['bolen']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Иное</label><input type="number" class="form-control" name="inoe" value="<?php echo $current_status['inoe']; ?>" required min="0"></div>
            </div>
            <div class="form-group">
                <label for="примечание">Примечание</label>
                <textarea name="примечание" class="form-control" rows="2"><?php echo htmlspecialchars($current_status['примечание']); ?></textarea>
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
        <div class="d-flex justify-content-between align-items-center">
            <h4>Сводная информация по управлению за <?php echo date('d.m.Y', strtotime($view_date)); ?></h4>
            <form action="index.php" method="get" class="form-inline">
                <div class="form-group">
                    <label for="view_date" class="mr-2">Выберите дату:</label>
                    <input type="date" id="view_date" name="view_date" class="form-control" value="<?php echo $view_date; ?>">
                </div>
                <button type="submit" class="btn btn-secondary ml-2">Показать</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm text-center table-striped">
                <thead class="thead-light">
                    <tr>
                        <th class="align-middle" style="width: 15%;">Подразделение</th>
                        <th class="align-middle">По списку</th>
                        <th class="align-middle">Налицо</th>
                        <th class="align-middle">Наряд</th>
                        <th class="align-middle">Командировка</th>
                        <th class="align-middle">Отпуск</th>
                        <th class="align-middle">Болен</th>
                        <th class="align-middle">Иное</th>
                        <th class="align-middle" style="width: 25%;">Примечание</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($summary_result && mysqli_num_rows($summary_result) > 0) {
                        while ($row = mysqli_fetch_assoc($summary_result)) {
                            $nalico = $row['nalico'] ?? 0;
                            $naryad = $row['naryad'] ?? 0;
                            $komandirovka = $row['komandirovka'] ?? 0;
                            $otpusk = $row['otpusk'] ?? 0;
                            $bolen = $row['bolen'] ?? 0;
                            $inoe = $row['inoe'] ?? 0;
                            $total = $nalico + $naryad + $komandirovka + $otpusk + $bolen + $inoe;

                            $grand_total['total'] += $total;
                            $grand_total['nalico'] += $nalico;
                            $grand_total['naryad'] += $naryad;
                            $grand_total['komandirovka'] += $komandirovka;
                            $grand_total['otpusk'] += $otpusk;
                            $grand_total['bolen'] += $bolen;
                            $grand_total['inoe'] += $inoe;
                            ?>
                            <tr>
                                <td class="text-left align-middle"><?php echo htmlspecialchars($row['department_name']); ?></td>
                                <td class="align-middle"><strong><?php echo $total; ?></strong></td>
                                <td class="align-middle"><?php echo $nalico; ?></td>
                                <td class="align-middle"><?php echo $naryad; ?></td>
                                <td class="align-middle"><?php echo $komandirovka; ?></td>
                                <td class="align-middle"><?php echo $otpusk; ?></td>
                                <td class="align-middle"><?php echo $bolen; ?></td>
                                <td class="align-middle"><?php echo $inoe; ?></td>
                                <td class="text-left align-middle" style="white-space: pre-wrap;"><?php echo htmlspecialchars($row['примечание'] ?? ''); ?></td>
                            </tr>
                        <?php }
                    } else {
                        echo "<tr><td colspan='9' class='text-center'>Данные за выбранную дату отсутствуют.</td></tr>";
                    }
                    mysqli_stmt_close($stmt_summary);
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
