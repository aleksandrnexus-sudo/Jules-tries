<?php
// Подключаем заголовок. В нем уже есть auth.php (проверка авторизации) и config.php (подключение к БД).
require_once 'includes/header.php';
require_once 'includes/functions.php';

$error_message = '';
$success_message = '';

// Определяем дату, за которую нужно показать данные. По умолчанию - сегодня.
$view_date = $_GET['view_date'] ?? date('Y-m-d');

// --- Логика для пользователей с ролью 'department' ---
// Этот блок кода выполняется, только если у пользователя роль 'department'.
if ($_SESSION['role'] === 'department') {
    $department_id = $_SESSION['department_id'];
    $report_date_today = date('Y-m-d'); // Пользователи могут отправлять данные только за текущий день.

    // Обработка отправки формы со статусами.
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_status'])) {
        if (empty($department_id)) {
            $error_message = "Ваша учетная запись не привязана к отделу. Вы не можете отправлять данные.";
        } else {
            // Собираем данные из формы.
            $status_values = [
                'department_id' => $department_id,
                'report_date' => $report_date_today,
                'present' => (int)$_POST['present'],
                'on_duty' => (int)$_POST['on_duty'],
                'trip' => (int)$_POST['trip'],
                'vacation' => (int)$_POST['vacation'],
                'sick' => (int)$_POST['sick'],
                'other' => (int)$_POST['other'],
                'notes' => trim($_POST['notes'])
            ];

            try {
                // Используем PostgreSQL-специфичный синтаксис INSERT ... ON CONFLICT ... DO UPDATE.
                $sql = "
                    INSERT INTO statuses (department_id, report_date, present, on_duty, trip, vacation, sick, other, notes)
                    VALUES (:department_id, :report_date, :present, :on_duty, :trip, :vacation, :sick, :other, :notes)
                    ON CONFLICT (department_id, report_date) DO UPDATE SET
                        present = EXCLUDED.present, on_duty = EXCLUDED.on_duty, trip = EXCLUDED.trip,
                        vacation = EXCLUDED.vacation, sick = EXCLUDED.sick, other = EXCLUDED.other, notes = EXCLUDED.notes
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($status_values);
                log_event("Отправлен/обновлен статус для отдела ID {$department_id} за {$report_date_today}");
                $success_message = "Данные за сегодня успешно сохранены.";
            } catch (PDOException $e) {
                $error_message = "Ошибка сохранения данных: " . $e->getMessage();
            }
        }
    }

    // Получаем текущие данные за сегодня для предзаполнения формы.
    $current_status = ['present' => 0, 'on_duty' => 0, 'trip' => 0, 'vacation' => 0, 'sick' => 0, 'other' => 0, 'notes' => ''];
    if (!empty($department_id)) {
        $stmt = $pdo->prepare("SELECT * FROM statuses WHERE department_id = :id AND report_date = :date");
        $stmt->execute(['id' => $department_id, 'date' => $report_date_today]);
        $fetched_status = $stmt->fetch();
        if ($fetched_status) {
            $current_status = $fetched_status;
        }
    }
}

// --- Логика для всех пользователей (отображение сводной таблицы) ---
$summary_data = [];
$grand_total = ['total' => 0, 'present' => 0, 'on_duty' => 0, 'trip' => 0, 'vacation' => 0, 'sick' => 0, 'other' => 0];

try {
    // Запрос для получения данных всех отделов за выбранную дату.
    // LEFT JOIN используется, чтобы показать все отделы, даже если у них нет данных за этот день.
    $sql_summary = "
        SELECT
            d.name as department_name,
            s.present, s.on_duty, s.trip, s.vacation, s.sick, s.other, s.notes
        FROM departments d
        LEFT JOIN statuses s ON d.id = s.department_id AND s.report_date = :view_date
        ORDER BY d.name;
    ";
    $stmt_summary = $pdo->prepare($sql_summary);
    $stmt_summary->execute(['view_date' => $view_date]);
    $summary_data = $stmt_summary->fetchAll();
} catch (PDOException $e) {
    $error_message = "Ошибка при загрузке сводных данных: " . $e->getMessage();
}
?>

<!-- Вывод сообщений об успехе или ошибке -->
<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

