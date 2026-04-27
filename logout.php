<?php
// страница выхода из личного кабинета
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    logout_user();
}

flash('success', 'Вы вышли из LK.');
redirect('login.php');
