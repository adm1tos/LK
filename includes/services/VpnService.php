<?php
//
declare(strict_types=1);

final class VpnService
{
    private readonly SettingsService $settings;
    private readonly WireGuardService $wireGuard;
    private readonly MikrotikService $mikrotik;

    // Инициализирует сервис VPN и подключает связанные сервисы настроек, WireGuard и MikroTik.
    public function __construct(private readonly array $config)
    {
        $this->settings = new SettingsService();
        $this->wireGuard = new WireGuardService($config);
        $this->mikrotik = new MikrotikService($config);
    }


    // Генерирует или перегенерирует VPN-конфиг пользователя и синхронизирует peer в MikroTik.
    public function generateForUser(array $user): array
    {
        $request = $this->getUserRequest((int) $user['id']);
        if (($request['status'] ?? null) !== 'approved') {
            throw new RuntimeException('Конфиг можно получить только после одобрения заявки.');
        }

        $currentConfig = $this->getUserConfig((int) $user['id']);
        $allowRegen = $this->settings->getBool('allow_regen', false);

        $hasConfig = $currentConfig !== null && (int) ($currentConfig['has_config'] ?? 0) === 1;
        if ($hasConfig && !$allowRegen) {
            throw new RuntimeException('Повторная генерация сейчас запрещена.');
        }

        $oldPublicKey = $currentConfig['public_key'] ?? null;
        $ipAddress = $hasConfig && !empty($currentConfig['ip_address'])
            ? (string) $currentConfig['ip_address']
            : $this->allocateNextIp();

        $keys = $this->wireGuard->generateKeyPair();
        $configBody = $this->wireGuard->createConfig($keys['private'], $ipAddress);
        $comment = $this->buildComment($user);

        $routerAction = 'created';
        if ($oldPublicKey) {
            $updated = $this->mikrotik->updatePeerByPublicKey($oldPublicKey, $keys['public'], $ipAddress, $comment);
            if ($updated) {
                $routerAction = 'updated';
            } else {
                $this->mikrotik->addPeer($keys['public'], $ipAddress, $comment);
            }
        } else {
            $this->mikrotik->addPeer($keys['public'], $ipAddress, $comment);
        }

        $configPath = $this->wireGuard->saveConfig((int) $user['id'], $configBody);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $this->saveUserConfig((int) $user['id'], $keys['public'], $ipAddress);

            if (!$hasConfig || empty($currentConfig['ip_address'])) {
                $nextCounter = $this->extractLastOctet($ipAddress) + 1;
                $this->settings->set('last_ip_count', (string) $nextCounter);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'ip_address' => $ipAddress,
            'public_key' => $keys['public'],
            'config_path' => $configPath,
            'config_body' => $configBody,
            'action' => $routerAction,
        ];
    }

    // Выделяет следующий свободный IP-адрес для нового VPN-клиента
    private function allocateNextIp(): string
    {
        $counter = max(2, $this->settings->getInt('last_ip_count', 2));
        $maxClients = (int) $this->config['vpn']['max_clients'];

        if ($counter >= ($maxClients + 1)) {
            throw new RuntimeException('Достигнут лимит клиентов. Невозможно создать новый IP.');
        }

        return (string) $this->config['vpn']['user_ip_prefix'] . $counter;
    }

    // Извлекает последний октет IPv4-адреса для обновления счетчика
    private function extractLastOctet(string $ipAddress): int
    {
        $parts = explode('.', $ipAddress);
        return (int) end($parts);
    }

    // Возвращает заявку пользователя на VPN или null, если заявки нет
    private function getUserRequest(int $userId): ?array
    {
        $stmt = db()->prepare('SELECT id, status, reviewed_by, reviewed_at, created_at FROM vpn_requests WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Возвращает текущую запись VPN-конфига пользователя или null
    private function getUserConfig(int $userId): ?array
    {
        $stmt = db()->prepare('SELECT id, public_key, ip_address, has_config, is_active FROM vpn_configs WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // Создает или обновляет запись VPN-конфига пользователя в базе данных
    private function saveUserConfig(int $userId, string $publicKey, string $ipAddress): void
    {
        $stmt = db()->prepare('SELECT id FROM vpn_configs WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $save = db()->prepare('UPDATE vpn_configs SET public_key = :public_key, ip_address = :ip_address, has_config = 1, is_active = 1 WHERE user_id = :user_id');
        } else {
            $save = db()->prepare('INSERT INTO vpn_configs (user_id, public_key, ip_address, has_config, is_active) VALUES (:user_id, :public_key, :ip_address, 1, 1)');
        }

        $save->execute([
            'user_id' => $userId,
            'public_key' => $publicKey,
            'ip_address' => $ipAddress,
        ]);
    }


    // КОММЕНТАРИЙ С ИНФОЙ РЕГИСТРАЦИИ ИЗ БД 
    private function buildComment(array $user): string
    {
        $name = $user['username'];
        $email = (string) ($user['email'] ?? '');
        return sprintf('name=%s, email=%s, username=%s', $name, $email, $user['username']);
    }
}