<!-- Форма ввода данных (только для пользователей отделов) -->
<?php if ($_SESSION['role'] === 'department'): ?>
<div class="card mb-4">
    <div class="card-header"><h4>Ввод данных за сегодня (<?php echo date('d.m.Y'); ?>)</h4></div>
    <div class="card-body">
        <?php if (empty($department_id)): ?>
            <div class="alert alert-warning">Ваша учетная запись не привязана к отделу. Вы не можете отправлять данные.</div>
        <?php else: ?>
        <form action="index.php" method="post">
            <div class="form-row">
                <div class="form-group col-md-2"><label>Налицо</label><input type="number" class="form-control" name="present" value="<?php echo $current_status['present']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Наряд</label><input type="number" class="form-control" name="on_duty" value="<?php echo $current_status['on_duty']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Командировка</label><input type="number" class="form-control" name="trip" value="<?php echo $current_status['trip']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Отпуск</label><input type="number" class="form-control" name="vacation" value="<?php echo $current_status['vacation']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Болен</label><input type="number" class="form-control" name="sick" value="<?php echo $current_status['sick']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Иное</label><input type="number" class="form-control" name="other" value="<?php echo $current_status['other']; ?>" required min="0"></div>
            </div>
            <div class="form-group">
                <label for="notes">Примечание</label>
                <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars($current_status['notes']); ?></textarea>
            </div>
            <button type="submit" name="submit_status" class="btn btn-primary">Сохранить данные</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Сводная таблица (для всех пользователей) -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h4>Сводка за <?php echo date('d.m.Y', strtotime($view_date)); ?></h4>
            <!-- Форма для выбора даты -->
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
            <!-- table-striped добавляет стиль "зебры" -->
            <table class="table table-bordered table-hover table-sm text-center table-striped">
                <thead class="thead-light">
                    <tr>
                        <th class="align-middle" style="width: 15%;">Отдел</th>
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
                    <?php foreach ($summary_data as $row):
                        // Присваиваем значения, используя null-coalescing оператор на случай, если данных за день нет.
                        $present = $row['present'] ?? 0; $on_duty = $row['on_duty'] ?? 0; $trip = $row['trip'] ?? 0;
                        $vacation = $row['vacation'] ?? 0; $sick = $row['sick'] ?? 0; $other = $row['other'] ?? 0;
                        $total = $present + $on_duty + $trip + $vacation + $sick + $other;

                        // Считаем общую сумму для строки "ИТОГО".
                        $grand_total['total'] += $total; $grand_total['present'] += $present; $grand_total['on_duty'] += $on_duty;
                        $grand_total['trip'] += $trip; $grand_total['vacation'] += $vacation; $grand_total['sick'] += $sick; $grand_total['other'] += $other;
                    ?>
                    <tr>
                        <td class="text-left align-middle"><?php echo htmlspecialchars($row['department_name']); ?></td>
                        <td class="align-middle"><strong><?php echo $total; ?></strong></td>
                        <td class="align-middle"><?php echo $present; ?></td>
                        <td class="align-middle"><?php echo $on_duty; ?></td>
                        <td class="align-middle"><?php echo $trip; ?></td>
                        <td class="align-middle"><?php echo $vacation; ?></td>
                        <td class="align-middle"><?php echo $sick; ?></td>
                        <td class="align-middle"><?php echo $other; ?></td>
                        <td class="text-left align-middle" style="white-space: pre-wrap;"><?php echo htmlspecialchars($row['notes'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <!-- Итоговая строка -->
                <tfoot class="bg-secondary text-white font-weight-bold">
                    <tr>
                        <td class="text-right">ИТОГО:</td>
                        <td><?php echo $grand_total['total']; ?></td>
                        <td><?php echo $grand_total['present']; ?></td>
                        <td><?php echo $grand_total['on_duty']; ?></td>
                        <td><?php echo $grand_total['trip']; ?></td>
                        <td><?php echo $grand_total['vacation']; ?></td>
                        <td><?php echo $grand_total['sick']; ?></td>
                        <td><?php echo $grand_total['other']; ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php
// Подключаем футер.
require_once 'includes/footer.php';
?>
