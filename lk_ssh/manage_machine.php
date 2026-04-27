<?php
// страница выбранной машины для добавление ssh в неё
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

require_once INCLUDES_PATH . '/services/VmSshService.php';

$node = trim((string) ($_GET['node'] ?? ''));
$vmid = (int) ($_GET['vmid'] ?? 0);
$type = trim((string) ($_GET['type'] ?? 'qemu'));

if ($node === '' || $vmid <= 0) {
    flash('error', 'Не выбрана машина.');
    redirect('ssh/machines.php');
}

$vmSsh = new VmSshService($config);

if (!$vmSsh->isManagedVm($node, $vmid, $type)) {
    flash('error', 'Для этой VM SSH-управление пока не настроено.');
    redirect('ssh/machines.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $linuxUser = trim((string) ($_POST['linux_user'] ?? ''));
    $sshKeyId = (int) ($_POST['ssh_key_id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($linuxUser === '' || $sshKeyId <= 0) {
            throw new RuntimeException('Выбери пользователя VM и SSH-ключ.');
        }

        $keyStmt = db()->prepare('
            SELECT id, key_name, public_key
            FROM ssh_keys
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
        ');
        $keyStmt->execute([
            'id' => $sshKeyId,
            'user_id' => current_user()['id'],
        ]);
        $key = $keyStmt->fetch();

        if (!$key) {
            throw new RuntimeException('SSH-ключ не найден.');
        }

        if ($action === 'add') {
            $vmSsh->addKey($node, $vmid, $type, $linuxUser, (string) $key['public_key']);
            flash('success', 'SSH-ключ добавлен пользователю ' . $linuxUser . '.');
        } elseif ($action === 'remove') {
            $vmSsh->removeKey($node, $vmid, $type, $linuxUser, (string) $key['public_key']);
            flash('success', 'SSH-ключ удалён у пользователя ' . $linuxUser . '.');
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }

        redirect('ssh/manage_machine.php?node=' . urlencode($node) . '&vmid=' . $vmid . '&type=' . urlencode($type));
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('ssh/manage_machine.php?node=' . urlencode($node) . '&vmid=' . $vmid . '&type=' . urlencode($type));
    }
}

#try {
#    $users = $vmSsh->listUsers($node, $vmid, $type);
#} catch (Throwable $e) {
#    flash('error', 'Не удалось получить список пользователей VM: ' . $e->getMessage());
#    $users = [];
#}
try {
    $users = $vmSsh->listUsers($node, $vmid, $type);
} catch (Throwable $e) {
    echo '<pre>';
    echo "Класс ошибки: " . get_class($e) . "\n";
    echo "Сообщение: " . ($e->getMessage() !== '' ? $e->getMessage() : '[пусто]') . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    echo '</pre>';
    exit;
}

$keyStmt = db()->prepare('
    SELECT id, key_name, public_key, created_at
    FROM ssh_keys
    WHERE user_id = :user_id
    ORDER BY created_at DESC
');
$keyStmt->execute(['user_id' => current_user()['id']]);
$keys = $keyStmt->fetchAll();

$pageTitle = 'Настроить SSH';
$pageSubtitle = 'VMID ' . $vmid . ', node ' . $node;

require INCLUDES_PATH . '/header.php';
?>

<section class="card">
    <div class="btn-row">
        <a class="btn btn-secondary" href="<?= e(url('ssh/machines.php')) ?>">Назад к машинам</a>
    </div>
</section>

<section class="card">
    <h2>Настройка SSH</h2>

    <p><strong>VMID:</strong> <?= e((string) $vmid) ?></p>
    <p><strong>Node:</strong> <?= e($node) ?></p>

    <form method="post">
        <?= csrf_field() ?>

        <label for="linux_user">Пользователь VM</label>
        <select id="linux_user" name="linux_user" required>
            <option value="">— Выбери пользователя —</option>
            <?php foreach ($users as $linuxUser): ?>
                <option value="<?= e($linuxUser) ?>"><?= e($linuxUser) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="ssh_key_id">SSH-ключ</label>
        <select id="ssh_key_id" name="ssh_key_id" required>
            <option value="">— Выбери ключ —</option>
            <?php foreach ($keys as $key): ?>
                <option value="<?= e((string) $key['id']) ?>">
                    <?= e($key['key_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="btn-row" style="margin-top: 16px;">
            <button class="btn" type="submit" name="action" value="add">Выдать доступ</button>
            <button class="btn btn-danger" type="submit" name="action" value="remove">Отозвать доступ</button>
        </div>
    </form>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>