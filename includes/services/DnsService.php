<?php
// сервис для работы с DNS-записями
declare(strict_types=1);

final class DnsService
{
    // Инициализирует сервис DNS с доступом к БД и API MikroTik
    public function __construct(
        private readonly PDO $db,
        private readonly MikrotikService $mikrotik
    ) {
    }

    // Возвращает список DNS-записей с именем пользователя, который их создал
    public function listRecords(): array
    {
        $stmt = $this->db->query('
            SELECT dr.*, u.username AS created_by_username
            FROM dns_records dr
            LEFT JOIN users u ON u.id = dr.created_by
            ORDER BY dr.created_at DESC
        ');

        return $stmt->fetchAll();
    }

    // Находит DNS-запись в базе по её идентификатору
    public function findRecordById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM dns_records WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    // Создает или обновляет DNS-запись в БД и синхронизирует её в MikroTik
    public function saveRecord(
        string $node,
        int $vmid,
        string $type,
        string $machineName,
        string $domainName,
        string $ipAddress,
        int $userId
    ): void {
        $domainName = strtolower(trim($domainName));
        $ipAddress = trim($ipAddress);

        $this->validateDomainName($domainName);

        if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('QEMU Guest Agent вернул некорректный IPv4-адрес.');
        }

        $machineStmt = $this->db->prepare('
            SELECT *
            FROM dns_records
            WHERE node = :node AND vmid = :vmid AND type = :type
            LIMIT 1
        ');
        $machineStmt->execute([
            'node' => $node,
            'vmid' => $vmid,
            'type' => $type,
        ]);

        $existingMachine = $machineStmt->fetch() ?: null;

        $domainStmt = $this->db->prepare('
            SELECT *
            FROM dns_records
            WHERE domain_name = :domain_name
            LIMIT 1
        ');
        $domainStmt->execute(['domain_name' => $domainName]);

        $existingDomain = $domainStmt->fetch() ?: null;

        if (
            $existingDomain
            && (!$existingMachine || (int) $existingDomain['id'] !== (int) $existingMachine['id'])
        ) {
            throw new RuntimeException('Такое доменное имя уже назначено другой машине.');
        }

        if (
            $existingMachine
            && strtolower((string) $existingMachine['domain_name']) !== $domainName
        ) {
            $this->deleteRouterRecordForDbRecord($existingMachine);
        }

        $comment = 'LK DNS VMID ' . $vmid . ' ' . $machineName;

        $routerRecord = $this->mikrotik->findDnsStaticRecordByName($domainName);

        if ($routerRecord && !empty($routerRecord['.id'])) {
            $this->mikrotik->updateDnsStaticRecord(
                (string) $routerRecord['.id'],
                $domainName,
                $ipAddress,
                $comment
            );

            $mikrotikId = (string) $routerRecord['.id'];
        } else {
            $routerRecord = $this->mikrotik->addDnsStaticRecord(
                $domainName,
                $ipAddress,
                $comment
            );

            $mikrotikId = (string) ($routerRecord['.id'] ?? '');
        }

        if ($existingMachine) {
            $stmt = $this->db->prepare('
                UPDATE dns_records
                SET machine_name = :machine_name,
                    domain_name = :domain_name,
                    ip_address = :ip_address,
                    mikrotik_id = :mikrotik_id,
                    updated_at = NOW()
                WHERE id = :id
            ');

            $stmt->execute([
                'machine_name' => $machineName,
                'domain_name' => $domainName,
                'ip_address' => $ipAddress,
                'mikrotik_id' => $mikrotikId !== '' ? $mikrotikId : null,
                'id' => $existingMachine['id'],
            ]);

            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO dns_records (
                node, vmid, type, machine_name,
                domain_name, ip_address, mikrotik_id,
                created_by
            )
            VALUES (
                :node, :vmid, :type, :machine_name,
                :domain_name, :ip_address, :mikrotik_id,
                :created_by
            )
        ');

        $stmt->execute([
            'node' => $node,
            'vmid' => $vmid,
            'type' => $type,
            'machine_name' => $machineName,
            'domain_name' => $domainName,
            'ip_address' => $ipAddress,
            'mikrotik_id' => $mikrotikId !== '' ? $mikrotikId : null,
            'created_by' => $userId,
        ]);
    }

    // Удаляет DNS-запись из БД и связанный static DNS на MikroTik.
    public function deleteRecord(int $id): void
    {
        $record = $this->findRecordById($id);

        if (!$record) {
            throw new RuntimeException('DNS-запись не найдена.');
        }

        $this->deleteRouterRecordForDbRecord($record);

        $stmt = $this->db->prepare('DELETE FROM dns_records WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // Удаляет DNS-запись на MikroTik по ID или по доменному имени из записи БД.
    private function deleteRouterRecordForDbRecord(array $record): void
    {
        $mikrotikId = (string) ($record['mikrotik_id'] ?? '');

        if ($mikrotikId !== '') {
            try {
                $this->mikrotik->deleteDnsStaticRecordById($mikrotikId);
                return;
            } catch (Throwable) {
                // Если ID на MikroTik уже не актуален, попробуем найти запись по имени.
            }
        }

        $domainName = (string) ($record['domain_name'] ?? '');
        if ($domainName === '') {
            return;
        }

        $routerRecord = $this->mikrotik->findDnsStaticRecordByName($domainName);

        if ($routerRecord && !empty($routerRecord['.id'])) {
            $this->mikrotik->deleteDnsStaticRecordById((string) $routerRecord['.id']);
        }
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
            throw new RuntimeException('Некорректное доменное имя. Пример: vm100.lab.local');
        }
    }
}