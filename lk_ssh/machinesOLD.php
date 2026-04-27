<?php
//Неиспользующийся старый вариант страницы доступных машин, где просто выводятся машины без ssh
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

require_once INCLUDES_PATH . '/services/ProxmoxService.php';

$pageTitle = 'Доступные машины';
$pageSubtitle = 'Список доступных для подключения машин.';
$machines = [];

try {
    $service = new ProxmoxService($config);
    $machines = $service->listActiveMachines();
} catch (Throwable $e) {
    flash('error', 'Не удалось получить список машин Proxmox: ' . $e->getMessage());
}

require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <h2>Доступные машины</h2>
    <p class="muted">Описание</p>

    <?php if (!$machines): ?>
        <p>Машины не найдены или Proxmox не вернул данные.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>VMID</th>
                    <th>Имя</th>
                    <th>Тип</th>
                    <th>Нода</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>Uptime</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($machines as $machine): ?>
                    <tr>
                        <td><code><?= e((string) $machine['vmid']) ?></code></td>
                        <td><?= e((string) $machine['name']) ?></td>
                        <td>
                            <span class="badge <?= ($machine['type'] ?? '') === 'qemu' ? 'badge-approved' : 'badge-pending' ?>">
                                <?= e((string) $machine['type']) ?>
                            </span>
                        </td>
                        <td><?= e((string) $machine['node']) ?></td>
                        <td><?= e((string) $machine['cpus']) ?></td>
                        <td><?= e(ProxmoxService::formatBytes($machine['maxmem'] ?? 0)) ?></td>
                        <td><?= e(ProxmoxService::formatUptime($machine['uptime'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>