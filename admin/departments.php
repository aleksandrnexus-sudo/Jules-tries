<?php
require_once 'header.php';

// Initialize variables
$department_name = "";
$department_id = 0;
$update_mode = false;
$error_message = '';
$success_message = '';

// Handle POST requests for creating and updating
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $department_name = trim($_POST['name']);

    if (empty($department_name)) {
        $error_message = "Department name cannot be empty.";
    } else {
        // Update existing department
        if (isset($_POST['update'])) {
            $department_id = $_POST['id'];
            try {
                $sql = "UPDATE departments SET name = :name WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['name' => $department_name, 'id' => $department_id]);
                log_event("Updated department ID: {$department_id} with new name '{$department_name}'");
                $success_message = "Department updated successfully.";
            } catch (PDOException $e) {
                $error_message = "Error updating department. It might already exist. " . $e->getMessage();
            }
        // Create new department
        } elseif (isset($_POST['save'])) {
            try {
                $sql = "INSERT INTO departments (name) VALUES (:name)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['name' => $department_name]);
                $new_id = $pdo->lastInsertId();
                log_event("Created new department '{$department_name}' (ID: {$new_id})");
                $success_message = "Department created successfully.";
                $department_name = ""; // Clear field after successful insert
            } catch (PDOException $e) {
                $error_message = "Error creating department. It might already exist. " . $e->getMessage();
            }
        }
    }
}

// Handle GET requests for editing and deleting
if ($_SERVER["REQUEST_METHOD"] == "GET") {
    // Populate form for editing
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
    // Handle deletion
    if (isset($_GET['delete'])) {
        $department_id = $_GET['delete'];

        try {
            // Check if any users are assigned to this department
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id = :id");
            $stmt_check->execute(['id' => $department_id]);
            $user_count = $stmt_check->fetchColumn();

            if ($user_count > 0) {
                $error_message = "Cannot delete department: users are currently assigned to it. Please reassign them first.";
            } else {
                $sql = "DELETE FROM departments WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $department_id]);
                log_event("Deleted department ID: {$department_id}");
                $success_message = "Department deleted successfully.";
            }
        } catch (PDOException $e) {
            $error_message = "Error deleting department: " . $e->getMessage();
        }
    }
}
?>

<div class="row">
    <div class="col-md-4">
        <h3><?php echo $update_mode ? 'Edit Department' : 'Add New Department'; ?></h3>
        <form action="departments.php" method="post" class="card p-3">
            <input type="hidden" name="id" value="<?php echo $department_id; ?>">
            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <div class="form-group">
                <label for="name">Department Name</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($department_name); ?>" required>
            </div>
            <div class="form-group">
                <?php if ($update_mode): ?>
                    <button type="submit" class="btn btn-primary" name="update">Update</button>
                    <a href="departments.php" class="btn btn-secondary">Cancel</a>
                <?php else: ?>
                    <button type="submit" class="btn btn-success" name="save">Save</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="col-md-8">
        <h3>Department List</h3>
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
                while ($row = $stmt->fetch()) { ?>
                    <tr>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td>
                            <a href="departments.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-info" title="Edit"><i class="bi bi-pencil"></i></a>
                            <a href="departments.php?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this department?');" title="Delete"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once 'footer.php';
?>
