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
        'username' => (string) $user['username'],
        'email' => $user['email'] ?? null,
    ]);

    flash('success', 'WireGuard-конфиг создан. IP: ' . $result['ip_address']);
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}

redirect('vpn/index.php');
