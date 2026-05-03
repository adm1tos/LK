<?php
// сервис для работы с DNS-записями MikroTik
declare(strict_types=1);

final class DnsService
{
    // Инициализирует сервис DNS с доступом к API MikroTik.
    // База данных больше не используется: источником DNS-записей считается сам MikroTik.
    public function __construct(
        private readonly MikrotikService $mikrotik
    ) {
    }

    // Возвращает список статических DNS-записей напрямую с MikroTik.
    public function listRecords(): array
    {
        $records = $this->mikrotik->getDnsStaticRecords();

        usort($records, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $records;
    }

    // Ищет DNS-запись на MikroTik по доменному имени.
    public function findRecordByName(string $domainName): ?array
    {
        $domainName = strtolower(trim($domainName));

        $this->validateDomainName($domainName);

        return $this->mikrotik->findDnsStaticRecordByName($domainName);
    }

    // Создает или обновляет static DNS-запись на MikroTik.
    public function saveRecord(
        string $domainName,
        string $ipAddress,
        string $comment = ''
    ): void {
        $domainName = strtolower(trim($domainName));
        $ipAddress = trim($ipAddress);
        $comment = trim($comment);

        $this->validateDomainName($domainName);

        if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Некорректный IPv4-адрес.');
        }

        // Если запись с таким доменом уже есть, обновляем её.
        // Если записи нет, создаём новую static DNS-запись.
        $routerRecord = $this->mikrotik->findDnsStaticRecordByName($domainName);

        if ($routerRecord && !empty($routerRecord['.id'])) {
            $this->mikrotik->updateDnsStaticRecord(
                (string) $routerRecord['.id'],
                $domainName,
                $ipAddress,
                $comment
            );

            return;
        }

        $this->mikrotik->addDnsStaticRecord($domainName, $ipAddress, $comment);
    }

    // Удаляет DNS-запись с MikroTik по внутреннему ID RouterOS.
    public function deleteRecordByRouterId(string $routerId): void
    {
        $routerId = trim($routerId);

        if ($routerId === '') {
            throw new RuntimeException('Не выбрана DNS-запись.');
        }

        $this->mikrotik->deleteDnsStaticRecordById($routerId);
    }

    // Удаляет старую DNS-запись этой же VM, если пользователь поменял доменное имя.
    public function deleteProxmoxRecordForMachineIfDomainChanged(
        string $node,
        int $vmid,
        string $type,
        string $newDomainName
    ): void {
        $newDomainName = strtolower(trim($newDomainName));
        $machineKey = $node . ':' . $vmid . ':' . $type;

        $this->validateDomainName($newDomainName);

        foreach ($this->listRecords() as $record) {
            $recordKey = self::getRecordMachineKey($record);

            if ($recordKey !== $machineKey) {
                continue;
            }

            $recordName = strtolower((string) ($record['name'] ?? ''));
            $routerId = (string) ($record['.id'] ?? '');

            if ($recordName !== $newDomainName && $routerId !== '') {
                $this->mikrotik->deleteDnsStaticRecordById($routerId);
            }
        }
    }

    // Извлекает ключ машины node:vmid:type из комментария DNS-записи MikroTik.
    public static function getRecordMachineKey(array $record): ?string
    {
        $comment = (string) ($record['comment'] ?? '');

        if (
            preg_match('/\bnode=([^;]+)/', $comment, $nodeMatch)
            && preg_match('/\bvmid=(\d+)/', $comment, $vmidMatch)
            && preg_match('/\btype=([^;]+)/', $comment, $typeMatch)
        ) {
            return trim($nodeMatch[1]) . ':' . trim($vmidMatch[1]) . ':' . trim($typeMatch[1]);
        }

        return null;
    }

    // Проверяет доменное имя на пустоту, длину и корректный формат.
    private function validateDomainName(string $domainName): void
    {
        if ($domainName === '') {
            throw new RuntimeException('Доменное имя не может быть пустым.');
        }

        if (strlen($domainName) > 255) {
            throw new RuntimeException('Доменное имя слишком длинное.');
        }

        if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domainName)) {
            throw new RuntimeException('Некорректное доменное имя. Пример: vm100.vnii.local');
        }
    }
}