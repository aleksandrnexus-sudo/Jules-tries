<?php
/**
 * Централизованная функция для записи событий в базу данных.
 *
 * Эта функция предназначена для подключения и использования в любом месте,
 * где требуется логирование действий. Она зависит от глобального объекта $pdo
 * из config.php и имени пользователя из сессии $_SESSION['username'].
 *
 * @param string $action Описание действия для записи в лог.
 */
function log_event($action) {
    // Получаем доступ к глобальному объекту PDO и имени пользователя из сессии.
    global $pdo;
    $username = $_SESSION['username'] ?? 'system'; // Если пользователь не определен, указываем 'system'.

    // Убедимся, что объект PDO доступен.
    if (!isset($pdo)) {
        error_log("Функция log_event не выполнена: объект PDO недоступен.");
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
        // Если логирование не удалось, мы не прерываем основное действие пользователя.
        // Вместо этого, мы записываем ошибку в лог ошибок сервера для последующего анализа.
        error_log("Не удалось записать событие '{$action}' для пользователя '{$username}': " . $e->getMessage());
    }
}
?>
