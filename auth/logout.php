<?php
// страница выхода из личного кабинета
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_user()) {
    logout_user();
}

flash('success', 'Вы вышли из LK.');
redirect('auth/login.php');
