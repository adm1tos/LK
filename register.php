<?php
// страница регистрации в личный кабинет
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('mainmenu/dashboard.php');
}
// массив для хранения ошибок
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($username === '' || mb_strlen($username) < 3) {
        $errors[] = 'Логин должен быть не короче 3 символов.';
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
        $stmt = db()->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
        $stmt->execute(['username' => $username, 'email' => $email]);
        if ($stmt->fetch()) {
            $errors[] = 'Пользователь с таким логином или email уже существует.';
        }
    }
// если ошибок нет, создаем нового пользователя
    if (!$errors) {
        $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (:username, :email, :password_hash, :role)');
        $stmt->execute([
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
            <label for="username">Логин</label>
            <input id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" required>

            <label for="email">Email</label>
            <input id="email" type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>

            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" required>

            <label for="password_confirm">Повтори пароль</label>
            <input id="password_confirm" type="password" name="password_confirm" required>

            <button class="btn" type="submit">Зарегистрироваться</button>
        </form>

        <p>Уже есть аккаунт? <a href="<?= e(url('login.php')) ?>">Войти</a></p>
    </div>
</div>
</body>
</html>
