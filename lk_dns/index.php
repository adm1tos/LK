<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

// Подготавливает зависимости для получения VM, работы с роутером и DNS-записями
$pageTitle = 'DNS';
$pageSubtitle = 'Назначение DNS-записей через MikroTik.';

$proxmox = new ProxmoxService($config);
$mikrotik = new MikrotikService($config);
$dnsService = new DnsService($mikrotik);

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

// Форматирует размер памяти для комментария DNS-записи
function dns_format_bytes(int|string|null $bytes): string
{
    $bytes = (int) $bytes;

    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

    return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
}

// Формирует комментарий для DNS-записи, созданной по VM из Proxmox
function dns_vm_comment(array $machine, string $ipAddress): string
{
    $vmid = (int) ($machine['vmid'] ?? 0);
    $name = (string) ($machine['name'] ?? ('vm-' . $vmid));
    $node = (string) ($machine['node'] ?? '');
    $type = (string) ($machine['type'] ?? 'qemu');
    $status = (string) ($machine['status'] ?? 'unknown');
    $cpus = (string) ($machine['cpus'] ?? '—');
    $ram = dns_format_bytes($machine['maxmem'] ?? 0);

    return sprintf(
        'LK DNS; source=proxmox; node=%s; vmid=%d; type=%s; name=%s; status=%s; cpu=%s; ram=%s; ip=%s',
        $node,
        $vmid,
        $type,
        $name,
        $status,
        $cpus,
        $ram,
        $ipAddress
    );
}

// Формирует комментарий для DNS-записи, добавленной вручную
function dns_manual_comment(string $customComment = ''): string
{
    $customComment = trim($customComment);

    if ($customComment === '') {
        return 'LK DNS; source=manual';
    }

    return 'LK DNS; source=manual; comment=' . $customComment;
}

// Обрабатывает сохранение и удаление DNS-записей из формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_vm_dns') {
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

            $machineKey = $node . ':' . $vmid . ':' . $type;

            // Проверяет, не занято ли выбранное DNS-имя другой VM из Proxmox.
            $existingByDomain = $dnsService->findRecordByName($domainName);
            $existingMachineKey = $existingByDomain
                ? DnsService::getRecordMachineKey($existingByDomain)
                : null;

            if ($existingMachineKey !== null && $existingMachineKey !== $machineKey) {
                throw new RuntimeException('Такое DNS-имя уже назначено другой виртуальной машине.');
            }

            // Получает IP машины из Proxmox Guest Agent для последующего DNS
            $ipAddress = $proxmox->getQemuMachineIp($node, $vmid);

            if (!$ipAddress) {
                throw new RuntimeException(
                    'Не удалось получить IPv4-адрес через QEMU Guest Agent. Проверь, что VM включена, агент установлен и включён в настройках Proxmox.'
                );
            }

            $machine = [
                'node' => $node,
                'vmid' => $vmid,
                'type' => $type,
                'name' => $machineName,
                'status' => (string) ($_POST['status'] ?? 'running'),
                'cpus' => (string) ($_POST['cpus'] ?? ''),
                'maxmem' => (string) ($_POST['maxmem'] ?? '0'),
            ];

            // Если у этой VM уже была DNS-запись с другим именем, удаляет старую запись с MikroTik.
            $dnsService->deleteProxmoxRecordForMachineIfDomainChanged(
                $node,
                $vmid,
                $type,
                $domainName
            );

            // Сохраняет или обновляет static DNS-запись на MikroTik.
            $dnsService->saveRecord(
                $domainName,
                $ipAddress,
                dns_vm_comment($machine, $ipAddress)
            );

            flash('success', 'DNS-запись сохранена: ' . $domainName . ' → ' . $ipAddress);
        } elseif ($action === 'save_manual_dns') {
            // Добавляет произвольную DNS-запись, не связанную с Proxmox.
            $domainName = trim((string) ($_POST['domain_name'] ?? ''));
            $ipAddress = trim((string) ($_POST['ip_address'] ?? ''));
            $comment = trim((string) ($_POST['comment'] ?? ''));

            $dnsService->saveRecord(
                $domainName,
                $ipAddress,
                dns_manual_comment($comment)
            );

            flash('success', 'DNS-запись сохранена: ' . $domainName . ' → ' . $ipAddress);
        } elseif ($action === 'delete') {
            // Удаляет DNS-запись по внутреннему ID MikroTik.
            $routerId = trim((string) ($_POST['router_id'] ?? ''));

            $dnsService->deleteRecordByRouterId($routerId);
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

// Загружает DNS-записи напрямую с MikroTik
try {
    $records = $dnsService->listRecords();
} catch (Throwable $e) {
    flash('error', 'Не удалось получить DNS-записи с MikroTik: ' . $e->getMessage());
}

$recordsByMachine = [];

// Строит быстрый индекс DNS-записей по ключу node:vmid:type из комментария MikroTik
foreach ($records as $record) {
    $key = DnsService::getRecordMachineKey($record);

    if ($key !== null) {
        $recordsByMachine[$key] = $record;
    }
}

$defaultZone = (string) ($config['dns']['default_zone'] ?? 'vnii.local');

require INCLUDES_PATH . '/header.php';
?>

<section class="card">
    <h2>Активные машины Proxmox</h2>
    <p class="muted">
        Важно: для добавления DNS-записи необходимо сначала включить виртуалку.
        IP-адрес будет получен автоматически через QEMU Guest Agent.
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

                    $domain = $existing['name']
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
                                <input type="hidden" name="status" value="<?= e((string) ($machine['status'] ?? 'unknown')) ?>">
                                <input type="hidden" name="cpus" value="<?= e((string) ($machine['cpus'] ?? '')) ?>">
                                <input type="hidden" name="maxmem" value="<?= e((string) ($machine['maxmem'] ?? '0')) ?>">

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
                                <button class="btn" type="submit" name="action" value="save_vm_dns">
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
    <h2>Добавить произвольную DNS-запись</h2>
    <p class="muted">
        Этот блок можно использовать для адресов не из Proxmox:
        например для самого сайта LK, NAS, принтера или другого локального сервиса.
    </p>

    <form method="post">
        <?= csrf_field() ?>

        <label for="manual_domain_name">DNS-имя</label>
        <input
            id="manual_domain_name"
            type="text"
            name="domain_name"
            placeholder="lk.<?= e($defaultZone) ?>"
            required
        >

        <label for="manual_ip_address">IPv4-адрес</label>
        <input
            id="manual_ip_address"
            type="text"
            name="ip_address"
            placeholder="192.168.1.10"
            required
        >

        <label for="manual_comment">Комментарий</label>
        <input
            id="manual_comment"
            type="text"
            name="comment"
            placeholder="Сайт LK"
        >

        <button class="btn" type="submit" name="action" value="save_manual_dns">
            Добавить DNS-запись
        </button>
    </form>
</section>

<section class="card">
    <h2>DNS-записи MikroTik</h2>
    <p class="muted">
        Список загружается напрямую с MikroTik из раздела <code>/ip dns static</code>.
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