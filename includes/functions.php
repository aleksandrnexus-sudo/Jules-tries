<?php
/**
 * Записывает событие в лог
 *
 * @param mysqli $link Соединение с БД
 * @param int|null $user_id ID пользователя (может быть null для системных событий)
 * @param string $action Описание действия
 */
function log_event($link, $user_id, $action) {
    // Проверяем, что соединение с базой данных все еще активно
    if ($link->ping()) {
        $sql = "INSERT INTO logs (user_id, action) VALUES (?, ?)";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "is", $param_user_id, $param_action);

            $param_user_id = $user_id;
            $param_action = $action;

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } else {
            // Ошибка при подготовке запроса. Можно записать в системный лог.
            error_log("Failed to prepare statement for log_event: " . mysqli_error($link));
        }
    } else {
        // Соединение потеряно.
        error_log("Database connection lost in log_event.");
    }
}
?>
