<?php
require_once 'header.php';

// Инициализация переменных для формы
$department_name = "";
$department_id = 0;
$update = false;
$error = '';
$success = '';

// Обработка POST-запросов (создание и обновление)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Обновление отдела
    if (isset($_POST['update'])) {
        $department_id = $_POST['id'];
        $department_name = trim($_POST['name']);

        if (!empty($department_name)) {
            $sql = "UPDATE departments SET name = ? WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "si", $department_name, $department_id);
                if (mysqli_stmt_execute($stmt)) {
                    log_event($link, $_SESSION['id'], "Обновлен отдел ID: {$department_id} с новым названием '{$department_name}'");
                    $success = "Отдел успешно обновлен.";
                } else {
                    $error = "Ошибка при обновлении отдела.";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $error = "Название отдела не может быть пустым.";
        }
    // Создание нового отдела
    } elseif (isset($_POST['save'])) {
        $department_name = trim($_POST['name']);

        if (!empty($department_name)) {
            $sql = "INSERT INTO departments (name) VALUES (?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "s", $department_name);
                if (mysqli_stmt_execute($stmt)) {
                    $new_id = mysqli_insert_id($link);
                    log_event($link, $_SESSION['id'], "Создан новый отдел '{$department_name}' (ID: {$new_id})");
                    $success = "Отдел успешно создан.";
                    $department_name = ""; // Очистить поле после добавления
                } else {
                    $error = "Ошибка: такой отдел уже существует или произошла другая ошибка.";
                }
                mysqli_stmt_close($stmt);
            }
        } else {
            $error = "Название отдела не может быть пустым.";
        }
    }
}

// Обработка GET-запросов (редактирование и удаление)
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Загрузка данных для редактирования
    if (isset($_GET['edit'])) {
        $department_id = $_GET['edit'];
        $update = true;
        $sql = "SELECT name FROM departments WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $department_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $name);
            mysqli_stmt_fetch($stmt);
            $department_name = $name;
            mysqli_stmt_close($stmt);
        }
    }
    // Удаление отдела
    if (isset($_GET['delete'])) {
        $department_id = $_GET['delete'];
        // Проверка, есть ли у отдела пользователи
        $check_sql = "SELECT COUNT(*) FROM users WHERE department_id = ?";
        if($stmt_check = mysqli_prepare($link, $check_sql)) {
            mysqli_stmt_bind_param($stmt_check, "i", $department_id);
            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_bind_result($stmt_check, $user_count);
            mysqli_stmt_fetch($stmt_check);
            mysqli_stmt_close($stmt_check);

            if($user_count > 0) {
                $error = "Невозможно удалить отдел, так как за ним закреплены пользователи. Сначала измените их отдел.";
            } else {
                 $sql = "DELETE FROM departments WHERE id = ?";
                if ($stmt = mysqli_prepare($link, $sql)) {
                    mysqli_stmt_bind_param($stmt, "i", $department_id);
                    if (mysqli_stmt_execute($stmt)) {
                        log_event($link, $_SESSION['id'], "Удален отдел ID: {$department_id}");
                        $success = "Отдел успешно удален.";
                    } else {
                        $error = "Ошибка при удалении отдела.";
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}
?>

<div class="row">
    <div class="col-md-4">
        <h3><?php echo $update ? 'Редактировать отдел' : 'Добавить новый отдел'; ?></h3>
        <form action="departments.php" method="post">
            <input type="hidden" name="id" value="<?php echo $department_id; ?>">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            <div class="form-group">
                <label>Название отдела</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($department_name); ?>" required>
            </div>
            <div class="form-group">
                <?php if ($update == true): ?>
                    <button type="submit" class="btn btn-primary" name="update">Обновить</button>
                    <a href="departments.php" class="btn btn-secondary">Отмена</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-success" name="save">Сохранить</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="col-md-8">
        <h3>Список отделов</h3>
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $result = mysqli_query($link, "SELECT * FROM departments");
                if(mysqli_num_rows($result) > 0){
                    while ($row = mysqli_fetch_assoc($result)) { ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td>
                                <a href="departments.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="Редактировать"><i class="bi bi-pencil"></i></a>
                                <a href="departments.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Вы уверены, что хотите удалить этот отдел?');" title="Удалить"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                    <?php }
                } else {
                    echo "<tr><td colspan='3'>Отделы не найдены.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once 'footer.php';
?>
