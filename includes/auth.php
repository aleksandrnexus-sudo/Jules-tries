<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Kerberos Authentication & User Parsing ---

// For development/testing purposes, if PHP_AUTH_USER is not set by the server,
// you can simulate it by uncommenting one of the lines below.
// In a real production environment with Kerberos, this 'if' block can be removed.
if (!isset($_SERVER['PHP_AUTH_USER'])) {
    // To test as admin:
    // $_SERVER['PHP_AUTH_USER'] = 'as-biserov@domain.com';

    // To test as a regular user (assuming this user will be added to the DB with department rights):
    // $_SERVER['PHP_AUTH_USER'] = 'testuser@domain.com';

    // To test as an unauthorized user:
    // $_SERVER['PHP_AUTH_USER'] = 'unknown@domain.com';

    // If still not set, default to a known admin for development.
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        $_SERVER['PHP_AUTH_USER'] = 'as-karpov@domain.com';
    }
}

// Get the full username (e.g., login@domain) provided by the web server
$kerberos_user = $_SERVER['PHP_AUTH_USER'] ?? null;

if (empty($kerberos_user)) {
    // This case should ideally be handled by the web server configuration (e.g., Apache's AuthType Kerberos),
    // which shouldn't allow access to the script without authentication. This is a fallback.
    header('HTTP/1.1 401 Unauthorized');
    die('401 Unauthorized: Kerberos authentication is required to access this application.');
}

// Parse the username to get the part before the '@' symbol
$username_parts = explode('@', $kerberos_user);
$username = strtolower($username_parts[0]); // Use lowercase for consistency

// --- Authorization & Session Management ---

// Check if a session is already active and if the username matches.
// This avoids hitting the database on every single page load for an already-authorized user.
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['username']) && $_SESSION['username'] === $username) {
    // The user is already authenticated and authorized in this session.
    return;
}

// If there's no active session for this user, we must query the database to get their role.
// This code will run only once per session.
require_once __DIR__ . '/../config.php'; // Ensure the $pdo object is available

try {
    $stmt = $pdo->prepare("SELECT username, role, department_id FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user_data = $stmt->fetch();

    if ($user_data) {
        // User is found in our database. Authorize them by creating a session.
        session_regenerate_id(true); // Regenerate session ID to prevent session fixation attacks

        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $user_data['username'];
        $_SESSION['role'] = $user_data['role'];
        $_SESSION['department_id'] = $user_data['department_id'];

    } else {
        // The user is authenticated via Kerberos, but is not registered in our application's database.
        // Therefore, they are not authorized to use the application.
        session_destroy(); // Clean up any partial session
        header('HTTP/1.1 403 Forbidden');
        die('403 Forbidden: Your user account (' . htmlspecialchars($username) . ') is authenticated but not authorized to use this application. Please contact an administrator.');
    }

} catch (PDOException $e) {
    // This would happen if the database is down or there's a query error.
    session_destroy();
    header('HTTP/1.1 500 Internal Server Error');
    error_log("Authorization check failed: " . $e->getMessage()); // Log the actual error
    die("A critical error occurred during the authorization check. Please try again later.");
}
?>
