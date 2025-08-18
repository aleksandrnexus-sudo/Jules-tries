<?php
// Подключаем общий заголовок для админ-панели.
require_once 'header.php';

// Инициализация переменных.
// Данные могут приходить как через GET (при выборе), так и через POST (при сохранении).
$department_id = $_REQUEST['department_id'] ?? null;
$report_date = $_REQUEST['report_date'] ?? date('Y-m-d');
$error_message = '';
$success_message = '';

// Получаем список всех отделов для выпадающего списка в форме выбора.
$departments_stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
$departments = $departments_stmt->fetchAll();

// Обработка POST-запроса для сохранения данных о статусе.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_status'])) {
    $department_id = $_POST['department_id'];
    $report_date = $_POST['report_date'];

    // Собираем все значения из формы в ассоциативный массив.
    $status_values = [
        'present' => (int)$_POST['present'],
        'on_duty' => (int)$_POST['on_duty'],
        'trip' => (int)$_POST['trip'],
        'vacation' => (int)$_POST['vacation'],
        'sick' => (int)$_POST['sick'],
        'other' => (int)$_POST['other'],
        'notes' => trim($_POST['notes'])
    ];

    try {
        // Используем конструкцию INSERT ... ON CONFLICT ... DO UPDATE для PostgreSQL.
        // Это аналог ON DUPLICATE KEY UPDATE в MySQL.
        // Если запись для этой даты и отдела уже существует, она обновится. Если нет - создастся.
        $sql = "
            INSERT INTO statuses (department_id, report_date, present, on_duty, trip, vacation, sick, other, notes)
            VALUES (:department_id, :report_date, :present, :on_duty, :trip, :vacation, :sick, :other, :notes)
            ON CONFLICT (department_id, report_date)
            DO UPDATE SET
                present = EXCLUDED.present,
                on_duty = EXCLUDED.on_duty,
                trip = EXCLUDED.trip,
                vacation = EXCLUDED.vacation,
                sick = EXCLUDED.sick,
                other = EXCLUDED.other,
                notes = EXCLUDED.notes
        ";

        $stmt = $pdo->prepare($sql);
        // Передаем в execute массив, объединяя ID, дату и значения статусов.
        $stmt->execute(array_merge(
            ['department_id' => $department_id, 'report_date' => $report_date],
            $status_values
        ));

        log_event("Администратор '{$_SESSION['username']}' отредактировал статус для отдела ID {$department_id} за дату {$report_date}");
        $success_message = "Данные успешно сохранены.";

    } catch (PDOException $e) {
        $error_message = "Ошибка сохранения данных: " . $e->getMessage();
    }
}

// Загрузка данных для отображения в форме, если выбран отдел и дата.
$status_data = null;
if ($department_id) {
    $stmt = $pdo->prepare("SELECT * FROM statuses WHERE department_id = :id AND report_date = :date");
    $stmt->execute(['id' => $department_id, 'date' => $report_date]);
    $status_data = $stmt->fetch();
}

// Если для выбранного дня данных нет, инициализируем массив с нулевыми значениями.
if (!$status_data) {
    $status_data = [
        'present' => 0, 'on_duty' => 0, 'trip' => 0,
        'vacation' => 0, 'sick' => 0, 'other' => 0, 'notes' => ''
    ];
}
?>

<h3>Редактирование данных о статусе</h3>
<p>Выберите отдел и дату для загрузки и редактирования данных.</p>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

<!-- Форма для выбора отдела и даты -->
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

<!-- Форма для редактирования данных (отображается после выбора отдела) -->
<?php if ($department_id): ?>
<div class="card">
    <div class="card-header">
        <h4>
            Редактирование для отдела "<?php echo htmlspecialchars(array_column($departments, 'name', 'id')[$department_id]); ?>"
            за <?php echo date('d.m.Y', strtotime($report_date)); ?>
        </h4>
    </div>
    <div class="card-body">
        <form action="edit_status.php" method="post">
            <input type="hidden" name="department_id" value="<?php echo $department_id; ?>">
            <input type="hidden" name="report_date" value="<?php echo $report_date; ?>">

            <div class="form-row">
                <div class="form-group col-md-2"><label>Налицо</label><input type="number" class="form-control" name="present" value="<?php echo $status_data['present']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Наряд</label><input type="number" class="form-control" name="on_duty" value="<?php echo $status_data['on_duty']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Командировка</label><input type="number" class="form-control" name="trip" value="<?php echo $status_data['trip']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Отпуск</label><input type="number" class="form-control" name="vacation" value="<?php echo $status_data['vacation']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Болен</label><input type="number" class="form-control" name="sick" value="<?php echo $status_data['sick']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Иное</label><input type="number" class="form-control" name="other" value="<?php echo $status_data['other']; ?>" required min="0"></div>
            </div>
            <div class="form-group">
                <label for="notes">Примечание</label>
                <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($status_data['notes']); ?></textarea>
            </div>
            <button type="submit" name="save_status" class="btn btn-success">Сохранить изменения</button>
            <a href="edit_status.php" class="btn btn-secondary">Сбросить выбор</a>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
require_once 'footer.php';
?>
