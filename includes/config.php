<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', BASE_PATH . '/includes');
}

if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', BASE_PATH . '/storage');
}

function env_value(string $key, ?string $default = null): ?string
{
    static $loaded = false;

    if (!$loaded) {
        $envFile = BASE_PATH . '/.env';

        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $line, 2);

                $name = trim($name);
                $value = trim($value);

                if (strlen($value) >= 2) {
                    $first = $value[0];
                    $last = $value[strlen($value) - 1];

                    if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                        $value = substr($value, 1, -1);
                    }
                }

                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        $loaded = true;
    }

    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function detect_base_url(): string
{
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $basePath = realpath(BASE_PATH) ?: BASE_PATH;

    if (
        $documentRoot &&
        $basePath &&
        str_starts_with(str_replace('\\', '/', $basePath), str_replace('\\', '/', $documentRoot))
    ) {
        $relative = substr(
            str_replace('\\', '/', $basePath),
            strlen(str_replace('\\', '/', $documentRoot))
        );

        $relative = trim((string) $relative, '/');

        return $relative === '' ? '' : '/' . $relative;
    }

    return '';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', detect_base_url());
}

return [
    'app_name' => env_value('APP_NAME', 'LK'),
    'app_env' => env_value('APP_ENV', 'local'),

    'db' => [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => env_value('DB_PORT', '3306'),
        'name' => env_value('DB_NAME', 'lk'),
        'user' => env_value('DB_USER', 'root'),
        'pass' => env_value('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    'mikrotik' => [
        'host' => env_value('MIKROTIK_HOST', ''),
        'user' => env_value('MIKROTIK_USER', ''),
        'password' => env_value('MIKROTIK_PASSWORD', ''),
        'port' => env_value('MIKROTIK_PORT', '8728'),
        'use_ssl' => env_value('MIKROTIK_USE_SSL', '0') === '1',
        'timeout' => (int) env_value('MIKROTIK_TIMEOUT', '10'),
    ],

    'vpn' => [
        'wg_interface_name' => env_value('VPN_WG_INTERFACE_NAME', 'wireguard1'),
        'server_ip' => env_value('VPN_SERVER_IP', ''),
        'server_port' => (int) env_value('VPN_SERVER_PORT', '51820'),
        'dns' => env_value('VPN_DNS', '8.8.8.8'),
        'router_public_key' => env_value('VPN_ROUTER_PUBLIC_KEY', ''),
        'user_ip_prefix' => env_value('VPN_USER_IP_PREFIX', '192.168.0.'),
        'max_clients' => (int) env_value('VPN_MAX_CLIENTS', '253'),
        'allowed_ips' => env_value('VPN_ALLOWED_IPS', '192.168.100.0/24, 192.168.102.0/24, 192.168.109.1/32'),
        'extra_interface_addresses' => env_value('VPN_EXTRA_INTERFACE_ADDRESSES', ''),
        'persistent_keepalive' => (int) env_value('VPN_PERSISTENT_KEEPALIVE', '25'),
    ],

    'proxmox' => [
        'host' => env_value('PROXMOX_HOST', '127.0.0.1'),
        'user' => env_value('PROXMOX_USER', ''),
        'password' => env_value('PROXMOX_PASSWORD', ''),
        'realm' => env_value('PROXMOX_REALM', 'pam'),
        'port' => env_value('PROXMOX_PORT', '8006'),
    ],

    'test_ssh_vm' => [
        'host' => env_value('TEST_VM_SSH_HOST', '127.0.0.1'),
        'port' => (int) env_value('TEST_VM_SSH_PORT', '2224'),
        'user' => env_value('TEST_VM_SSH_USER', 'lkadmin'),
        'vmid' => (int) env_value('TEST_VM_VMID', '100'),
        'node' => env_value('TEST_VM_NODE', 'prox'),
        'type' => env_value('TEST_VM_TYPE', 'qemu'),
    ],

    'ssh_admin' => [
        'private_key_path' => env_value('SSH_ADMIN_PRIVATE_KEY_PATH', 'storage/keys/lkadmin_site_id_ed25519'),
        'script_path' => env_value('SSH_ADMIN_SCRIPT_PATH', 'storage/scripts/lk-ssh-admin.sh'),
    ],

    'dns' => [
        'default_zone' => env_value('DNS_DEFAULT_ZONE', 'vnii.local'),
    ],

    'yandex' => [
    'client_id' => env_value('YANDEX_CLIENT_ID', ''),
    'client_secret' => env_value('YANDEX_CLIENT_SECRET', ''),
    'redirect_uri' => env_value('YANDEX_REDIRECT_URI', ''),
    ],
];