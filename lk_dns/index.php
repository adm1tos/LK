<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

// Подготавливает зависимости для получения VM, работы с роутером и DNS-записями
$pageTitle = 'DNS';
$pageSubtitle = 'Назначение DNS-записей через MikroTik.';

$proxmox = new ProxmoxService($config);
$mikrotik = new MikrotikService($config);
$dnsService = new DnsService(db(), $mikrotik);

$user = current_user();

if (!$user) {
    throw new RuntimeException('Пользователь не найден.');
}

$userId = (int) $user['id'];

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

// Формирует комментарий для DNS-записи, созданной по VM из Proxmox
function dns_vm_comment(array $machine): string
{
    $vmid = (int) ($machine['vmid'] ?? 0);
    $name = (string) ($machine['name'] ?? ('vm-' . $vmid));
    $node = (string) ($machine['node'] ?? '');
    $type = (string) ($machine['type'] ?? 'qemu');

    return sprintf(
        'LK DNS; source=proxmox; node=%s; vmid=%d; type=%s; name=%s',
        $node,
        $vmid,
        $type,
        $name
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

// Обрабатывает сохранение DNS-записей из формы
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

            // Получает IP машины из Proxmox Guest Agent для последующего DNS.
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
            ];

            // Если у этой VM уже была DNS-запись с другим именем, удаляет старую запись с MikroTik.
            $dnsService->deleteProxmoxRecordForMachineIfDomainChanged(
                $node,
                $vmid,
                $type,
                $domainName,
                $userId
            );

            // Сохраняет или обновляет static DNS-запись на MikroTik и запись в БД.
            $dnsService->saveProxmoxRecord(
                $node,
                $vmid,
                $type,
                $machineName,
                $domainName,
                $ipAddress,
                dns_vm_comment($machine),
                $userId
            );

            // Получаем ID записи для логирования
            $stmt = db()->prepare('SELECT id FROM dns_records WHERE domain_name = :domain_name AND created_by = :user_id LIMIT 1');
            $stmt->execute(['domain_name' => $domainName, 'user_id' => $userId]);
            $record = $stmt->fetch();
            $recordId = $record ? (int) $record['id'] : null;

            // Логируем создание DNS-записи
            $logger = new LoggerService(db());
            $logger->log(
                'create',
                'dns_record',
                $recordId,
                sprintf('Создана DNS-запись: %s → %s (VM: %s, VMID: %d)', $domainName, $ipAddress, $machineName, $vmid)
            );

            flash('success', 'DNS-запись сохранена: ' . $domainName . ' → ' . $ipAddress);
        } elseif ($action === 'save_manual_dns') {
            // Добавляет произвольную DNS-запись, не связанную с Proxmox.
            $domainName = trim((string) ($_POST['domain_name'] ?? ''));
            $ipAddress = trim((string) ($_POST['ip_address'] ?? ''));
            $comment = trim((string) ($_POST['comment'] ?? ''));

            $dnsService->saveManualRecord(
                $domainName,
                $ipAddress,
                dns_manual_comment($comment),
                $userId
            );

            // Получаем ID записи для логирования
            $stmt = db()->prepare('SELECT id FROM dns_records WHERE domain_name = :domain_name AND created_by = :user_id LIMIT 1');
            $stmt->execute(['domain_name' => $domainName, 'user_id' => $userId]);
            $record = $stmt->fetch();
            $recordId = $record ? (int) $record['id'] : null;

            // Логируем создание DNS-записи
            $logger = new LoggerService(db());
            $logger->log(
                'create',
                'dns_record',
                $recordId,
                sprintf('Создана DNS-запись: %s → %s (вручную)', $domainName, $ipAddress)
            );

            flash('success', 'DNS-запись сохранена: ' . $domainName . ' → ' . $ipAddress);
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('lk_dns/index.php');
}

$machines = [];
$userRecords = [];

// Загружает список запущенных машин из Proxmox
try {
    $machines = array_values(array_filter(
        $proxmox->listActiveMachines(),
        static fn(array $machine): bool => ($machine['type'] ?? '') === 'qemu'
    ));
} catch (Throwable $e) {
    flash('error', 'Не удалось получить список VM из Proxmox: ' . $e->getMessage());
}

// Загружает DNS-записи текущего пользователя из БД
try {
    $userRecords = $dnsService->listUserRecords($userId);
} catch (Throwable $e) {
    flash('error', 'Не удалось получить ваши DNS-записи: ' . $e->getMessage());
}

$recordsByMachine = [];

// Строит быстрый индекс DNS-записей пользователя по ключу node:vmid:type
foreach ($userRecords as $record) {
    if (($record['source'] ?? '') !== 'proxmox') {
        continue;
    }

    $node = (string) ($record['node'] ?? '');
    $vmid = (int) ($record['vmid'] ?? 0);
    $type = (string) ($record['type'] ?? '');

    if ($node !== '' && $vmid > 0 && $type !== '') {
        $recordsByMachine[$node . ':' . $vmid . ':' . $type] = $record;
    }
}

$defaultZone = (string) ($config['dns']['default_zone'] ?? 'vnii.local');

require INCLUDES_PATH . '/header.php';
?>

<section class="card">
    <h2>Активные машины Proxmox</h2>
    <p class="muted">
        Важно! Для добавления DNS-записи необходимо сначала включить виртуалку.
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
        Можно использовать для адресов не из Proxmox: например для сайта LK, NAS, принтера или другого локального сервиса.
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
            placeholder="192.168.X.X"
            required
        >

        <label for="manual_comment">Комментарий</label>
        <input
            id="manual_comment"
            type="text"
            name="comment"
            placeholder="Описание"
        >

        <button class="btn" type="submit" name="action" value="save_manual_dns">
            Добавить DNS-запись
        </button>
    </form>
</section>

<section class="card">
    <div class="btn-row">
        <a class="btn btn-secondary" href="<?= e(url('lk_dns/records.php')) ?>">
            Мои DNS-записи
        </a>
    </div>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>