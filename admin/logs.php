<?php
require_once 'header.php';

// --- Логика очистки логов ---
if (isset($_POST['clear_logs'])) {
    $interval_clear = $_POST['interval_clear'] ?? 'all';
    $where_clause_clear = '';

    switch ($interval_clear) {
        case 'day':
            $where_clause_clear = "WHERE `timestamp` < NOW() - INTERVAL 1 DAY";
            break;
        case 'week':
            $where_clause_clear = "WHERE `timestamp` < NOW() - INTERVAL 1 WEEK";
            break;
        case 'month':
            $where_clause_clear = "WHERE `timestamp` < NOW() - INTERVAL 1 MONTH";
            break;
        case 'all':
            $where_clause_clear = ""; // TRUNCATE будет быстрее, но DELETE с WHERE более гибкий
            break;
    }

    if ($where_clause_clear !== '') {
        $sql_clear = "DELETE FROM `logs` " . $where_clause_clear;
    } else {
        // Для 'all' используем TRUNCATE для сброса автоинкремента
        $sql_clear = "TRUNCATE TABLE `logs`";
    }

    if (mysqli_query($link, $sql_clear)) {
        log_event($link, $_SESSION['id'], "Очищены логи за интервал: {$interval_clear}");
        $success = "Логи успешно очищены.";
    } else {
        $error = "Ошибка при очистке логов: " . mysqli_error($link);
    }
}


// --- Логика фильтрации и отображения логов ---
$interval = $_GET['interval'] ?? 'all'; // 'all' по умолчанию
$where_clause = '';

switch ($interval) {
    case 'day':
        $where_clause = "WHERE l.timestamp >= NOW() - INTERVAL 1 DAY";
        break;
    case 'week':
        $where_clause = "WHERE l.timestamp >= NOW() - INTERVAL 1 WEEK";
        break;
    case 'month':
        $where_clause = "WHERE l.timestamp >= NOW() - INTERVAL 1 MONTH";
        break;
    case 'all':
    default:
        $where_clause = "";
        break;
}

$sql = "SELECT l.id, l.timestamp, l.action, u.username
        FROM logs l
        LEFT JOIN users u ON l.user_id = u.id
        $where_clause
        ORDER BY l.timestamp DESC";

$logs_result = mysqli_query($link, $sql);

?>

<h3>Просмотр системных логов</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<!-- Форма фильтрации -->
<div class="card mb-4">
    <div class="card-body">
        <form action="logs.php" method="get" class="form-inline">
            <div class="form-group mr-3">
                <label for="interval" class="mr-2">Показать за:</label>
                <select name="interval" id="interval" class="form-control">
                    <option value="all" <?php echo ($interval == 'all') ? 'selected' : ''; ?>>Всё время</option>
                    <option value="day" <?php echo ($interval == 'day') ? 'selected' : ''; ?>>Последний день</option>
                    <option value="week" <?php echo ($interval == 'week') ? 'selected' : ''; ?>>Последнюю неделю</option>
                    <option value="month" <?php echo ($interval == 'month') ? 'selected' : ''; ?>>Последний месяц</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Применить</button>
        </form>
    </div>
</div>

<!-- Таблица с логами -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Записи логов</span>
        <!-- Кнопка для модального окна очистки -->
        <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#clearLogsModal">
            Очистить логи
        </button>
    </div>
    <div class="card-body">
        <table class="table table-bordered table-hover table-sm">
            <thead class="thead-light">
                <tr>
                    <th style="width: 5%;">ID</th>
                    <th style="width: 20%;">Время</th>
                    <th style="width: 15%;">Пользователь</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($logs_result && mysqli_num_rows($logs_result) > 0) {
                    while ($row = mysqli_fetch_assoc($logs_result)) { ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo $row['timestamp']; ?></td>
                            <td><?php echo htmlspecialchars($row['username'] ?? 'Система'); ?></td>
                            <td><?php echo htmlspecialchars($row['action']); ?></td>
                        </tr>
                    <?php }
                } else {
                    echo "<tr><td colspan='4' class='text-center'>Логи отсутствуют.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Модальное окно для очистки логов -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" role="dialog" aria-labelledby="clearLogsModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form action="logs.php" method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="clearLogsModalLabel">Очистка логов</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <p>Вы действительно хотите очистить логи? Это действие необратимо.</p>
          <div class="form-group">
                <label for="interval_clear">Очистить логи за:</label>
                <select name="interval_clear" id="interval_clear" class="form-control">
                    <option value="all">Всё время</option>
                    <option value="day">Старше 1 дня</option>
                    <option value="week">Старше 1 недели</option>
                    <option value="month">Старше 1 месяца</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Отмена</button>
          <button type="submit" name="clear_logs" class="btn btn-danger">Очистить</button>
        </div>
      </form>
    </div>
  </div>
</div>


<?php
require_once 'footer.php';
?>
