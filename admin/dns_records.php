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

            $dnsService->deleteRouterRecordById($routerId);

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
                    <th>Router ID</th>
                    <th>Действие</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <?php
                    $routerId = (string) ($record['.id'] ?? '');
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
                        <td><code><?= e($routerId !== '' ? $routerId : '—') ?></code></td>
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