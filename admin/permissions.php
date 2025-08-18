<?php
// Подключаем общий заголовок и аутентификацию.
require_once 'header.php';

// Список администраторов по умолчанию, которых нельзя изменять или удалять.
$default_admins = ['as-biserov', 'as-karpov'];

// Инициализация переменных для формы.
$user_id = 0;
$username = '';
$role = 'department';
$department_id = null;
$update_mode = false; // Флаг для переключения между режимами "Добавить" и "Редактировать".
$error_message = '';
$success_message = '';

// Получаем список всех отделов для выпадающего списка в форме.
$departments_stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
$departments = $departments_stmt->fetchAll();

// --- Обработка POST-запросов (сохранение или обновление прав) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = strtolower(trim($_POST['username']));
    $role = $_POST['role'];
    // Если роль - 'admin', department_id должен быть NULL.
    $department_id = ($role === 'department' && !empty($_POST['department_id'])) ? $_POST['department_id'] : null;

    if (empty($username) || empty($role)) {
        $error_message = "Имя пользователя и роль обязательны для заполнения.";
    } else {
        // --- Логика обновления прав ---
        if (isset($_POST['update'])) {
            $user_id = $_POST['id'];
            // Получаем оригинальное имя пользователя, чтобы проверить, не является ли он админом по умолчанию.
            $original_user_stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
            $original_user_stmt->execute(['id' => $user_id]);
            $original_username = $original_user_stmt->fetchColumn();

            // Запрещаем менять роль администратора по умолчанию.
            if (in_array($original_username, $default_admins) && $role !== 'admin') {
                $error_message = "Нельзя изменить роль администратора по умолчанию.";
            } else {
                try {
                    $sql = "UPDATE users SET username = :username, role = :role, department_id = :department_id WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        'username' => $username,
                        'role' => $role,
                        'department_id' => $department_id,
                        'id' => $user_id
                    ]);
                    log_event("Обновлены права для пользователя '{$username}' (ID: {$user_id})");
                    $success_message = "Права пользователя успешно обновлены.";
                } catch (PDOException $e) {
                    $error_message = "Ошибка обновления пользователя. Возможно, такое имя уже используется.";
                }
            }
        // --- Логика предоставления новых прав ---
        } elseif (isset($_POST['save'])) {
            try {
                $sql = "INSERT INTO users (username, role, department_id) VALUES (:username, :role, :department_id)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'username' => $username,
                    'role' => $role,
                    'department_id' => $department_id,
                ]);
                $new_id = $pdo->lastInsertId();
                log_event("Предоставлены права новому пользователю '{$username}' (ID: {$new_id})");
                $success_message = "Права для пользователя успешно предоставлены.";
            } catch (PDOException $e) {
                $error_message = "Ошибка предоставления прав. Возможно, такой пользователь уже добавлен.";
            }
        }
    }
}

// --- Обработка GET-запросов (редактирование и удаление) ---
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Загрузка данных пользователя в форму для редактирования.
    if (isset($_GET['edit'])) {
        $user_id = $_GET['edit'];
        $update_mode = true;
        $stmt = $pdo->prepare("SELECT username, role, department_id FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $user = $stmt->fetch();
        if ($user) {
            $username = $user['username'];
            $role = $user['role'];
            $department_id = $user['department_id'];
        }
    }
    // Удаление прав пользователя.
    if (isset($_GET['delete'])) {
        $user_id = $_GET['delete'];
        // Проверяем, не является ли пользователь админом по умолчанию.
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $user_to_delete = $stmt->fetchColumn();

        if (in_array($user_to_delete, $default_admins)) {
            $error_message = "Администраторов по умолчанию удалять нельзя.";
        } else {
            try {
                $sql = "DELETE FROM users WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $user_id]);
                log_event("Удалены права для пользователя '{$user_to_delete}' (ID: {$user_id})");
                $success_message = "Права пользователя успешно отозваны.";
            } catch (PDOException $e) {
                $error_message = "Ошибка при отзыве прав.";
            }
        }
    }
}
?>

<div class="row">
    <!-- Форма для добавления/редактирования прав -->
    <div class="col-md-4">
        <h3><?php echo $update_mode ? 'Редактировать права' : 'Предоставить права'; ?></h3>
        <form action="permissions.php" method="post" class="card p-3 bg-light">
            <input type="hidden" name="id" value="<?php echo $user_id; ?>">
            <?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
            <?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

            <div class="form-group">
                <label for="username">Имя пользователя (логин из Kerberos, без @...)</label>
                <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>" required>
            </div>
            <div class="form-group">
                <label for="role">Роль</label>
                <select name="role" id="role" class="form-control" required>
                    <option value="department" <?php if($role === 'department') echo 'selected'; ?>>Пользователь отдела</option>
                    <option value="admin" <?php if($role === 'admin') echo 'selected'; ?>>Администратор</option>
                </select>
            </div>
            <div class="form-group" id="department-select-group" style="<?php echo ($role !== 'department') ? 'display: none;' : ''; ?>">
                <label for="department_id">Отдел</label>
                <select name="department_id" id="department_id" class="form-control">
                    <option value="">-- Выберите отдел --</option>
                    <?php foreach ($departments as $dep): ?>
                        <option value="<?php echo $dep['id']; ?>" <?php echo ($department_id == $dep['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dep['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <?php if ($update_mode): ?>
                    <button type="submit" class="btn btn-primary" name="update">Обновить</button>
                    <a href="permissions.php" class="btn btn-secondary">Отмена</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-success" name="save">Сохранить</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <!-- Таблица с текущими правами пользователей -->
    <div class="col-md-8">
        <h3>Список прав пользователей</h3>
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>Имя пользователя</th>
                    <th>Роль</th>
                    <th>Отдел</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT u.id, u.username, u.role, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id ORDER BY u.username");
                while ($row = $stmt->fetch()) { ?>
                    <tr>
                        <td><i class="bi bi-person"></i> <?php echo htmlspecialchars($row['username']); ?></td>
                        <td><?php echo $row['role'] === 'admin' ? '<i class="bi bi-shield-lock"></i> Администратор' : '<i class="bi bi-person-workspace"></i> Пользователь отдела'; ?></td>
                        <td><?php echo htmlspecialchars($row['department_name'] ?? 'N/A'); ?></td>
                        <td>
                            <a href="permissions.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="Редактировать"><i class="bi bi-pencil"></i></a>
                            <?php if (!in_array($row['username'], $default_admins)): ?>
                                <a href="permissions.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Вы уверены?');" title="Удалить"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Скрипт для скрытия/показа выпадающего списка отделов в зависимости от выбранной роли.
document.getElementById('role').addEventListener('change', function() {
    const departmentSelect = document.getElementById('department-select-group');
    if (this.value === 'department') {
        departmentSelect.style.display = 'block';
    } else {
        departmentSelect.style.display = 'none';
    }
});
</script>

<?php
require_once 'footer.php';
?>
