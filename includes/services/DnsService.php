<?php
// сервис для работы с DNS-записями
declare(strict_types=1);

final class DnsService
{
    // Инициализирует сервис DNS с доступом к БД и API MikroTik.
    public function __construct(
        private readonly PDO $db,
        private readonly MikrotikService $mikrotik
    ) {
    }

    // Возвращает все static DNS-записи напрямую с MikroTik.
    // Используется на админской странице.
    public function listRouterRecords(): array
    {
        $records = $this->mikrotik->getDnsStaticRecords();

        usort($records, static function (array $a, array $b): int {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $records;
    }

    // Возвращает DNS-записи, созданные конкретным пользователем через LK.
    public function listUserRecords(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT *
            FROM dns_records
            WHERE created_by = :created_by
            ORDER BY created_at DESC
        ');

        $stmt->execute([
            'created_by' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    // Находит DNS-запись в БД по ID.
    public function findDbRecordById(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT *
            FROM dns_records
            WHERE id = :id
            LIMIT 1
        ');

        $stmt->execute([
            'id' => $id,
        ]);

        return $stmt->fetch() ?: null;
    }

    // Ищет DNS-запись на MikroTik по доменному имени.
    public function findRecordByName(string $domainName): ?array
    {
        $domainName = strtolower(trim($domainName));

        $this->validateDomainName($domainName);

        return $this->mikrotik->findDnsStaticRecordByName($domainName);
    }

    // Создаёт или обновляет DNS-запись для VM из Proxmox.
    public function saveProxmoxRecord(
        string $node,
        int $vmid,
        string $type,
        string $machineName,
        string $domainName,
        string $ipAddress,
        string $comment,
        int $userId
    ): void {
        $this->saveRecord([
            'source' => 'proxmox',
            'node' => $node,
            'vmid' => $vmid,
            'type' => $type,
            'machine_name' => $machineName,
            'domain_name' => $domainName,
            'ip_address' => $ipAddress,
            'record_comment' => $comment,
            'created_by' => $userId,
        ]);
    }

    // Создаёт или обновляет произвольную DNS-запись пользователя.
    public function saveManualRecord(
        string $domainName,
        string $ipAddress,
        string $comment,
        int $userId
    ): void {
        $this->saveRecord([
            'source' => 'manual',
            'node' => null,
            'vmid' => null,
            'type' => null,
            'machine_name' => null,
            'domain_name' => $domainName,
            'ip_address' => $ipAddress,
            'record_comment' => $comment,
            'created_by' => $userId,
        ]);
    }

    // Общая логика создания/обновления DNS-записи в MikroTik и БД.
    private function saveRecord(array $data): void
    {
        $source = (string) ($data['source'] ?? 'manual');
        $node = $data['node'] !== null ? trim((string) $data['node']) : null;
        $vmid = $data['vmid'] !== null ? (int) $data['vmid'] : null;
        $type = $data['type'] !== null ? trim((string) $data['type']) : null;
        $machineName = $data['machine_name'] !== null ? trim((string) $data['machine_name']) : null;

        $domainName = strtolower(trim((string) $data['domain_name']));
        $ipAddress = trim((string) $data['ip_address']);
        $comment = trim((string) ($data['record_comment'] ?? ''));
        $userId = (int) $data['created_by'];

        $this->validateDomainName($domainName);

        if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Некорректный IPv4-адрес.');
        }

        $existingRecord = null;

        if ($source === 'proxmox' && $node !== null && $vmid !== null && $type !== null) {
            $stmt = $this->db->prepare('
                SELECT *
                FROM dns_records
                WHERE source = "proxmox"
                  AND node = :node
                  AND vmid = :vmid
                  AND type = :type
                  AND created_by = :created_by
                LIMIT 1
            ');

            $stmt->execute([
                'node' => $node,
                'vmid' => $vmid,
                'type' => $type,
                'created_by' => $userId,
            ]);

            $existingRecord = $stmt->fetch() ?: null;
        }

        if (!$existingRecord) {
            $stmt = $this->db->prepare('
                SELECT *
                FROM dns_records
                WHERE domain_name = :domain_name
                LIMIT 1
            ');

            $stmt->execute([
                'domain_name' => $domainName,
            ]);

            $existingRecord = $stmt->fetch() ?: null;
        }

        if ($existingRecord && (int) $existingRecord['created_by'] !== $userId) {
            throw new RuntimeException('Такая DNS-запись уже создана другим пользователем.');
        }

        $domainOwnerStmt = $this->db->prepare('
            SELECT *
            FROM dns_records
            WHERE domain_name = :domain_name
            LIMIT 1
        ');

        $domainOwnerStmt->execute([
            'domain_name' => $domainName,
        ]);

        $domainOwner = $domainOwnerStmt->fetch() ?: null;

        if (
            $domainOwner
            && (!$existingRecord || (int) $domainOwner['id'] !== (int) $existingRecord['id'])
        ) {
            throw new RuntimeException('Такое DNS-имя уже используется.');
        }

        $routerRecord = $this->mikrotik->findDnsStaticRecordByName($domainName);

        if ($routerRecord && !$domainOwner && !$existingRecord) {
            throw new RuntimeException('Такая DNS-запись уже существует на MikroTik. Обратись к администратору.');
        }

        if (
            $existingRecord
            && strtolower((string) $existingRecord['domain_name']) !== $domainName
        ) {
            $this->deleteRouterRecordForDbRecord($existingRecord);
        }

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

        if ($existingRecord) {
            $stmt = $this->db->prepare('
                UPDATE dns_records
                SET source = :source,
                    node = :node,
                    vmid = :vmid,
                    type = :type,
                    machine_name = :machine_name,
                    domain_name = :domain_name,
                    ip_address = :ip_address,
                    record_comment = :record_comment,
                    mikrotik_id = :mikrotik_id,
                    updated_at = NOW()
                WHERE id = :id
            ');

            $stmt->execute([
                'source' => $source,
                'node' => $node,
                'vmid' => $vmid,
                'type' => $type,
                'machine_name' => $machineName,
                'domain_name' => $domainName,
                'ip_address' => $ipAddress,
                'record_comment' => $comment !== '' ? $comment : null,
                'mikrotik_id' => $mikrotikId !== '' ? $mikrotikId : null,
                'id' => $existingRecord['id'],
            ]);

            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO dns_records (
                source,
                node,
                vmid,
                type,
                machine_name,
                domain_name,
                ip_address,
                record_comment,
                mikrotik_id,
                created_by
            )
            VALUES (
                :source,
                :node,
                :vmid,
                :type,
                :machine_name,
                :domain_name,
                :ip_address,
                :record_comment,
                :mikrotik_id,
                :created_by
            )
        ');

        $stmt->execute([
            'source' => $source,
            'node' => $node,
            'vmid' => $vmid,
            'type' => $type,
            'machine_name' => $machineName,
            'domain_name' => $domainName,
            'ip_address' => $ipAddress,
            'record_comment' => $comment !== '' ? $comment : null,
            'mikrotik_id' => $mikrotikId !== '' ? $mikrotikId : null,
            'created_by' => $userId,
        ]);
    }

    // Обновляет существующую пользовательскую DNS-запись (имя, IP, комментарий).
    public function updateUserRecord(
        int $recordId,
        string $newDomainName,
        string $newIpAddress,
        string $newComment,
        int $userId
    ): void {
        $record = $this->findDbRecordById($recordId);

        if (!$record) {
            throw new RuntimeException('DNS-запись не найдена.');
        }

        if ((int) $record['created_by'] !== $userId) {
            throw new RuntimeException('У вас нет прав на редактирование этой записи.');
        }

        $newDomainName = strtolower(trim($newDomainName));
        $newIpAddress = trim($newIpAddress);
        $newComment = trim($newComment);

        $this->validateDomainName($newDomainName);

        if (!filter_var($newIpAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Некорректный IPv4-адрес.');
        }

        if (strtolower((string) $record['domain_name']) !== $newDomainName) {
            $stmt = $this->db->prepare('SELECT id FROM dns_records WHERE domain_name = :domain_name AND id != :id LIMIT 1');
            $stmt->execute(['domain_name' => $newDomainName, 'id' => $recordId]);
            if ($stmt->fetch()) {
                throw new RuntimeException('Такое DNS-имя уже используется.');
            }

            $routerRecord = $this->mikrotik->findDnsStaticRecordByName($newDomainName);
            if ($routerRecord) {
                throw new RuntimeException('Такая DNS-запись уже существует на MikroTik. Обратись к администратору.');
            }
        }

        $mikrotikId = (string) ($record['mikrotik_id'] ?? '');
        if ($mikrotikId === '') {
            $oldRouterRecord = $this->mikrotik->findDnsStaticRecordByName((string) $record['domain_name']);
            if ($oldRouterRecord && !empty($oldRouterRecord['.id'])) {
                $mikrotikId = (string) $oldRouterRecord['.id'];
            }
        }

        if ($mikrotikId !== '') {
            try {
                $this->mikrotik->updateDnsStaticRecord($mikrotikId, $newDomainName, $newIpAddress, $newComment);
            } catch (Throwable) {
                $newRouterRecord = $this->mikrotik->addDnsStaticRecord($newDomainName, $newIpAddress, $newComment);
                $mikrotikId = (string) ($newRouterRecord['.id'] ?? '');
            }
        } else {
            $newRouterRecord = $this->mikrotik->addDnsStaticRecord($newDomainName, $newIpAddress, $newComment);
            $mikrotikId = (string) ($newRouterRecord['.id'] ?? '');
        }

        $stmt = $this->db->prepare('UPDATE dns_records SET domain_name = :domain_name, ip_address = :ip_address, record_comment = :record_comment, mikrotik_id = :mikrotik_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'domain_name' => $newDomainName, 'ip_address' => $newIpAddress,
            'record_comment' => $newComment !== '' ? $newComment : null, 'mikrotik_id' => $mikrotikId !== '' ? $mikrotikId : null, 'id' => $recordId,
        ]);
    }

    // Удаляет пользовательскую DNS-запись из MikroTik и БД.
    public function deleteUserRecord(int $recordId, int $userId): void
    {
        $record = $this->findDbRecordById($recordId);

        if (!$record) {
            throw new RuntimeException('DNS-запись не найдена.');
        }

        if ((int) $record['created_by'] !== $userId) {
            throw new RuntimeException('Нельзя удалить чужую DNS-запись.');
        }

        $this->deleteRouterRecordForDbRecord($record);

        $stmt = $this->db->prepare('
            DELETE FROM dns_records
            WHERE id = :id
        ');

        $stmt->execute([
            'id' => $recordId,
        ]);
    }

    // Админское удаление DNS-записи с MikroTik по RouterOS ID.
    // Если запись была создана через LK, она также удаляется из БД.
    public function deleteRouterRecordById(string $routerId): void
    {
        $routerId = trim($routerId);

        if ($routerId === '') {
            throw new RuntimeException('Не выбрана DNS-запись.');
        }

        $routerRecord = $this->findRouterRecordById($routerId);
        $domainName = (string) ($routerRecord['name'] ?? '');

        $this->mikrotik->deleteDnsStaticRecordById($routerId);

        $stmt = $this->db->prepare('
            DELETE FROM dns_records
            WHERE mikrotik_id = :mikrotik_id
               OR domain_name = :domain_name
        ');

        $stmt->execute([
            'mikrotik_id' => $routerId,
            'domain_name' => $domainName,
        ]);
    }

    // Удаляет старую DNS-запись этой же VM, если пользователь поменял доменное имя.
    public function deleteProxmoxRecordForMachineIfDomainChanged(
        string $node,
        int $vmid,
        string $type,
        string $newDomainName,
        int $userId
    ): void {
        $newDomainName = strtolower(trim($newDomainName));

        $stmt = $this->db->prepare('
            SELECT *
            FROM dns_records
            WHERE source = "proxmox"
              AND node = :node
              AND vmid = :vmid
              AND type = :type
              AND created_by = :created_by
            LIMIT 1
        ');

        $stmt->execute([
            'node' => $node,
            'vmid' => $vmid,
            'type' => $type,
            'created_by' => $userId,
        ]);

        $record = $stmt->fetch() ?: null;

        if (!$record) {
            return;
        }

        if (strtolower((string) $record['domain_name']) === $newDomainName) {
            return;
        }

        $this->deleteRouterRecordForDbRecord($record);

        $delete = $this->db->prepare('
            DELETE FROM dns_records
            WHERE id = :id
        ');

        $delete->execute([
            'id' => $record['id'],
        ]);
    }

    // Ищет запись MikroTik по внутреннему RouterOS ID.
    private function findRouterRecordById(string $routerId): ?array
    {
        foreach ($this->listRouterRecords() as $record) {
            if ((string) ($record['.id'] ?? '') === $routerId) {
                return $record;
            }
        }

        return null;
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
            throw new RuntimeException('Некорректное доменное имя. Пример: vm100.vnii.local');
        }
    }
}