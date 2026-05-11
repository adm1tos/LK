<?php
// страница входа в личный кабинет
// отображается при попытке входа в аккаунт без авторизации
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_user()) {
    redirect('mainmenu/dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $login = trim($_POST['login'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $errors[] = 'Заполни email и пароль.';
    } else {
        $stmt = db()->prepare('
            SELECT id, password_hash, is_active
            FROM users
            WHERE email = :email
            LIMIT 1
        ');
        $stmt->execute([
            'email' => $login,
        ]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Неверный email или пароль.';
        } elseif ((int) $user['is_active'] !== 1) {
            $errors[] = 'Аккаунт отключен.';
        } else {
            $userId = (int) $user['id'];

            // Логируем вход
            $logger = new LoggerService(db());
            $logger->log('login', 'user', $userId, 'Вход в систему');

            login_user($userId);
            flash('success', 'Вы вошли в LK.');
            redirect('mainmenu/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — LK</title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <h1>Вход в LK</h1>
        <p class="muted">Авторизуйся, чтобы открыть личный кабинет.</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($success = flash('success')): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <label for="login">Email</label>
            <input id="login" name="login" value="<?= e($_POST['login'] ?? '') ?>" required>

            <label for="password">Пароль</label>
            <input id="password" type="password" name="password" required>

            <button class="btn" type="submit">Войти</button>

            <div class="btn-row">
                <a class="btn btn-yandex" href="<?= e(url('auth/yandex/start.php')) ?>">
                    Войти через Яндекс
                </a>
            </div>
        </form>

        <p>Нет аккаунта? <a href="<?= e(url('auth/register.php')) ?>">Зарегистрироваться</a></p>
    </div>
</div>
</body>
</html>
