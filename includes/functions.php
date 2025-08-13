<?php
/**
 * Centralized function to log events to the database.
 *
 * This function is designed to be included and used wherever an action needs to be logged.
 * It relies on the global $pdo object from config.php and the $_SESSION['username'].
 *
 * @param string $action The description of the action to be logged.
 */
function log_event($action) {
    // Access the global PDO object and session username
    global $pdo;
    $username = $_SESSION['username'] ?? 'system';

    // Ensure PDO object is available
    if (!isset($pdo)) {
        error_log("log_event failed: PDO object is not available.");
        return;
    }

    try {
        $sql = "INSERT INTO logs (username, action) VALUES (:username, :action)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'username' => $username,
            'action' => $action
        ]);
    } catch (PDOException $e) {
        // If logging fails, we don't want to break the user's current action.
        // Instead, we log the error to the server's error log for the administrator to review.
        error_log("Failed to log event '{$action}' for user '{$username}': " . $e->getMessage());
    }
}
?>
