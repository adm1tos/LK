<?php
// Страница управления пирами с роутера

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$admin = current_user();
$mikrotik = new MikrotikService($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
// получаем данные для управления пирами
    $peerId = trim((string) ($_POST['peer_id'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');
    $currentDisabled = (string) ($_POST['current_disabled'] ?? 'false');

//тут происходит управление пирами через MikroTikService
    try {
        if ($peerId === '') {
            throw new RuntimeException('Не передан идентификатор пира.');
        }
//
        if ($action === 'toggle') {
            $isCurrentlyDisabled = in_array(strtolower($currentDisabled), ['true', 'yes', '1'], true);
            $newDisabled = !$isCurrentlyDisabled;
            $mikrotik->togglePeerById($peerId, $newDisabled);
// записываем в лог изменения статуса пира
            $log = db()->prepare('INSERT INTO admin_logs (admin_id, action_type, target_type, details) VALUES (:admin_id, :action_type, :target_type, :details)');
            $log->execute([
                'admin_id' => $admin['id'],
                'action_type' => 'vpn_peer_toggle',
                'target_type' => 'mikrotik_peer',
                'details' => 'peer_id=' . $peerId . '; disabled=' . ($newDisabled ? '1' : '0'),
            ]);

            flash('success', $newDisabled ? 'Пир отключен.' : 'Пир включен.');
        } elseif ($action === 'delete') {
            $mikrotik->deletePeerById($peerId);
// записываем в лог удаление пира
            $log = db()->prepare('INSERT INTO admin_logs (admin_id, action_type, target_type, details) VALUES (:admin_id, :action_type, :target_type, :details)');
            $log->execute([
                'admin_id' => $admin['id'],
                'action_type' => 'vpn_peer_delete',
                'target_type' => 'mikrotik_peer',
                'details' => 'peer_id=' . $peerId,
            ]);

            flash('success', 'Пир удален.');
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $e) {
        flash('error', 'Ошибка при работе с MikroTik: ' . $e->getMessage());
    }

    redirect('admin/vpn_peers.php');
}

$pageTitle = 'VPN пиры';
$pageSubtitle = 'Список WireGuard-пиров на MikroTik и быстрые действия.';
// получаем список пиров 
$peers = [];
try {
    $peers = $mikrotik->getPeers();

    usort($peers, static function (array $a, array $b): int {
        return strcmp((string) ($a['interface'] ?? ''), (string) ($b['interface'] ?? ''))
            ?: strcmp((string) ($a['comment'] ?? ''), (string) ($b['comment'] ?? ''));
    });
} catch (Throwable $e) {
    flash('error', 'Не удалось получить список пиров с MikroTik: ' . $e->getMessage());
}

require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <h2>Пиры WireGuard</h2>
    <p class="muted">Данные берутся напрямую с роутера MikroTik.</p>

    <?php if (!$peers): ?>
        <p>Пиры не найдены или роутер не вернул список.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>.id</th>
                    <th>Интерфейс</th>
                    <th>Public key</th>
                    <th>Allowed address</th>
                    <th>Комментарий</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($peers as $peer): ?>
                    <?php
                    $peerId = (string) ($peer['.id'] ?? '');
                    $publicKey = (string) ($peer['public-key'] ?? '—');
                    $allowedAddress = (string) ($peer['allowed-address'] ?? '—');
                    $comment = (string) ($peer['comment'] ?? '');
                    $interface = (string) ($peer['interface'] ?? '—');
                    $disabledRaw = strtolower((string) ($peer['disabled'] ?? 'false'));
                    $isDisabled = in_array($disabledRaw, ['true', 'yes', '1'], true);
                    ?>
                    <tr>
                        <td><code><?= e($peerId) ?></code></td>
                        <td><?= e($interface) ?></td>
                        <td><code><?= e($publicKey) ?></code></td>
                        <td><code><?= e($allowedAddress) ?></code></td>
                        <td><?= e($comment !== '' ? $comment : '—') ?></td>
                        <td>
                            <span class="badge <?= $isDisabled ? 'badge-rejected' : 'badge-approved' ?>">
                                <?= $isDisabled ? 'disabled' : 'enabled' ?>
                            </span>
                        </td>
                        <td>
                            <form class="inline-form" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="peer_id" value="<?= e($peerId) ?>">
                                <input type="hidden" name="current_disabled" value="<?= e($disabledRaw) ?>">
                                <button class="btn btn-secondary" type="submit" name="action" value="toggle">
                                    <?= $isDisabled ? 'Включить' : 'Выключить' ?>
                                </button>
                                <button class="btn btn-danger" type="submit" name="action" value="delete" onclick="return confirm('Удалить этого пира с MikroTik?');">
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
