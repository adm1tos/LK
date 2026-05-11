<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();
verify_csrf();

$user = current_user();

try {
    $service = new VpnService($config);
    $result = $service->generateForUser([
        'id' => (int) $user['id'],
        'first_name' => (string) ($user['first_name'] ?? ''),
        'last_name' => (string) ($user['last_name'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'email' => $user['email'] ?? null,
    ]);

    // Получаем ID конфига для логирования
    $stmt = db()->prepare('SELECT id FROM vpn_configs WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $user['id']]);
    $config = $stmt->fetch();
    $configId = $config ? (int) $config['id'] : null;

    // Логируем генерацию VPN конфига
    $logger = new LoggerService(db());
    $logger->log(
        'generate',
        'vpn_config',
        $configId,
        sprintf('Сгенерирован WireGuard конфиг. IP: %s', $result['ip_address'])
    );

    flash('success', 'WireGuard-конфиг создан. IP: ' . $result['ip_address']);
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}

redirect('lk_vpn/index.php');
