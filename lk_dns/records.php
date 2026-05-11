<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

$pageTitle = 'Мои DNS-записи';
$pageSubtitle = 'Просмотр и удаление DNS-записей, созданных вами через LK.';

$mikrotik = new MikrotikService($config);
$dnsService = new DnsService(db(), $mikrotik);

$user = current_user();

if (!$user) {
    throw new RuntimeException('Пользователь не найден.');
}

$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'delete_user_dns') {
            $recordId = (int) ($_POST['record_id'] ?? 0);

            if ($recordId <= 0) {
                throw new RuntimeException('Не выбрана DNS-запись.');
            }

            // Получаем информацию о записи перед удалением для логирования
            $stmt = db()->prepare('SELECT domain_name, ip_address FROM dns_records WHERE id = :id AND created_by = :user_id LIMIT 1');
            $stmt->execute(['id' => $recordId, 'user_id' => $userId]);
            $recordInfo = $stmt->fetch();

            $dnsService->deleteUserRecord($recordId, $userId);

            // Логируем удаление DNS-записи
            if ($recordInfo) {
                $logger = new LoggerService(db());
                $logger->log(
                    'delete',
                    'dns_record',
                    $recordId,
                    sprintf('Удалена DNS-запись: %s → %s', $recordInfo['domain_name'], $recordInfo['ip_address'])
                );
            }

            flash('success', 'DNS-запись удалена.');
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('lk_dns/records.php');
}

$userRecords = [];

try {
    $userRecords = $dnsService->listUserRecords($userId);
} catch (Throwable $e) {
    flash('error', 'Не удалось получить ваши DNS-записи: ' . $e->getMessage());
}

require INCLUDES_PATH . '/header.php';
?>

<section class="card">
    <div class="btn-row">
        <a class="btn btn-secondary" href="<?= e(url('lk_dns/index.php')) ?>">
            Добавить DNS-запись
        </a>
    </div>
</section>

<section class="card">
    <h2>Мои DNS-записи</h2>
    <p class="muted">
        Здесь отображаются только DNS-записи, которые были добавлены вами через LK.
        При удалении запись удаляется и из базы LK, и с MikroTik.
    </p>

    <?php if (!$userRecords): ?>
        <p>У вас пока нет DNS-записей.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Домен</th>
                    <th>IP</th>
                    <th>Тип</th>
                    <th>Описание</th>
                    <th>Создана</th>
                    <th>Действие</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($userRecords as $record): ?>
                    <tr>
                        <td><code><?= e((string) $record['domain_name']) ?></code></td>
                        <td><?= e((string) $record['ip_address']) ?></td>
                        <td>
                            <?php if (($record['source'] ?? '') === 'proxmox'): ?>
                                VM
                            <?php else: ?>
                                Произвольная
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($record['source'] ?? '') === 'proxmox'): ?>
                                <?= e((string) ($record['machine_name'] ?? 'VM')) ?>
                                <span class="muted">
                                    VMID <?= e((string) ($record['vmid'] ?? '')) ?>
                                </span>
                            <?php else: ?>
                                <?= e((string) ($record['record_comment'] ?? '—')) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($record['created_at'] ?? '—')) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Удалить DNS-запись?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="record_id" value="<?= e((string) $record['id']) ?>">
                                <button class="btn btn-danger" type="submit" name="action" value="delete_user_dns">
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