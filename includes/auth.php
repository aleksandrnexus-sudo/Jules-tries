<?php
/**
 * Центральный файл для аутентификации и авторизации.
 * Этот скрипт должен подключаться в начале каждой страницы, требующей доступа.
 */

// Запускаем сессию, если она еще не была запущена.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Аутентификация через Kerberos и обработка имени пользователя ---

// Блок для разработки и тестирования. В реальной среде с Kerberos он не нужен.
// Он симулирует переменную $_SERVER['PHP_AUTH_USER'], которую должен предоставлять веб-сервер.
if (!isset($_SERVER['PHP_AUTH_USER'])) {
    // Чтобы тестировать под админом:
    // $_SERVER['PHP_AUTH_USER'] = 'as-biserov@domain.com';

    // Чтобы тестировать под обычным пользователем:
    // $_SERVER['PHP_AUTH_USER'] = 'testuser@domain.com';

    // По умолчанию для разработки используется один из администраторов.
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        $_SERVER['PHP_AUTH_USER'] = 'as-karpov@domain.com';
    }
}

// Получаем полное имя пользователя (например, login@domain), переданное веб-сервером.
$kerberos_user = $_SERVER['PHP_AUTH_USER'] ?? null;

// Если имя пользователя отсутствует, прекращаем выполнение.
// В идеале, веб-сервер должен блокировать доступ еще до выполнения скрипта.
if (empty($kerberos_user)) {
    header('HTTP/1.1 401 Unauthorized');
    die('401 Unauthorized: Требуется аутентификация Kerberos для доступа к приложению.');
}

// Извлекаем логин из полного имени (часть до символа '@').
$username_parts = explode('@', $kerberos_user);
$username = strtolower($username_parts[0]); // Приводим к нижнему регистру для единообразия.

// --- Авторизация и управление сессией ---

// Проверяем, есть ли уже активная сессия для этого пользователя.
// Это позволяет избежать запросов к БД при каждой загрузке страницы.
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['username']) && $_SESSION['username'] === $username) {
    // Пользователь уже авторизован в текущей сессии.
    return;
}

// Если активной сессии нет, делаем запрос к БД для получения роли и прав.
// Этот код выполняется только один раз за сессию.
require_once __DIR__ . '/../config.php'; // Подключаем $pdo

try {
    $stmt = $pdo->prepare("SELECT username, role, department_id FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);
    $user_data = $stmt->fetch();

    if ($user_data) {
        // Пользователь найден в нашей БД. Авторизуем его, создав сессию.
        session_regenerate_id(true); // Пересоздаем ID сессии для предотвращения атак фиксации сессии.

        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $user_data['username'];
        $_SESSION['role'] = $user_data['role'];
        $_SESSION['department_id'] = $user_data['department_id'];

    } else {
        // Пользователь прошел аутентификацию Kerberos, но не зарегистрирован в нашем приложении.
        // Следовательно, он не авторизован для использования системы.
        session_destroy(); // Уничтожаем сессию.
        header('HTTP/1.1 403 Forbidden');
        die('403 Forbidden: Ваша учетная запись (' . htmlspecialchars($username) . ') аутентифицирована, но не имеет прав для доступа к этому приложению. Пожалуйста, свяжитесь с администратором.');
    }

} catch (PDOException $e) {
    // Обработка ошибок, если БД недоступна во время проверки авторизации.
    session_destroy();
    header('HTTP/1.1 500 Internal Server Error');
    error_log("Authorization check failed: " . $e->getMessage()); // Логируем ошибку на сервере.
    die("Произошла критическая ошибка при проверке авторизации. Пожалуйста, попробуйте позже.");
}
?>
