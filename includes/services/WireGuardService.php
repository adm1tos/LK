<?php
// Генерация ключей и сборка текста WireGuard-конфига

declare(strict_types=1);

final class WireGuardService
{
    // Инициализирует сервис генерации конфигов WireGuard из конфигурации приложения
    public function __construct(private readonly array $config)
    {
    }

    /** @return array{private:string,public:string} */
    // Генерирует новую пару приватного и публичного ключей WireGuard.
    public function generateKeyPair(): array
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Для генерации WireGuard-ключей требуется расширение sodium.');
        }

        $privateRaw = random_bytes(SODIUM_CRYPTO_BOX_SECRETKEYBYTES);
        $publicRaw = sodium_crypto_scalarmult_base($privateRaw);

        return [
            'private' => base64_encode($privateRaw),
            'public' => base64_encode($publicRaw),
        ];
    }

    // Собирает текст клиентского WireGuard-конфига из ключа и IP-адреса
    // Типа шаблон
    public function createConfig(string $privateKey, string $clientIp): string
    {
        $vpn = $this->config['vpn'];

        $address = $clientIp . '/24';
        $extraAddresses = trim((string) ($vpn['extra_interface_addresses'] ?? ''));
        if ($extraAddresses !== '') {
            $address .= ', ' . $extraAddresses;
        }

        return "[Interface]
"
            . "PrivateKey = {$privateKey}
"
            . "Address = {$address}
"
            . "DNS = {$vpn['dns']}

"
            . "[Peer]
"
            . "PublicKey = {$vpn['router_public_key']}
"
            . "Endpoint = {$vpn['server_ip']}:{$vpn['server_port']}
"
            . "AllowedIPs = {$vpn['allowed_ips']}
"
            . "PersistentKeepalive = {$vpn['persistent_keepalive']}
";
    }

    // Сохраняет готовый конфиг на диск и возвращает путь к файлу
    public function saveConfig(int $userId, string $configBody): string
    {
        $dir = STORAGE_PATH . '/configs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать директорию для конфигов.');
        }

        $filePath = $dir . '/wg-user-' . $userId . '.conf';
        if (file_put_contents($filePath, $configBody) === false) {
            throw new RuntimeException('Не удалось сохранить WireGuard-конфиг на диск.');
        }

        return $filePath;
    }
}
