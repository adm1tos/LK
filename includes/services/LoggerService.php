<?php

declare(strict_types=1);

class LoggerService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    //Записывает действие пользователя в лог
    public function log(
        string $actionType,
        string $targetType,
        ?int $targetId = null,
        ?string $details = null,
        ?int $userId = null
    ): void {
        // Если пользователь не передан, пытаемся получить текущего
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        // Если всё ещё нет пользователя (гость), пропускаем логирование
        if ($userId === null) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO admin_logs (admin_id, action_type, target_type, target_id, details)
            VALUES (:user_id, :action_type, :target_type, :target_id, :details)
        ');

        $stmt->execute([
            'user_id' => $userId,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details,
        ]);
    }

    //Получает список логов с пагинацией
    public function getLogs(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'al.admin_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }

        if (!empty($filters['action_type'])) {
            $where[] = 'al.action_type = :action_type';
            $params['action_type'] = $filters['action_type'];
        }

        if (!empty($filters['target_type'])) {
            $where[] = 'al.target_type = :target_type';
            $params['target_type'] = $filters['target_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("
            SELECT 
                al.id,
                al.admin_id,
                al.action_type,
                al.target_type,
                al.target_id,
                al.details,
                al.created_at,
                u.first_name,
                u.last_name,
                u.username,
                u.email,
                u.role
            FROM admin_logs al
            LEFT JOIN users u ON u.id = al.admin_id
            $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll();
    }


    //Получает общее количество записей в логе с учетом фильтров
    public function getCount(array $filters = []): int
    {
        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'admin_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }

        if (!empty($filters['action_type'])) {
            $where[] = 'action_type = :action_type';
            $params['action_type'] = $filters['action_type'];
        }

        if (!empty($filters['target_type'])) {
            $where[] = 'target_type = :target_type';
            $params['target_type'] = $filters['target_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM admin_logs $whereClause");
        $stmt->execute($params);

        $result = $stmt->fetch();

        return (int) ($result['count'] ?? 0);
    }

    //Получает список всех типов действий из логов
    public function getActionTypes(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT action_type FROM admin_logs ORDER BY action_type');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    //Получает список всех типов объектов из логов
    public function getTargetTypes(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT target_type FROM admin_logs WHERE target_type IS NOT NULL ORDER BY target_type');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}