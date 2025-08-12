<?php
// Инициализируем сессию
session_start();

// Если пользователь уже вошел в систему, перенаправляем его на главную страницу
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: index.php");
    exit;
}

// Подключаем файл конфигурации и функции
require_once "config.php";
require_once "includes/functions.php";

// Определяем переменные и инициализируем их пустыми значениями
$username = $password = "";
$username_err = $password_err = $login_err = "";

// Обработка данных формы при отправке формы
if($_SERVER["REQUEST_METHOD"] == "POST"){

    // Проверяем, пусто ли имя пользователя
    if(empty(trim($_POST["username"]))){
        $username_err = "Пожалуйста, введите имя пользователя.";
    } else{
        $username = trim($_POST["username"]);
    }

    // Проверяем, пуст ли пароль
    if(empty(trim($_POST["password"]))){
        $password_err = "Пожалуйста, введите свой пароль.";
    } else{
        $password = trim($_POST["password"]);
    }

    // Валидация учетных данных
    if(empty($username_err) && empty($password_err)){
        // Подготовка выражения SELECT
        $sql = "SELECT id, username, password, role, department_id FROM users WHERE username = ?";

        if($stmt = mysqli_prepare($link, $sql)){
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            $param_username = $username;

            if(mysqli_stmt_execute($stmt)){
                mysqli_stmt_store_result($stmt);

                if(mysqli_stmt_num_rows($stmt) == 1){
                    mysqli_stmt_bind_result($stmt, $id, $username, $hashed_password, $role, $department_id);
                    if(mysqli_stmt_fetch($stmt)){
                        if(password_verify($password, $hashed_password)){
                            // Пароль верный, начинаем новую сессию
                            // session_start(); // Сессия уже запущена в начале файла

                            // Сохраняем данные в сессионных переменных
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $id;
                            $_SESSION["username"] = $username;
                            $_SESSION["role"] = $role;
                            $_SESSION["department_id"] = $department_id;

                            // Запись в лог
                            log_event($link, $id, "Пользователь '" . $username . "' вошел в систему.");

                            // Перенаправляем пользователя на главную страницу
                            header("location: index.php");
                        } else{
                            $login_err = "Неверное имя пользователя или пароль.";
                        }
                    }
                } else{
                    $login_err = "Неверное имя пользователя или пароль.";
                }
            } else{
                echo "Что-то пошло не так. Пожалуйста, попробуйте еще раз позже.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    mysqli_close($link);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            font-family: sans-serif;
            background-color: #f8f9fa;
        }
        .wrapper {
            width: 100%;
            max-width: 400px;
            padding: 20px;
            margin: 0 auto;
            margin-top: 5%;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <h2 class="text-center">Вход в систему</h2>
        <p class="text-center">Пожалуйста, введите ваши учетные данные.</p>

        <?php
        if(!empty($login_err)){
            echo '<div class="alert alert-danger">' . $login_err . '</div>';
        }
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group">
                <label>Имя пользователя</label>
                <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $username; ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>
            <div class="form-group">
                <label>Пароль</label>
                <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group">
                <input type="submit" class="btn btn-primary btn-block" value="Войти">
            </div>
        </form>
    </div>
</body>
</html>
