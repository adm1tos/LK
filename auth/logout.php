<?php
// страница выхода из личного кабинета
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = current_user();

if ($user) {
    // Логируем выход до разлогинивания
    $logger = new LoggerService(db());
    $logger->log('logout', 'user', $user['id'], 'Выход из системы');

    logout_user();
}

flash('success', 'Вы вышли из LK.');
redirect('auth/login.php');
