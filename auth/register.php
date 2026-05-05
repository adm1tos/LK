<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_user()) {
    redirect('mainmenu/dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($firstName === '' || mb_strlen($firstName) < 2) {
        $errors[] = 'Имя должно быть не короче 2 символов.';
    }

    if ($lastName === '' || mb_strlen($lastName) < 2) {
        $errors[] = 'Фамилия должна быть не короче 2 символов.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Укажи корректный email.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не короче 6 символов.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Пароли не совпадают.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
            $errors[] = 'Пользователь с таким email уже существует.';
        }
    }

    if (!$errors) {
        /*username оставлю на всякий случай*/

        $username = $email;

        $stmt = db()->prepare('
            INSERT INTO users (
                first_name,
                last_name,
                username,
                email,
                password_hash,
                role
            )
            VALUES (
                :first_name,
                :last_name,
                :username,
                :email,
                :password_hash,
                :role
            )
        ');

        $stmt->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'user',
        ]);

        login_user((int) db()->lastInsertId());
        flash('success', 'Аккаунт создан. Добро пожаловать в LK.');
        redirect('mainmenu/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация — LK</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <h1>Регистрация в LK</h1>
        <p class="muted">Создай аккаунт, чтобы получить доступ к модулям сайта.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="post">
            <?= csrf_field() ?>

            <label for="first_name">Имя</label>
            <input
                id="first_name"
                name="first_name"
                value="<?= e($_POST['first_name'] ?? '') ?>"
                required
            >

            <label for="last_name">Фамилия</label>
            <input
                id="last_name"
                name="last_name"
                value="<?= e($_POST['last_name'] ?? '') ?>"
                required
            >

            <label for="email">Email</label>
            <input
                id="email"
                type="email"
                name="email"
                value="<?= e($_POST['email'] ?? '') ?>"
                required
            >

            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" required>

            <label for="password_confirm">Повтори пароль</label>
            <input id="password_confirm" type="password" name="password_confirm" required>

            <button class="btn" type="submit">Зарегистрироваться</button>
            <div class="btn-row">
                <a class="btn btn-yandex" href="<?= e(url('auth/yandex/start.php')) ?>">
                    Зарегистрироваться через Яндекс
                </a>
            </div>
        </form>

        <p>Уже есть аккаунт? <a href="<?= e(url('auth/login.php')) ?>">Войти</a></p>
    </div>
</div>
</body>
</html>