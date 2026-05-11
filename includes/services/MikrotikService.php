<?php
// Добавление включение/выключение удаление вывод списка пиров

declare(strict_types=1);

use RouterOS\Client;
use RouterOS\Query;

final class MikrotikService
{
    private array $config;
    private ?Client $client = null;

    // Сохраняет конфигурацию подключения к MikroTik
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // Возвращает подключенный API-клиент MikroTik с ленивой инициализацией
    private function client(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        $mt = $this->config['mikrotik'];

        $this->client = new Client([
            'host' => $mt['host'],
            'user' => $mt['user'],
            'pass' => $mt['password'],
            'port' => (int) $mt['port'],
            'timeout' => (int) $mt['timeout'],
            'ssl' => (bool) $mt['use_ssl'],
        ]);

        return $this->client;
    }

    // Выполняет запрос к роутеру и проверяет ответ на наличие ошибок
    private function executeQuery(Query $query): array
    {
        $result = $this->client()->query($query)->read();
        $resultArray = is_array($result) ? $result : [];

        // Проверяем наличие ошибки (!trap) в ответе
        foreach ($resultArray as $item) {
            if (is_array($item)) {
                if (array_key_exists('!trap', $item) || isset($item['message'])) {
                    $msg = $item['message'] ?? $item['!trap']['message'] ?? $item['!trap'][0]['message'] ?? 'Недостаточно прав или неизвестная ошибка';
                    throw new RuntimeException('Ошибка API: ' . (string) $msg);
                }
            }
        }

        // Обработка случая, если ошибка вернулась плоским массивом
        if (array_key_exists('!trap', $resultArray) || isset($resultArray['message'])) {
            $msg = $resultArray['message'] ?? $resultArray['!trap']['message'] ?? $resultArray['!trap'][0]['message'] ?? 'Недостаточно прав или неизвестная ошибка';
            throw new RuntimeException('Ошибка API: ' . (string) $msg);
        }

        return $resultArray;
    }

    // Получает список всех WireGuard-пиров на роутере
    public function getPeers(): array
    {
        $query = new Query('/interface/wireguard/peers/print');
        return $this->executeQuery($query);
    }

    // Ищет WireGuard-пир по его публичному ключу
    public function findPeerByPublicKey(string $publicKey): ?array
    {
        $query = (new Query('/interface/wireguard/peers/print'))
            ->where('public-key', $publicKey);

        $result = $this->executeQuery($query);
        return $result[0] ?? null;
    }

    // Добавляет нового WireGuard пира в интерфейс роутера
    public function addPeer(string $publicKey, string $ipAddress, string $comment = ''): void
    {
        $query = (new Query('/interface/wireguard/peers/add'))
            ->equal('interface', $this->config['vpn']['wg_interface_name'])
            ->equal('public-key', $publicKey)
            ->equal('allowed-address', $ipAddress . '/32');

        if ($comment !== '') {
            $query->equal('comment', $comment);
        }

        $this->executeQuery($query);
    }

    // Обновляет существующего пира по старому ключу и возвращает факт обновления
    public function updatePeerByPublicKey(
        string $oldPublicKey,
        string $newPublicKey,
        string $ipAddress,
        string $comment = ''
    ): bool {
        $peer = $this->findPeerByPublicKey($oldPublicKey);
        if (!$peer || empty($peer['.id'])) {
            return false;
        }

        $query = (new Query('/interface/wireguard/peers/set'))
            ->equal('.id', $peer['.id'])
            ->equal('public-key', $newPublicKey)
            ->equal('allowed-address', $ipAddress . '/32');

        if ($comment !== '') {
            $query->equal('comment', $comment);
        }

        $this->executeQuery($query);
        return true;
    }

    // Включает или отключает пира по его внутреннему ID на роутере
    public function togglePeerById(string $id, bool $disable): void
    {
        $query = (new Query('/interface/wireguard/peers/set'))
            ->equal('.id', $id)
            ->equal('disabled', $disable ? 'yes' : 'no');

        $this->executeQuery($query);
    }

    // ПЕРЕКЛЮЧЕНИЕ ПИРА
    public function setPeerDisabled(string $id, bool $disable): void
    {
        $this->togglePeerById($id, $disable);
    }

    // Удаляет WireGuard-пира по внутреннему ID
    public function deletePeerById(string $id): void
    {
        $query = (new Query('/interface/wireguard/peers/remove'))
            ->equal('.id', $id);

        $this->executeQuery($query);
    }

    //////////дальше для DNS

     // Получает список всех статических DNS-записей на MikroTik \уже не старое
    public function getDnsStaticRecords(): array
    {
        $query = new Query('/ip/dns/static/print');
        return $this->executeQuery($query);
    }

    // Ищет статическую DNS-запись на MikroTik по днс имени
    public function findDnsStaticRecordByName(string $name): ?array
    {
        $query = (new Query('/ip/dns/static/print'))
            ->where('name', $name);

        $result = $this->executeQuery($query);
        return $result[0] ?? null;
    }

        // Создает вченую DNS-запись на MikroTik и возвращает созданную запись
    public function addDnsStaticRecord(string $name, string $address, string $comment = ''): ?array
    {
        $query = (new Query('/ip/dns/static/add'))
            ->equal('name', $name)
            ->equal('address', $address);

        if ($comment !== '') {
            $query->equal('comment', $comment);
        }

        $this->executeQuery($query);
        return $this->findDnsStaticRecordByName($name);
    }

        // Обновляет параметры существующей статической DNS-записи на MikroTik.
    public function updateDnsStaticRecord(string $id, string $name, string $address, string $comment = ''): void
    {
        $query = (new Query('/ip/dns/static/set'))
            ->equal('.id', $id)
            ->equal('name', $name)
            ->equal('address', $address);

        if ($comment !== '') {
            $query->equal('comment', $comment);
        }

        $this->executeQuery($query);
    }

        // Удаляет статическую DNS-запись на MikroTik по ID.
    public function deleteDnsStaticRecordById(string $id): void
    {
        $query = (new Query('/ip/dns/static/remove'))
            ->equal('.id', $id);

        $this->executeQuery($query);
    }
}