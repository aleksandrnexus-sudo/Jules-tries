<?php
require_once 'header.php';

$error_message = '';
$success_message = '';

// Handle log clearing
if (isset($_POST['clear_logs'])) {
    $interval_clear = $_POST['interval_clear'] ?? 'all';
    $sql_clear = '';

    try {
        switch ($interval_clear) {
            case 'day':
                $sql_clear = "DELETE FROM logs WHERE log_time < NOW() - INTERVAL '1 day'";
                break;
            case 'week':
                $sql_clear = "DELETE FROM logs WHERE log_time < NOW() - INTERVAL '1 week'";
                break;
            case 'month':
                $sql_clear = "DELETE FROM logs WHERE log_time < NOW() - INTERVAL '1 month'";
                break;
            case 'all':
                $sql_clear = "TRUNCATE TABLE logs"; // TRUNCATE is faster and resets sequence in PostgreSQL
                break;
        }

        if ($sql_clear) {
            $pdo->exec($sql_clear);
            log_event("Cleared logs for interval: {$interval_clear}");
            $success_message = "Logs cleared successfully.";
        }
    } catch (PDOException $e) {
        $error_message = "Error clearing logs: " . $e->getMessage();
    }
}

// Handle log filtering for display
$interval = $_GET['interval'] ?? 'all';
$where_clause = '';
$params = [];

switch ($interval) {
    case 'day':
        $where_clause = "WHERE log_time >= NOW() - INTERVAL '1 day'";
        break;
    case 'week':
        $where_clause = "WHERE log_time >= NOW() - INTERVAL '1 week'";
        break;
    case 'month':
        $where_clause = "WHERE log_time >= NOW() - INTERVAL '1 month'";
        break;
}

$sql_select = "SELECT id, TO_CHAR(log_time, 'YYYY-MM-DD HH24:MI:SS') as formatted_time, username, action
               FROM logs
               $where_clause
               ORDER BY log_time DESC";

$stmt = $pdo->prepare($sql_select);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>

<h3>System Event Logs</h3>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

<!-- Filter Form -->
<div class="card mb-4">
    <div class="card-body">
        <form action="logs.php" method="get" class="form-inline">
            <div class="form-group mr-3">
                <label for="interval" class="mr-2">Show logs for:</label>
                <select name="interval" id="interval" class="form-control">
                    <option value="all" <?php echo ($interval == 'all') ? 'selected' : ''; ?>>All time</option>
                    <option value="day" <?php echo ($interval == 'day') ? 'selected' : ''; ?>>Last day</option>
                    <option value="week" <?php echo ($interval == 'week') ? 'selected' : ''; ?>>Last week</option>
                    <option value="month" <?php echo ($interval == 'month') ? 'selected' : ''; ?>>Last month</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Apply</button>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Log Entries</span>
        <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#clearLogsModal">
            <i class="bi bi-trash"></i> Clear Logs
        </button>
    </div>
    <div class="card-body">
        <table class="table table-bordered table-hover table-sm">
            <thead class="thead-light">
                <tr>
                    <th style="width: 20%;">Timestamp</th>
                    <th style="width: 15%;">User</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($logs) > 0): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo $log['formatted_time']; ?></td>
                            <td><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></td>
                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" class="text-center">No logs found for the selected period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Clear Logs Modal -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form action="logs.php" method="post">
        <div class="modal-header">
          <h5 class="modal-title">Confirm Log Clearing</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <p>Are you sure you want to clear logs? This action is irreversible.</p>
          <div class="form-group">
                <label for="interval_clear">Clear logs older than:</label>
                <select name="interval_clear" id="interval_clear" class="form-control">
                    <option value="all">All Logs</option>
                    <option value="day">1 Day</option>
                    <option value="week">1 Week</option>
                    <option value="month">1 Month</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="submit" name="clear_logs" class="btn btn-danger">Clear Logs</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
require_once dirname(__DIR__) . '/includes/functions.php';
require_once 'footer.php';
?>
