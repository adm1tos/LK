<?php
// страница с включенными вмками из proxmox
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

require_once INCLUDES_PATH . '/services/ProxmoxService.php';
require_once INCLUDES_PATH . '/services/VmSshService_ALT.php';

$pageTitle = 'SSH / Машины';
$pageSubtitle = 'Список активных машин Proxmox для настройки SSH.';
$machines = [];

try {
    $service = new ProxmoxService($config);
    $machines = $service->listActiveMachines();
} catch (Throwable $e) {
    //flash('error', 'Не удалось получить список машин Proxmox: ' . $e->getMessage());
    flash('error', 'Не удалось получить список машин Proxmox.');
}

$vmSsh = new VmSshService($config);

$stmt = db()->prepare('SELECT node, vmid, type, keys_count FROM user_vm_ssh_counters WHERE user_id = :user_id');
$stmt->execute(['user_id' => current_user()['id']]);
$counters = [];
foreach ($stmt->fetchAll() as $row) {
    $counters[$row['node'] . ':' . $row['vmid'] . ':' . $row['type']] = (int) $row['keys_count'];
}

if ($machines) {
    usort($machines, static function (array $a, array $b) use ($counters): int {
        $keyA = ($a['node'] ?? '') . ':' . ($a['vmid'] ?? 0) . ':' . ($a['type'] ?? 'qemu');
        $keyB = ($b['node'] ?? '') . ':' . ($b['vmid'] ?? 0) . ':' . ($b['type'] ?? 'qemu');

        $countA = $counters[$keyA] ?? 0;
        $countB = $counters[$keyB] ?? 0;

        if ($countA !== $countB) {
            return $countB <=> $countA; // Сортировка по убыванию количества ключей
        }

        return ((int) ($a['vmid'] ?? 0)) <=> ((int) ($b['vmid'] ?? 0)); // При равенстве сортируем по возрастанию VMID
    });
}

require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <div class="btn-row">
        <a class="btn btn-secondary" href="<?= e(url('lk_ssh/index.php')) ?>">Назад к SSH-ключам</a>
    </div>
</section>

<section class="card">
    <h2>Активные машины</h2>
    <p class="muted">Показаны только включённые машины.</p>

    <?php if (!$machines): ?>
        <p>Активные машины не найдены.</p>
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
                    <th>Доступ</th>
                    <th>Uptime</th>
                    <th>SSH</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($machines as $machine): ?>
                    <?php
                    $node = (string) ($machine['node'] ?? '');
                    $vmid = (int) ($machine['vmid'] ?? 0);
                    $type = (string) ($machine['type'] ?? 'qemu');
                    $canManage = $vmSsh->isManagedVm($node, $vmid, $type);
                    
                    $machineKey = $node . ':' . $vmid . ':' . $type;
                    $keysCount = $counters[$machineKey] ?? 0;
                    ?>
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
                        <td>
                            <?php if ($keysCount > 0): ?>
                                <span class="badge badge-success">Ключей: <?= e((string) $keysCount) ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Нет доступа</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(ProxmoxService::formatUptime($machine['uptime'] ?? 0)) ?></td>
                        <td>
                            <?php if ($canManage): ?>
                                <a class="btn btn-secondary"
                                   href="<?= e(url('lk_ssh/manage_machine.php?node=' . urlencode($node) . '&vmid=' . urlencode((string) $vmid) . '&type=' . urlencode($type))) ?>">
                                    Настроить SSH
                                </a>
                            <?php else: ?>
                                <span class="muted">—</span>
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