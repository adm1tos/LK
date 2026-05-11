<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$pageTitle = 'DNS-записи MikroTik';
$pageSubtitle = 'Просмотр и удаление всех статических DNS-записей на MikroTik.';

$mikrotik = new MikrotikService($config);
$dnsService = new DnsService(db(), $mikrotik);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'delete') {
            $routerId = trim((string) ($_POST['router_id'] ?? ''));

            // Получаем информацию о записи перед удалением для логирования
            $records = $dnsService->listRouterRecords();
            $recordInfo = null;
            foreach ($records as $record) {
                if (($record['.id'] ?? '') === $routerId) {
                    $recordInfo = $record;
                    break;
                }
            }

            $dnsService->deleteRouterRecordById($routerId);

            // Логируем удаление DNS-записи
            $logger = new LoggerService(db());
            $logger->log(
                'delete',
                'dns_record_mikrotik',
                null,
                sprintf(
                    'Удалена DNS-запись с MikroTik: %s → %s (router_id=%s)',
                    $recordInfo['name'] ?? 'unknown',
                    $recordInfo['address'] ?? 'unknown',
                    $routerId
                )
            );

            flash('success', 'DNS-запись удалена.');
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('admin/dns_records.php');
}

$records = [];

try {
    $records = $dnsService->listRouterRecords();
} catch (Throwable $e) {
    flash('error', 'Не удалось получить DNS-записи с MikroTik: ' . $e->getMessage());
}

require INCLUDES_PATH . '/header.php';
?>

<section class="card">
    <h2>DNS-записи MikroTik</h2>
    <p class="muted">
        Список загружается напрямую с MikroTik из раздела <code>/ip dns static</code>.
        Администратор может удалить любую запись.
    </p>

    <?php if (!$records): ?>
        <p>DNS-записей на MikroTik пока нет.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Домен</th>
                    <th>IP</th>
                    <th>Комментарий</th>
                    <th>TTL</th>
                    <th>ID</th>
                    <th>Действие</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <?php
                    $routerId = (string) ($record['.id'] ?? '');
                    $displayId = str_starts_with($routerId, '*') ? (string) hexdec(substr($routerId, 1)) : $routerId;
                    $name = (string) ($record['name'] ?? '—');
                    $address = (string) ($record['address'] ?? '—');
                    $comment = (string) ($record['comment'] ?? '');
                    $ttl = (string) ($record['ttl'] ?? '—');
                    ?>
                    <tr>
                        <td><code><?= e($name) ?></code></td>
                        <td><?= e($address) ?></td>
                        <td><?= e($comment !== '' ? $comment : '—') ?></td>
                        <td><?= e($ttl !== '' ? $ttl : '—') ?></td>
                        <td><code><?= e($displayId !== '' ? $displayId : '—') ?></code></td>
                        <td>
                            <?php if ($routerId !== ''): ?>
                                <form method="post" onsubmit="return confirm('Удалить DNS-запись с MikroTik?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="router_id" value="<?= e($routerId) ?>">
                                    <button class="btn btn-danger" type="submit" name="action" value="delete">
                                        Удалить
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="muted">Нет ID</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>