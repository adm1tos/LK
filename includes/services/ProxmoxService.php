<?php

declare(strict_types=1);

use ProxmoxVE\Proxmox;

final class ProxmoxService
{
    private Proxmox $client;

    // Инициализирует API-клиент Proxmox по параметрам из конфигурации.
    public function __construct(private readonly array $config)
    {
        $credentials = [
            'hostname' => $this->config['proxmox']['host'],
            'username' => $this->config['proxmox']['user'],
            'password' => $this->config['proxmox']['password'],
            'realm' => $this->config['proxmox']['realm'],
            'port' => $this->config['proxmox']['port'],
        ];

        $this->client = new Proxmox($credentials);
    }

    //Возвращает список QEMU VM и LXC контейнеров по всем нодам......
//берёт для каждой возвращает её данные
    public function listMachines(): array
    {
        $result = [];
        $nodesResponse = $this->client->get('/nodes');
        $nodes = $nodesResponse['data'] ?? [];

        foreach ($nodes as $nodeRow) {
            $node = (string) ($nodeRow['node'] ?? '');
            if ($node === '') {
                continue;
            }

            $qemuResponse = $this->client->get('/nodes/' . $node . '/qemu');
            foreach (($qemuResponse['data'] ?? []) as $vm) {
                $result[] = [
                    'node' => $node,
                    'vmid' => $vm['vmid'] ?? '',
                    'name' => $vm['name'] ?? ('qemu-' . ($vm['vmid'] ?? '')),
                    'type' => 'qemu',
                    'status' => $vm['status'] ?? 'unknown',
                    'cpus' => $vm['cpus'] ?? '',
                    'maxmem' => $vm['maxmem'] ?? '',
                    'uptime' => $vm['uptime'] ?? 0,
                ];
            }

            $lxcResponse = $this->client->get('/nodes/' . $node . '/lxc');
            foreach (($lxcResponse['data'] ?? []) as $ct) {
                $result[] = [
                    'node' => $node,
                    'vmid' => $ct['vmid'] ?? '',
                    'name' => $ct['name'] ?? ('lxc-' . ($ct['vmid'] ?? '')),
                    'type' => 'lxc',
                    'status' => $ct['status'] ?? 'unknown',
                    'cpus' => $ct['cpus'] ?? '',
                    'maxmem' => $ct['maxmem'] ?? '',
                    'uptime' => $ct['uptime'] ?? 0,
                ];
            }
        }

        usort($result, static function (array $a, array $b): int {
            return strcmp((string) $a['node'], (string) $b['node'])
                ?: strcmp((string) $a['type'], (string) $b['type'])
                ?: ((int) $a['vmid'] <=> (int) $b['vmid']);
        });

        return $result;
    }

    // Возвращает только запущенные машины из полученного общего списка
    public function listActiveMachines(): array
    {
        $machines = $this->listMachines();

        return array_values(array_filter(
            $machines,
            static fn(array $machine): bool => (($machine['status'] ?? '') === 'running')
        ));
    }
    // Форматирует размер в байтах в человекочитаемый вид.
    public static function formatBytes(int|string $bytes): string
    {
        $bytes = (int) $bytes;

        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1) . ' ' . $units[$power];
    }

    // Форматирует uptime в секунды/часы/дни для отображения.
    public static function formatUptime(int|string $seconds): string
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days . ' д ' . $hours . ' ч';
        }

        if ($hours > 0) {
            return $hours . ' ч ' . $minutes . ' мин';
        }

        return $minutes . ' мин';
    }

    /////дальше днс

    // Пытается получить IPv4-адрес машины через qemu guest agent
    private function getQemuIp(string $node, int $vmid): ?string
    {
        try {
            $response = $this->client->get('/nodes/' . $node . '/qemu/' . $vmid . '/agent/network-get-interfaces');
            $interfaces = $response['data']['result'] ?? [];

            foreach ($interfaces as $interface) {
                foreach (($interface['ip-addresses'] ?? []) as $ip) {
                    $address = (string) ($ip['ip-address'] ?? '');
                    $type = (string) ($ip['ip-address-type'] ?? '');

                    if ($type === 'ipv4' && $this->isUsableIp($address)) {
                        return $address;
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }


    // Проверяет, что IPv4-адрес валидный
    private function isUsableIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return !str_starts_with($ip, '127.');
    }

    // Возвращает основной рабочий IPv4 вмки, исключая служебное
    public function getQemuMachineIp(string $node, int $vmid): ?string
    {
        try {
            $response = $this->client->get(
                '/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/agent/network-get-interfaces'
            );

            $interfaces = $response['data']['result'] ?? [];

            foreach ($interfaces as $interface) {
                $interfaceName = (string) ($interface['name'] ?? '');

                if ($this->isIgnoredNetworkInterface($interfaceName)) {
                    continue;
                }

                foreach (($interface['ip-addresses'] ?? []) as $ipRow) {
                    $address = (string) ($ipRow['ip-address'] ?? '');
                    $type = (string) ($ipRow['ip-address-type'] ?? '');

                    if ($type === 'ipv4' && $this->isUsableIpv4($address)) {
                        return $address;
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    // Унифицированно получает IP машины по типу виртуализации.
    public function getMachineIp(string $node, int $vmid, string $type): ?string
    {
        if ($type !== 'qemu') {
            return null;
        }

        return $this->getQemuMachineIp($node, $vmid);
    }

    // Определяет, нужно ли исключить сетевой интерфейс из проверки IP??????????
    private function isIgnoredNetworkInterface(string $name): bool
    {
        if ($name === 'lo') {
            return true;
        }

        $ignoredPrefixes = [
            'docker',
            'br-',
            'veth',
            'virbr',
            'tun',
            'tap',
            'wg',
        ];

        foreach ($ignoredPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // Проверяет, что IPv4-адрес подходит для DNS.
    private function isUsableIpv4(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (
            str_starts_with($ip, '127.')
            || str_starts_with($ip, '169.254.')
            || $ip === '0.0.0.0'
        ) {
            return false;
        }

        return true;
    }
}