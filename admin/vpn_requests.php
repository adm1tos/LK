<?php
// страница управления заявками на VPN
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$admin = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
// получаем данные для управления заявками
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';
// если данные введены, обновляем статус заявки
    if ($requestId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
// обновляем статус заявки в базе данных
        $stmt = db()->prepare('UPDATE vpn_requests SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'reviewed_by' => $admin['id'],
            'id' => $requestId,
        ]);
// записываем в лог изменения статуса заявки через LoggerService
        $logger = new LoggerService(db());
        $logger->log(
            'vpn_request_' . $status,
            'vpn_request',
            $requestId,
            'Статус заявки изменен на ' . $status
        );
        flash('success', 'Статус заявки обновлен.');
    }

    redirect('admin/vpn_requests.php');
}

$pageTitle = 'VPN заявки';
$pageSubtitle = 'Администрирование заявок пользователей.';
// получаем список заявок на VPN
$stmt = db()->query('
    SELECT
        vr.id,
        vr.status,
        vr.created_at,
        vr.reviewed_at,
        u.first_name,
        u.last_name,
        u.username,
        u.email,
        reviewer.first_name AS reviewer_first_name,
        reviewer.last_name AS reviewer_last_name,
        reviewer.username AS reviewer_username
    FROM vpn_requests vr
    INNER JOIN users u ON u.id = vr.user_id
    LEFT JOIN users reviewer ON reviewer.id = vr.reviewed_by
    ORDER BY FIELD(vr.status, "pending", "approved", "rejected"), vr.created_at DESC
');
$requests = $stmt->fetchAll();

require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Пользователь</th>
                <th>Email</th>
                <th>Статус</th>
                <th>Создана</th>
                <th>Проверил</th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td><?= e((string) $request['id']) ?></td>
                    <td><?= e(user_display_name($request)) ?></td>
                    <td><?= e($request['email']) ?></td>
                    <td><span class="badge badge-<?= e($request['status']) ?>"><?= e($request['status']) ?></span></td>
                    <td><?= e((string) $request['created_at']) ?></td>
                    <td>
                        <?php
                        $reviewerName = trim((string) ($request['reviewer_first_name'] ?? '') . ' ' . (string) ($request['reviewer_last_name'] ?? ''));
                        echo e($reviewerName !== '' ? $reviewerName : ($request['reviewer_username'] ?? '—'));
                        ?>
                    </td>
                    <td>
                        <?php if ($request['status'] === 'pending'): ?>
                            <form class="inline-form" method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="request_id" value="<?= e((string) $request['id']) ?>">
                                <button class="btn" type="submit" name="action" value="approve">Approve</button>
                                <button class="btn btn-danger" type="submit" name="action" value="reject">Reject</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Нет действий</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
