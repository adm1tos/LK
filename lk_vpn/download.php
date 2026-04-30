<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

$user = current_user();
$filePath = STORAGE_PATH . '/configs/wg-user-' . (int) $user['id'] . '.conf';

if (!is_file($filePath)) {
    flash('error', 'Файл конфига не найден.');
    redirect('lk_vpn/index.php');
}

header('Content-Type: text/plain; charset=utf-8');

//
$fileName = preg_replace('/[^A-Za-z0-9А-Яа-яЁё._-]+/u', '_', user_display_name($user));
$fileName = trim((string) $fileName, '_');

if ($fileName === '') {
    $fileName = 'user-' . (int) $user['id'];
}

header('Content-Disposition: attachment; filename="wg-' . $fileName . '.conf"');
//

header('Content-Length: ' . (string) filesize($filePath));
readfile($filePath);
exit;
