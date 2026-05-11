<?php
// главная страница личного кабинета
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

$user = current_user();
$pageTitle = 'Главная';
$pageSubtitle = 'Главная внутренняя страница';
// получаем данные о заявке на VPN?????????
$stmt = db()->prepare('SELECT status, created_at, reviewed_at FROM vpn_requests WHERE user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $user['id']]);
$vpnRequest = $stmt->fetch();
// подключаем шаблон header.php
require INCLUDES_PATH . '/header.php';
?>
<div class="grid">
    <section class="card">
        <h2>Профиль</h2>
        <p><strong>Имя:</strong> <?= e(user_display_name($user)) ?></p>
        <p><strong>Email:</strong> <?= e($user['email']) ?></p>
        <p><strong>Роль:</strong> <?= e($user['role']) ?></p>
        <p>
            <strong>Привязка Яндекс:</strong> 
            <?php if (!empty($user['yandex_id'])): ?>
                <span class="badge badge-approved">Привязан</span>
            <?php else: ?>
                <span class="badge badge-pending">Не привязан</span>
                <a href="<?= e(url('auth/yandex/start.php')) ?>" class="btn btn-secondary" style="padding: 2px 10px; margin-left: 10px; text-decoration: none; font-size: 0.9em;">Привязать Яндекс</a>
            <?php endif; ?>
        </p>
    </section>
    <!--<section class="card">???????????????
        <h2>VPN модуль</h2>
        <?php if ($vpnRequest): ?>
            <p><strong>Статус заявки:</strong>
                <span class="badge badge-<?= e($vpnRequest['status']) ?>"><?= e($vpnRequest['status']) ?></span>
            </p>
            <p class="muted">Создана: <?= e((string) $vpnRequest['created_at']) ?></p>
        <?php else: ?>
            <p>Заявка на VPN еще не подавалась.</p>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('vpn/index.php')) ?>">Открыть VPN</a>
    </section>!-->
</div>
<section class="card">
    <h2>Пусто</h2>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
