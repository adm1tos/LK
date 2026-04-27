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

    // Получает список всех WireGuard-пиров на роутере
    public function getPeers(): array
    {
        $query = new Query('/interface/wireguard/peers/print');
        $result = $this->client()->query($query)->read();
        return is_array($result) ? $result : [];
    }

    // Ищет WireGuard-пир по его публичному ключу
    public function findPeerByPublicKey(string $publicKey): ?array
    {
        $query = (new Query('/interface/wireguard/peers/print'))
            ->where('public-key', $publicKey);

        $result = $this->client()->query($query)->read();
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

        $this->client()->query($query)->read();
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

        $this->client()->query($query)->read();
        return true;
    }

    // Включает или отключает пира по его внутреннему ID на роутере
    public function togglePeerById(string $id, bool $disable): void
    {
        $query = (new Query('/interface/wireguard/peers/set'))
            ->equal('.id', $id)
            ->equal('disabled', $disable ? 'yes' : 'no');

        $this->client()->query($query)->read();
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

        $this->client()->query($query)->read();
    }

    //////////дальше для DNS

    // Получает список всех статических DNS-записей на MikroTik СТАРОЕ
    /*public function getDnsStaticRecords(): array
    {
        $query = new Query('/ip/dns/static/print');
        $result = $this->client()->query($query)->read();

        return is_array($result) ? $result : [];
    }*/

    // Ищет статическую DNS-запись на MikroTik по днс имени
    public function findDnsStaticRecordByName(string $name): ?array
    {
        $query = (new Query('/ip/dns/static/print'))
            ->where('name', $name);

        $result = $this->client()->query($query)->read();

        return $result[0] ?? null;
    }

    // Создает вченую DNS-запись на MikroTik и возвращает созданную запись
    public function addDnsStaticRecord(string $name, string $address, string $comment = ''): ?array
    {
        $query = (new Query('/ip/dns/static/add'))
            ->equal('name', $name)
            ->equal('address', $address)
            ->equal('ttl', $this->config['dns']['ttl'] ?? '1d');

        if ($comment !== '') {
            $query->equal('comment', $comment);
        }

        $this->client()->query($query)->read();

        return $this->findDnsStaticRecordByName($name);
    }

    // Обновляет параметры существующей статической DNS-записи на MikroTik.
    public function updateDnsStaticRecord(string $id, string $name, string $address, string $comment = ''): void
    {
        $query = (new Query('/ip/dns/static/set'))
            ->equal('.id', $id)
            ->equal('name', $name)
            ->equal('address', $address)
            ->equal('ttl', $this->config['dns']['ttl'] ?? '1d');

        if ($comment !== '') {
            $query->equal('comment', $comment);
        }

        $this->client()->query($query)->read();
    }

    // Удаляет статическую DNS-запись на MikroTik по ID.
    public function deleteDnsStaticRecordById(string $id): void
    {
        $query = (new Query('/ip/dns/static/remove'))
            ->equal('.id', $id);

        $this->client()->query($query)->read();
    }

}
