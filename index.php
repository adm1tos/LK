<?php
// index.php - начальная страница сайта
// отображается при входе на сайт без авторизации или при выходе из аккаунта
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// если пользователь авторизован, перенаправляем на главную страницу личного кабинета
if (!is_guest()) {
    redirect('/mainmenu/dashboard.php');
}

$pageTitle = 'LK — начальная страница';
require_once __DIR__ . '/includes/header.php';
?>

<div class="content">
    <div class="card">
        <h1>LK</h1>
        <p>Добро пожаловать в LK.</p>
        <p>Это начальная страница сайта. Для продолжения войдите в аккаунт.</p>

        <div class="actions">
            <a class="btn btn-primary" href="/login.php">Войти</a>
            <a class="btn btn-secondary" href="/register.php">Регистрация</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>