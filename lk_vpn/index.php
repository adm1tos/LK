<?php
// отвечает за VPN и конфиг или генерации нового конфига
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

// Подготавливает данные пользователя и заголовок страницы VPN
$user = current_user();
$pageTitle = 'VPN';
$pageSubtitle = 'Генерация конфигов';

// Загружает текущую заявку пользователя на VPN
$stmt = db()->prepare('SELECT * FROM vpn_requests WHERE user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $user['id']]);
$request = $stmt->fetch();

// Загружает уже выданный VPN-конфиг пользователя (если есть).
$stmt = db()->prepare('SELECT * FROM vpn_configs WHERE user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $user['id']]);
$configRow = $stmt->fetch();
//var_dump(extension_loaded('sodium')); //проверка работы расширения sodium, если не работает - выдаст false, если работает - выдаст true
require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <h2>Текущий статус</h2>
    <?php if (!$request): ?>
        <!-- Показывает форму подачи заявки, если заявка еще не создавалась. -->
        <p>У тебя еще нет заявки на VPN.</p>
        <form method="post" action="<?= e(url('vpn/request.php')) ?>">
            <?= csrf_field() ?>
            <button class="btn" type="submit">Подать заявку</button>
        </form>
    <?php else: ?>
        <p><strong>Статус заявки:</strong>
            <span class="badge badge-<?= e($request['status']) ?>"><?= e($request['status']) ?></span>
        </p>
        <p><strong>Создана:</strong> <?= e((string) $request['created_at']) ?></p>
        <?php if (!empty($request['reviewed_at'])): ?>
            <p><strong>Рассмотрена:</strong> <?= e((string) $request['reviewed_at']) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Конфиг</h2>
    <?php if (($request['status'] ?? null) !== 'approved'): ?>
        <!--До одобрения заявки админом доступ к конфигу закрыт-->
        <p>Конфиг будет доступен после одобрения заявки администратором.</p>
    <?php else: ?>
        <?php if ($configRow): ?>
            <!--Для существующего конфига доступны скачивание и перегенерация-->
            <p><strong>IP:</strong> <?= e((string) $configRow['ip_address']) ?></p>
            <p><strong>Public key:</strong> <?= e((string) $configRow['public_key']) ?></p>
            <p><strong>Конфиг уже выдавался:</strong> <?= (int) $configRow['has_config'] === 1 ? 'Да' : 'Нет' ?></p>
            <div class="btn-row">
                <a class="btn btn-secondary" href="<?= e(url('vpn/download.php')) ?>">Скачать текущий .conf</a>
                <form method="post" action="<?= e(url('vpn/generate.php')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn" type="submit">Перегенерировать конфиг</button>
                </form>
            </div>
        <?php else: ?>
            <!--Если конфига еще нет, можно создать его впервые-->
            <p>Конфиг еще не создавался.</p>
            <form method="post" action="<?= e(url('vpn/generate.php')) ?>">
                <?= csrf_field() ?>
                <button class="btn" type="submit">Сгенерировать конфиг</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
