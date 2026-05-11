<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();
verify_csrf();

$user = current_user();
$stmt = db()->prepare('SELECT id FROM vpn_requests WHERE user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $user['id']]);

if ($stmt->fetch()) {
    flash('error', 'Заявка уже существует.');
} else {
    $stmt = db()->prepare('INSERT INTO vpn_requests (user_id, status) VALUES (:user_id, :status)');
    $stmt->execute([
        'user_id' => $user['id'],
        'status' => 'pending',
    ]);

    $requestId = (int) db()->lastInsertId();

    // Логируем создание заявки на VPN
    $logger = new LoggerService(db());
    $logger->log(
        'create_request',
        'vpn_request',
        $requestId,
        'Создана заявка на получение VPN доступа'
    );

    flash('success', 'Заявка на VPN отправлена.');
}

redirect('lk_vpn/index.php');
