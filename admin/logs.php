<?php
require_once 'header.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$error_message = '';
$success_message = '';

// --- Обработка POST-запроса на очистку логов ---
if (isset($_POST['clear_logs'])) {
    $interval_clear = $_POST['interval_clear'] ?? 'all';
    $sql_clear = '';

    try {
        // В зависимости от выбора пользователя, формируем SQL-запрос.
        // Синтаксис INTERVAL для PostgreSQL отличается от MySQL.
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
                // TRUNCATE TABLE работает быстрее для полной очистки и сбрасывает счетчик (SERIAL).
                $sql_clear = "TRUNCATE TABLE logs";
                break;
        }

        if ($sql_clear) {
            $pdo->exec($sql_clear);
            log_event("Очищены логи за интервал: {$interval_clear}");
            $success_message = "Логи успешно очищены.";
        }
    } catch (PDOException $e) {
        $error_message = "Ошибка при очистке логов: " . $e->getMessage();
    }
}

// --- Фильтрация логов для отображения ---
$interval = $_GET['interval'] ?? 'all';
$where_clause = '';

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

// TO_CHAR используется для форматирования даты и времени в PostgreSQL.
$sql_select = "SELECT id, TO_CHAR(log_time, 'YYYY-MM-DD HH24:MI:SS') as formatted_time, username, action
               FROM logs
               {$where_clause}
               ORDER BY log_time DESC";

$stmt = $pdo->prepare($sql_select);
$stmt->execute();
$logs = $stmt->fetchAll();
?>

<h3>Системные логи</h3>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

<!-- Форма для фильтрации логов по времени -->
<div class="card mb-4">
    <div class="card-body">
        <form action="logs.php" method="get" class="form-inline">
            <div class="form-group mr-3">
                <label for="interval" class="mr-2">Показать логи за:</label>
                <select name="interval" id="interval" class="form-control">
                    <option value="all" <?php echo ($interval == 'all') ? 'selected' : ''; ?>>Все время</option>
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
        <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#clearLogsModal">
            <i class="bi bi-trash"></i> Очистить логи
        </button>
    </div>
    <div class="card-body">
        <table class="table table-bordered table-hover table-sm">
            <thead class="thead-light">
                <tr>
                    <th style="width: 20%;">Время</th>
                    <th style="width: 15%;">Пользователь</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($logs) > 0): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo $log['formatted_time']; ?></td>
                            <td><?php echo htmlspecialchars($log['username'] ?? 'Система'); ?></td>
                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" class="text-center">Записи за выбранный период отсутствуют.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Модальное окно для подтверждения очистки логов -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form action="logs.php" method="post">
        <div class="modal-header">
          <h5 class="modal-title">Подтверждение очистки логов</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <p>Вы уверены, что хотите очистить логи? Это действие необратимо.</p>
          <div class="form-group">
                <label for="interval_clear">Очистить логи старше чем:</label>
                <select name="interval_clear" id="interval_clear" class="form-control">
                    <option value="all">Все логи</option>
                    <option value="day">1 день</option>
                    <option value="week">1 неделя</option>
                    <option value="month">1 месяц</option>
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
