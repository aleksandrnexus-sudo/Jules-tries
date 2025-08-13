<?php
require_once 'header.php';

// Default admins that cannot be modified
$default_admins = ['as-biserov', 'as-karpov'];

// Initialize variables
$user_id = 0;
$username = '';
$role = 'department';
$department_id = null;
$update_mode = false;
$error_message = '';
$success_message = '';

// Fetch all departments for the dropdown list
$departments_stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
$departments = $departments_stmt->fetchAll();

// --- Handle POST requests ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = strtolower(trim($_POST['username']));
    $role = $_POST['role'];
    $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;

    if (empty($username) || empty($role)) {
        $error_message = "Username and role are required.";
    } else {
        // Handle Update
        if (isset($_POST['update'])) {
            $user_id = $_POST['id'];
            $original_user_stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
            $original_user_stmt->execute(['id' => $user_id]);
            $original_username = $original_user_stmt->fetchColumn();

            // Prevent changing default admin's role
            if (in_array($original_username, $default_admins) && $role !== 'admin') {
                $error_message = "Cannot change the role of a default administrator.";
            } else {
                try {
                    $sql = "UPDATE users SET username = :username, role = :role, department_id = :department_id WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        'username' => $username,
                        'role' => $role,
                        'department_id' => ($role === 'admin') ? null : $department_id,
                        'id' => $user_id
                    ]);
                    log_event("Updated user permissions for '{$username}' (ID: {$user_id})");
                    $success_message = "User permissions updated successfully.";
                } catch (PDOException $e) {
                    $error_message = "Error updating user. Username may already exist.";
                }
            }
        // Handle Create
        } elseif (isset($_POST['save'])) {
            try {
                $sql = "INSERT INTO users (username, role, department_id) VALUES (:username, :role, :department_id)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'username' => $username,
                    'role' => $role,
                    'department_id' => ($role === 'admin') ? null : $department_id,
                ]);
                $new_id = $pdo->lastInsertId();
                log_event("Delegated permissions to new user '{$username}' (ID: {$new_id})");
                $success_message = "User permissions granted successfully.";
            } catch (PDOException $e) {
                $error_message = "Error granting permissions. Username may already exist.";
            }
        }
    }
}

// --- Handle GET requests ---
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Populate form for editing
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
    // Handle deletion
    if (isset($_GET['delete'])) {
        $user_id = $_GET['delete'];
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $user_to_delete = $stmt->fetchColumn();

        if (in_array($user_to_delete, $default_admins)) {
            $error_message = "Default administrators cannot be deleted.";
        } else {
            try {
                $sql = "DELETE FROM users WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $user_id]);
                log_event("Deleted user permissions for '{$user_to_delete}' (ID: {$user_id})");
                $success_message = "User permissions revoked successfully.";
            } catch (PDOException $e) {
                $error_message = "Error revoking permissions.";
            }
        }
    }
}
?>

<div class="row">
    <div class="col-md-4">
        <h3><?php echo $update_mode ? 'Edit Permissions' : 'Grant Permissions'; ?></h3>
        <form action="permissions.php" method="post" class="card p-3">
            <input type="hidden" name="id" value="<?php echo $user_id; ?>">
            <?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
            <?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; ?></div><?php endif; ?>

            <div class="form-group">
                <label for="username">Username (from Kerberos, without @domain)</label>
                <input type="text" name="username" id="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>" required>
            </div>
            <div class="form-group">
                <label for="role">Role</label>
                <select name="role" id="role" class="form-control" required>
                    <option value="department" <?php if($role === 'department') echo 'selected'; ?>>Department User</option>
                    <option value="admin" <?php if($role === 'admin') echo 'selected'; ?>>Administrator</option>
                </select>
            </div>
            <div class="form-group" id="department-select-group" style="<?php echo ($role !== 'department') ? 'display: none;' : ''; ?>">
                <label for="department_id">Department</label>
                <select name="department_id" id="department_id" class="form-control">
                    <option value="">-- Select Department --</option>
                    <?php foreach ($departments as $dep): ?>
                        <option value="<?php echo $dep['id']; ?>" <?php echo ($department_id == $dep['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dep['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <?php if ($update_mode): ?>
                    <button type="submit" class="btn btn-primary" name="update">Update</button>
                    <a href="permissions.php" class="btn btn-secondary">Cancel</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-success" name="save">Save</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="col-md-8">
        <h3>User Permissions List</h3>
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT u.id, u.username, u.role, d.name as department_name FROM users u LEFT JOIN departments d ON u.department_id = d.id ORDER BY u.username");
                while ($row = $stmt->fetch()) { ?>
                    <tr>
                        <td><i class="bi bi-person"></i> <?php echo htmlspecialchars($row['username']); ?></td>
                        <td><?php echo $row['role'] === 'admin' ? '<i class="bi bi-shield-lock"></i> Administrator' : '<i class="bi bi-person-workspace"></i> Department'; ?></td>
                        <td><?php echo htmlspecialchars($row['department_name'] ?? 'N/A'); ?></td>
                        <td>
                            <a href="permissions.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php if (!in_array($row['username'], $default_admins)): ?>
                                <a href="permissions.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?');" title="Delete"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Show/hide department dropdown based on role selection
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
