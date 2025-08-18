<?php
// Подключаем общий заголовок для админ-панели.
require_once 'header.php';

// Инициализация переменных для формы и сообщений
$department_name = "";
$department_id = 0;
$update_mode = false; // Флаг, определяющий, находится ли форма в режиме редактирования или добавления
$error_message = '';
$success_message = '';

// Обработка POST-запросов (создание и обновление отдела)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $department_name = trim($_POST['name']);

    // Проверка, что имя отдела не пустое
    if (empty($department_name)) {
        $error_message = "Название отдела не может быть пустым.";
    } else {
        // Логика обновления существующего отдела
        if (isset($_POST['update'])) {
            $department_id = $_POST['id'];
            try {
                $sql = "UPDATE departments SET name = :name WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['name' => $department_name, 'id' => $department_id]);
                log_event("Обновлен отдел ID: {$department_id} с новым названием '{$department_name}'");
                $success_message = "Отдел успешно обновлен.";
            } catch (PDOException $e) {
                // Обработка ошибки, если отдел с таким именем уже существует (из-за UNIQUE constraint)
                $error_message = "Ошибка обновления отдела. Возможно, отдел с таким названием уже существует.";
            }
        // Логика создания нового отдела
        } elseif (isset($_POST['save'])) {
            try {
                $sql = "INSERT INTO departments (name) VALUES (:name)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['name' => $department_name]);
                $new_id = $pdo->lastInsertId();
                log_event("Создан новый отдел '{$department_name}' (ID: {$new_id})");
                $success_message = "Отдел успешно создан.";
                $department_name = ""; // Очищаем поле ввода после успешного добавления
            } catch (PDOException $e) {
                $error_message = "Ошибка создания отдела. Возможно, отдел с таким названием уже существует.";
            }
        }
    }
}

// Обработка GET-запросов (для редактирования и удаления)
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Подготовка формы для редактирования
    if (isset($_GET['edit'])) {
        $department_id = $_GET['edit'];
        $update_mode = true;
        $sql = "SELECT name FROM departments WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $department_id]);
        $department = $stmt->fetch();
        if ($department) {
            $department_name = $department['name'];
        }
    }
    // Обработка удаления отдела
    if (isset($_GET['delete'])) {
        $department_id = $_GET['delete'];

        try {
            // Проверяем, привязаны ли к отделу какие-либо пользователи
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id = :id");
            $stmt_check->execute(['id' => $department_id]);
            $user_count = $stmt_check->fetchColumn();

            // Если пользователи есть, удаление запрещено
            if ($user_count > 0) {
                $error_message = "Невозможно удалить отдел: за ним закреплены пользователи. Сначала переназначьте их.";
            } else {
                // Если пользователей нет, удаляем отдел
                $sql = "DELETE FROM departments WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $department_id]);
                log_event("Удален отдел ID: {$department_id}");
                $success_message = "Отдел успешно удален.";
            }
        } catch (PDOException $e) {
            $error_message = "Ошибка при удалении отдела: " . $e->getMessage();
        }
    }
}
?>
<!-- HTML-разметка страницы -->
<div class="row">
    <!-- Форма для добавления/редактирования -->
    <div class="col-md-4">
        <h3><?php echo $update_mode ? 'Редактировать отдел' : 'Добавить новый отдел'; ?></h3>
        <form action="departments.php" method="post" class="card p-3 bg-light">
            <input type="hidden" name="id" value="<?php echo $department_id; ?>">

            <!-- Вывод сообщений об успехе или ошибке -->
            <?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
            <?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

            <div class="form-group">
                <label for="name">Название отдела</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($department_name); ?>" required>
            </div>
            <div class="form-group">
                <?php if ($update_mode): ?>
                    <button type="submit" class="btn btn-primary" name="update">Обновить</button>
                    <a href="departments.php" class="btn btn-secondary">Отмена</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-success" name="save">Сохранить</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Таблица с существующими отделами -->
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
                // Запрос на получение всех отделов для отображения в таблице
                $stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
                while ($row = $stmt->fetch()) { ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td>
                            <a href="departments.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="Редактировать"><i class="bi bi-pencil"></i></a>
                            <a href="departments.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Вы уверены, что хотите удалить этот отдел?');" title="Удалить"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Подключаем общий футер для админ-панели.
require_once 'footer.php';
?>
