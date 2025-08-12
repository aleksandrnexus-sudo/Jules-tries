<?php
require_once 'header.php';

// Инициализация переменных
$user_id = 0;
$username = '';
$department_id = '';
$role = 'department';
$update = false;
$error = '';
$success = '';

// Получение списка отделов для выпадающего списка
$departments_result = mysqli_query($link, "SELECT id, name FROM departments ORDER BY name");
$departments = mysqli_fetch_all($departments_result, MYSQLI_ASSOC);

// Обработка POST запросов
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Обновление пользователя
    if (isset($_POST['update'])) {
        $user_id = $_POST['id'];
        $username = trim($_POST['username']);
        $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
        $role = $_POST['role'];
        $password = $_POST['password'];

        if (!empty($username) && !empty($role)) {
            $sql = "UPDATE users SET username = ?, department_id = ?, role = ?" . (!empty($password) ? ", password = ?" : "") . " WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                if(!empty($password)){
                    mysqli_stmt_bind_param($stmt, "sisssi", $username, $department_id, $role, $hashed_password, $user_id);
                } else {
                    mysqli_stmt_bind_param($stmt, "sissi", $username, $department_id, $role, $user_id);
                }

                if (mysqli_stmt_execute($stmt)) {
                    log_event($link, $_SESSION['id'], "Обновлен пользователь '{$username}' (ID: {$user_id})");
                    $success = "Пользователь успешно обновлен.";
                } else {
                    $error = "Ошибка при обновлении. Возможно, имя пользователя уже занято.";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $error = "Имя пользователя и роль обязательны для заполнения.";
        }
    // Создание нового пользователя
    } elseif (isset($_POST['save'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
        $role = $_POST['role'];

        if (!empty($username) && !empty($password) && !empty($role)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (username, password, department_id, role) VALUES (?, ?, ?, ?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "ssis", $username, $hashed_password, $department_id, $role);
                if (mysqli_stmt_execute($stmt)) {
                    $new_id = mysqli_insert_id($link);
                    log_event($link, $_SESSION['id'], "Создан новый пользователь '{$username}' (ID: {$new_id})");
                    $success = "Пользователь успешно создан.";
                } else {
                    $error = "Ошибка при создании. Возможно, имя пользователя уже занято.";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $error = "Имя пользователя, пароль и роль обязательны для заполнения.";
        }
    }
}

// Обработка GET запросов
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Загрузка данных для редактирования
    if (isset($_GET['edit'])) {
        $user_id = $_GET['edit'];
        $update = true;
        $sql = "SELECT username, department_id, role FROM users WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $u_name, $d_id, $u_role);
            mysqli_stmt_fetch($stmt);
            $username = $u_name;
            $department_id = $d_id;
            $role = $u_role;
            mysqli_stmt_close($stmt);
        }
    }
    // Удаление пользователя
    if (isset($_GET['delete'])) {
        $user_id = $_GET['delete'];

        // Не позволяем удалять самого себя
        if($user_id == $_SESSION['id']) {
            $error = "Вы не можете удалить свою собственную учетную запись.";
        } else {
            $sql = "DELETE FROM users WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                if (mysqli_stmt_execute($stmt)) {
                    log_event($link, $_SESSION['id'], "Удален пользователь ID: {$user_id}");
                    $success = "Пользователь успешно удален.";
                } else {
                    $error = "Ошибка при удалении пользователя.";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>

<div class="row">
    <div class="col-md-4">
        <h3><?php echo $update ? 'Редактировать пользователя' : 'Добавить пользователя'; ?></h3>
        <form action="users.php" method="post">
            <input type="hidden" name="id" value="<?php echo $user_id; ?>">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            <div class="form-group">
                <label>Имя пользователя</label>
                <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>" required>
            </div>
            <div class="form-group">
                <label>Пароль <?php if($update) echo "(оставьте пустым, чтобы не менять)"; ?></label>
                <input type="password" name="password" class="form-control" <?php if(!$update) echo 'required'; ?>>
            </div>
             <div class="form-group">
                <label>Отдел</label>
                <select name="department_id" class="form-control">
                    <option value="">-- Не привязан к отделу --</option>
                    <?php foreach ($departments as $dep): ?>
                        <option value="<?php echo $dep['id']; ?>" <?php echo ($department_id == $dep['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dep['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Роль</label>
                <select name="role" class="form-control" required>
                    <option value="department" <?php echo ($role == 'department') ? 'selected' : ''; ?>>Пользователь отдела</option>
                    <option value="admin" <?php echo ($role == 'admin') ? 'selected' : ''; ?>>Администратор</option>
                </select>
            </div>
            <div class="form-group">
                <?php if ($update): ?>
                    <button type="submit" class="btn btn-primary" name="update">Обновить</button>
                    <a href="users.php" class="btn btn-secondary">Отмена</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-success" name="save">Сохранить</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="col-md-8">
        <h3>Список пользователей</h3>
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>ID</th>
                    <th>Имя</th>
                    <th>Отдел</th>
                    <th>Роль</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sql = "SELECT u.id, u.username, u.role, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id ORDER BY u.username";
                $result = mysqli_query($link, $sql);
                if(mysqli_num_rows($result) > 0){
                    while ($row = mysqli_fetch_assoc($result)) { ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['username']); ?></td>
                            <td><?php echo htmlspecialchars($row['department_name'] ?? 'N/A'); ?></td>
                            <td><?php echo $row['role'] == 'admin' ? 'Администратор' : 'Пользователь'; ?></td>
                            <td>
                                <a href="users.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="Редактировать"><i class="bi bi-pencil"></i></a>
                                <?php if ($_SESSION['id'] != $row['id']): // Не даем удалить самого себя ?>
                                <a href="users.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Вы уверены?');" title="Удалить"><i class="bi bi-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php }
                } else {
                    echo "<tr><td colspan='5'>Пользователи не найдены.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once 'footer.php';
?>
