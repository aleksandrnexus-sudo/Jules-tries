<?php
require_once 'header.php';

// Initialize variables
$department_id = $_REQUEST['department_id'] ?? null;
$report_date = $_REQUEST['report_date'] ?? date('Y-m-d');
$error_message = '';
$success_message = '';

// Fetch all departments for the selector
$departments_stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
$departments = $departments_stmt->fetchAll();

// Handle POST request to save status data
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_status'])) {
    $department_id = $_POST['department_id'];
    $report_date = $_POST['report_date'];

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
        // Use INSERT ... ON CONFLICT for PostgreSQL (equivalent to MySQL's ON DUPLICATE KEY UPDATE)
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
        $stmt->execute(array_merge(
            ['department_id' => $department_id, 'report_date' => $report_date],
            $status_values
        ));

        log_event("Administrator edited status for department ID {$department_id} for date {$report_date}");
        $success_message = "Data saved successfully.";

    } catch (PDOException $e) {
        $error_message = "Error saving data: " . $e->getMessage();
    }
}

// Fetch data for the selected department and date to show in the form
$status_data = null;
if ($department_id) {
    $stmt = $pdo->prepare("SELECT * FROM statuses WHERE department_id = :id AND report_date = :date");
    $stmt->execute(['id' => $department_id, 'date' => $report_date]);
    $status_data = $stmt->fetch();
}

// If no data exists for that day, initialize with defaults
if (!$status_data) {
    $status_data = [
        'present' => 0, 'on_duty' => 0, 'trip' => 0,
        'vacation' => 0, 'sick' => 0, 'other' => 0, 'notes' => ''
    ];
}
?>

<h3>Edit Status Data</h3>
<p>Select a department and date to load and edit the status information.</p>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

<!-- Selection Form -->
<div class="card mb-4">
    <div class="card-body">
        <form action="edit_status.php" method="get" class="form-inline">
            <div class="form-group mr-3">
                <label for="department_id" class="mr-2">Department:</label>
                <select name="department_id" id="department_id" class="form-control" required>
                    <option value="">-- Select Department --</option>
                    <?php foreach ($departments as $dep): ?>
                        <option value="<?php echo $dep['id']; ?>" <?php echo ($department_id == $dep['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dep['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mr-3">
                <label for="report_date" class="mr-2">Date:</label>
                <input type="date" id="report_date" name="report_date" class="form-control" value="<?php echo $report_date; ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">Load Data</button>
        </form>
    </div>
</div>

<!-- Edit Form (shown if a department is selected) -->
<?php if ($department_id): ?>
<div class="card">
    <div class="card-header">
        <h4>
            Editing data for "<?php echo htmlspecialchars(array_column($departments, 'name', 'id')[$department_id]); ?>"
            for date <?php echo date('d.m.Y', strtotime($report_date)); ?>
        </h4>
    </div>
    <div class="card-body">
        <form action="edit_status.php" method="post">
            <input type="hidden" name="department_id" value="<?php echo $department_id; ?>">
            <input type="hidden" name="report_date" value="<?php echo $report_date; ?>">

            <div class="form-row">
                <div class="form-group col-md-2"><label>Present</label><input type="number" class="form-control" name="present" value="<?php echo $status_data['present']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>On Duty</label><input type="number" class="form-control" name="on_duty" value="<?php echo $status_data['on_duty']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Trip</label><input type="number" class="form-control" name="trip" value="<?php echo $status_data['trip']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Vacation</label><input type="number" class="form-control" name="vacation" value="<?php echo $status_data['vacation']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Sick</label><input type="number" class="form-control" name="sick" value="<?php echo $status_data['sick']; ?>" required min="0"></div>
                <div class="form-group col-md-2"><label>Other</label><input type="number" class="form-control" name="other" value="<?php echo $status_data['other']; ?>" required min="0"></div>
            </div>
            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($status_data['notes']); ?></textarea>
            </div>
            <button type="submit" name="save_status" class="btn btn-success">Save Changes</button>
            <a href="edit_status.php" class="btn btn-secondary">Clear Selection</a>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
require_once 'footer.php';
?>
