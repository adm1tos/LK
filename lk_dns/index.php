<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

// Подготавливает зависимости для получения VM, работы с роутером и DNS-записями
$pageTitle = 'DNS';
$pageSubtitle = 'Назначение DNS активным машинам Proxmox в Mikrotik.';

$proxmox = new ProxmoxService($config);
$mikrotik = new MikrotikService($config);
$dnsService = new DnsService(db(), $mikrotik);

// Формирует домен по умолчанию на основе имени VM и выбранной DNS-зоны
function dns_default_domain(string $machineName, int $vmid, string $zone): string
{
    $slug = strtolower($machineName);
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'vm' . $vmid;
    }

    return $slug . '.' . $zone;
}

// Обрабатывает сохранение и удаление DNS-записей из формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            // Валидирует выбранную VM и входные данные формы.
            $node = trim((string) ($_POST['node'] ?? ''));
            $vmid = (int) ($_POST['vmid'] ?? 0);
            $type = trim((string) ($_POST['type'] ?? 'qemu'));
            $machineName = trim((string) ($_POST['machine_name'] ?? ''));
            $domainName = trim((string) ($_POST['domain_name'] ?? ''));

            if ($node === '' || $vmid <= 0 || $machineName === '') {
                throw new RuntimeException('Не выбрана машина.');
            }

            if ($type !== 'qemu') {
                throw new RuntimeException('Автоматическое получение IP сейчас поддерживается только для QEMU VM.');
            }

            // Получает IP машины из Proxmox Guest Agent для последующего DNS
            $ipAddress = $proxmox->getQemuMachineIp($node, $vmid);

            if (!$ipAddress) {
                throw new RuntimeException(
                    'Не удалось получить IPv4-адрес через QEMU Guest Agent. Проверь, что VM включена, агент установлен и включён в настройках Proxmox.'
                );
            }

            $user = current_user();
            if (!$user) {
                throw new RuntimeException('Пользователь не найден.');
            }

            // Сохраняет запись в БД и синхронизирует static DNS в MikroTik
            $dnsService->saveRecord(
                $node,
                $vmid,
                $type,
                $machineName,
                $domainName,
                $ipAddress,
                (int) $user['id']
            );

            flash('success', 'DNS-запись сохранена: ' . $domainName . ' → ' . $ipAddress);
        } elseif ($action === 'delete') {
            // Удаляет DNS-запись по идентификатору
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Не выбрана DNS-запись.');
            }

            $dnsService->deleteRecord($id);
            flash('success', 'DNS-запись удалена.');
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('lk_dns/index.php');
}

$machines = [];
$records = [];

// Загружает список запущенных машин из Proxmox
try {
    $machines = array_values(array_filter(
        $proxmox->listActiveMachines(),
        static fn(array $machine): bool => ($machine['type'] ?? '') === 'qemu'
    ));
} catch (Throwable $e) {
    flash('error', 'Не удалось получить список VM из Proxmox: ' . $e->getMessage());
}

// Загружает уже сохраненные DNS-записи для отображения и редактирования
try {
    $records = $dnsService->listRecords();
} catch (Throwable $e) {
    flash('error', 'Не удалось получить DNS-записи: ' . $e->getMessage());
}

$recordsByMachine = [];

// Строит быстрый индекс DNS-записей по ключу node:vmid:type
foreach ($records as $record) {
    $key = $record['node'] . ':' . $record['vmid'] . ':' . $record['type'];
    $recordsByMachine[$key] = $record;
}

$defaultZone = (string) ($config['dns']['default_zone'] ?? 'lab.local');

require INCLUDES_PATH . '/header.php';
?>

<section class="card">
    <h2>Активные машины Proxmox</h2>
    <p class="muted">
        Важно: для добавления DNS-записи необходимо сначала включить виртуалку
    </p>

    <?php if (!$machines): ?>
        <p>Активные машины не найдены.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>VMID</th>
                    <th>Имя</th>
                    <th>Нода</th>
                    <th>Статус</th>
                    <th>DNS-имя</th>
                    <th>Действие</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($machines as $machine): ?>
                    <?php
                    $node = (string) ($machine['node'] ?? '');
                    $vmid = (int) ($machine['vmid'] ?? 0);
                    $type = (string) ($machine['type'] ?? 'qemu');
                    $machineName = (string) ($machine['name'] ?? ('vm-' . $vmid));

                    $machineKey = $node . ':' . $vmid . ':' . $type;
                    $existing = $recordsByMachine[$machineKey] ?? null;

                    $domain = $existing['domain_name']
                        ?? dns_default_domain($machineName, $vmid, $defaultZone);
                    ?>
                    <tr>
                        <td><code><?= e((string) $vmid) ?></code></td>
                        <td><?= e($machineName) ?></td>
                        <td><?= e($node) ?></td>
                        <td><?= e((string) ($machine['status'] ?? 'unknown')) ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>

                                <input type="hidden" name="node" value="<?= e($node) ?>">
                                <input type="hidden" name="vmid" value="<?= e((string) $vmid) ?>">
                                <input type="hidden" name="type" value="<?= e($type) ?>">
                                <input type="hidden" name="machine_name" value="<?= e($machineName) ?>">

                                <input
                                    type="text"
                                    name="domain_name"
                                    value="<?= e((string) $domain) ?>"
                                    placeholder="vm100.<?= e($defaultZone) ?>"
                                    required
                                    style="min-width: 260px;"
                                >
                        </td>
                        <td>
                                <button class="btn" type="submit" name="action" value="save">
                                    Сохранить
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Добавленные DNS-записи</h2>

    <?php if (!$records): ?>
        <p>DNS-записей пока нет.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Домен</th>
                    <th>IP</th>
                    <th>Машина</th>
                    <th>Node</th>
                    <th>Router ID</th>
                    <th>Создал</th>
                    <th>Действие</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><code><?= e((string) $record['domain_name']) ?></code></td>
                        <td><?= e((string) $record['ip_address']) ?></td>
                        <td>
                            <?= e((string) $record['machine_name']) ?>
                            <span class="muted">VMID <?= e((string) $record['vmid']) ?></span>
                        </td>
                        <td><?= e((string) $record['node']) ?></td>
                        <td><code><?= e((string) ($record['mikrotik_id'] ?? '—')) ?></code></td>
                        <td><?= e((string) ($record['created_by_username'] ?? '—')) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Удалить DNS-запись?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $record['id']) ?>">
                                <button class="btn btn-danger" type="submit" name="action" value="delete">
                                    Удалить
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>