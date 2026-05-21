<?php
// страница просмотра логов действий пользователей
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$logger = new LoggerService(db());

// Получаем параметры пагинации и фильтров
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Получаем фильтры из GET параметров
$filters = [
    'user_id' => !empty($_GET['user_id']) ? (int) $_GET['user_id'] : null,
    'action_type' => !empty($_GET['action_type']) ? trim($_GET['action_type']) : null,
    'target_type' => !empty($_GET['target_type']) ? trim($_GET['target_type']) : null,
    'date_from' => !empty($_GET['date_from']) ? trim($_GET['date_from']) : null,
    'date_to' => !empty($_GET['date_to']) ? trim($_GET['date_to']) : null,
];

// Очищаем фильтры от пустых значений
$filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

// Получаем логи
$logs = $logger->getLogs($limit, $offset, $filters);
$totalCount = $logger->getCount($filters);
$totalPages = (int) ceil($totalCount / $limit);

// Получаем списки для фильтров
$actionTypes = $logger->getActionTypes();
$targetTypes = $logger->getTargetTypes();

$pageTitle = 'Логи действий';
$pageSubtitle = 'История всех действий пользователей в системе.';

require INCLUDES_PATH . '/header.php';
?>

<section class="card">
    <h2>Фильтры</h2>
    <form method="get" class="filter-form">
        <div class="filter-row">
            <div class="filter-group">
                <label for="filter_user_id">ID пользователя</label>
                <input type="number" id="filter_user_id" name="user_id" 
                       value="<?= e($_GET['user_id'] ?? '') ?>" min="1" 
                       placeholder="Например: 1">
            </div>

            <div class="filter-group">
                <label for="filter_action_type">Тип действия</label>
                <select id="filter_action_type" name="action_type">
                    <option value="">— Все действия —</option>
                    <?php foreach ($actionTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= (($_GET['action_type'] ?? '') === $type) ? 'selected' : '' ?>>
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="filter_target_type">Тип объекта</label>
                <select id="filter_target_type" name="target_type">
                    <option value="">— Все объекты —</option>
                    <?php foreach ($targetTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= (($_GET['target_type'] ?? '') === $type) ? 'selected' : '' ?>>
                            <?= e($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="filter_date_from">С даты</label>
                <input type="datetime-local" id="filter_date_from" name="date_from" 
                       value="<?= e($_GET['date_from'] ?? '') ?>">
            </div>

            <div class="filter-group">
                <label for="filter_date_to">По дату</label>
                <input type="datetime-local" id="filter_date_to" name="date_to" 
                       value="<?= e($_GET['date_to'] ?? '') ?>">
            </div>

            <div class="filter-group filter-actions">
                <button type="submit" class="btn">Применить</button>
                <a href="logs.php" class="btn btn-secondary">Сбросить</a>
            </div>
        </div>
    </form>
</section>

<section class="card">
    <div class="logs-header">
        <h2>Логи действий</h2>
        <p class="muted">
            Найдено записей: <strong><?= e((string) $totalCount) ?></strong>
        </p>
    </div>

    <?php if (!$logs): ?>
        <p>Логи не найдены.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Пользователь</th>
                    <th>Роль</th>
                    <th>Действие</th>
                    <th>Объект</th>
                    <th>ID объекта</th>
                    <th>Детали</th>
                    <th>Время</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $userName = trim((string) ($log['first_name'] ?? '') . ' ' . (string) ($log['last_name'] ?? ''));
                    if ($userName === '') {
                        $userName = $log['username'] ?? $log['email'] ?? '—';
                    }
                    $role = (string) ($log['role'] ?? '—');
                    $targetId = $log['target_id'] !== null ? (int) $log['target_id'] : '—';
                    ?>
                    <tr>
                        <td><?= e((string) $log['id']) ?></td>
                        <td>
                            <strong><?= e($userName) ?></strong>
                            <?php if ($log['admin_id']): ?>
                                <span class="muted">#<?= e((string) $log['admin_id']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= e($role === 'admin' ? 'approved' : 'pending') ?>">
                                <?= e($role) ?>
                            </span>
                        </td>
                        <td><code><?= e((string) $log['action_type']) ?></code></td>
                        <td><?= e((string) ($log['target_type'] ?? '—')) ?></td>
                        <td><?= e((string) $targetId) ?></td>
                        <td class="log-details" style="max-width: 300px; word-break: break-word; white-space: pre-wrap;"><?= e((string) ($log['details'] ?? '—')) ?></td>
                        <td class="log-time"><?= e((string) $log['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary" href="?page=<?= e((string) ($page - 1)) ?><?= count($filters) ? '&' . http_build_query($filters) : '' ?>">
                        ← Назад
                    </a>
                <?php endif; ?>

                <span class="pagination-info">
                    Страница <?= e((string) $page) ?> из <?= e((string) $totalPages) ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-secondary" href="?page=<?= e((string) ($page + 1)) ?><?= count($filters) ? '&' . http_build_query($filters) : '' ?>">
                        Вперед →
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>


<?php require INCLUDES_PATH . '/footer.php'; ?>