<?php
//ssh/index.php - Этот файл отвечает за отображение страницы управления SSH-ключами пользователя, а также обработку добавления и удаления ключей.
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_auth();

// Подготавливает данные текущего пользователя и заголовок страницы.
$user = current_user();
$pageTitle = 'SSH-ключи';
$pageSubtitle = 'Добавление и управление вашими публичными SSH-ключами.';


// Обрабатывает добавление и удаление SSH-ключей пользователя.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add') {
            // Валидирует имя и формат публичного SSH-ключа перед сохранением.
            $keyName = trim((string) ($_POST['key_name'] ?? ''));
            $publicKey = trim((string) ($_POST['public_key'] ?? ''));

            if ($keyName === '') {
                throw new RuntimeException('Укажи название ключа.');
            }

            if (mb_strlen($keyName) > 150) {
                throw new RuntimeException('Название ключа слишком длинное.');
            }

            if ($publicKey === '') {
                throw new RuntimeException('Нет SSH');
            }

            if (mb_strlen($publicKey) > 5000) {
                throw new RuntimeException('SSH-ключ слишком длинный.');
            }

            $publicKey = preg_replace('/\s+/', ' ', $publicKey) ?? $publicKey;
            $publicKey = trim($publicKey);

            $isValidKey = preg_match(
                '/^(ssh-(rsa|ed25519)|ecdsa-sha2-nistp(256|384|521))\s+[A-Za-z0-9+\/=]+(?:\s+.+)?$/',
                $publicKey
            ) === 1;

            if (!$isValidKey) {
                throw new RuntimeException('Некорректный формат.');
            }

            // Проверяет, что такой же ключ еще не добавлен этому пользователю.
            $check = db()->prepare('SELECT id FROM ssh_keys WHERE user_id = :user_id AND public_key = :public_key LIMIT 1');
            $check->execute([
                'user_id' => $user['id'],
                'public_key' => $publicKey,
            ]);

            if ($check->fetch()) {
                throw new RuntimeException('Такой ключ уже добавлен.');
            }

            // Сохраняет новый SSH-ключ в таблицу пользователя.
            $stmt = db()->prepare('INSERT INTO ssh_keys (user_id, key_name, public_key) VALUES (:user_id, :key_name, :public_key)');
            $stmt->execute([
                'user_id' => $user['id'],
                'key_name' => $keyName,
                'public_key' => $publicKey,
            ]);

            flash('success', 'SSH-ключ добавлен.');
        } elseif ($action === 'delete') {
            // Удаляет выбранный SSH-ключ, если он принадлежит текущему пользователю.
            $keyId = (int) ($_POST['key_id'] ?? 0);

            if ($keyId <= 0) {
                throw new RuntimeException('Не передан идентификатор ключа.');
            }

            $stmt = db()->prepare('DELETE FROM ssh_keys WHERE id = :id AND user_id = :user_id');
            $stmt->execute([
                'id' => $keyId,
                'user_id' => $user['id'],
            ]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Ключ не найден или у тебя нет прав на его удаление.');
            }

            flash('success', 'SSH-ключ удалён.');
        } else {
            throw new RuntimeException('Неизвестное действие.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('ssh/index.php');
}

// Загружает список SSH-ключей текущего пользователя для таблицы.
$stmt = db()->prepare('SELECT id, key_name, public_key, created_at FROM ssh_keys WHERE user_id = :user_id ORDER BY created_at DESC, id DESC');
$stmt->execute(['user_id' => $user['id']]);
$keys = $stmt->fetchAll();

require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <h2>Добавить новый SSH-ключ</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">

        <label for="key_name">Название ключа</label>
        <input id="key_name" name="key_name" placeholder="" maxlength="150" required>

        <label for="public_key">Публичный ключ</label>
        <textarea id="public_key" name="public_key" rows="6" placeholder="ssh-ed25519 ... user@host" required></textarea>

        <button class="btn" type="submit">Добавить ключ</button>
    </form>
</section>

<section class="card">
    <h2>Мои SSH-ключи</h2>
<div class="btn-row">
    <a class="btn btn-secondary" href="<?= e(url('ssh/machines.php')) ?>">Машины для привязки ключей...</a>
</div>
    <?php if (!$keys): ?>
        <p>У тебя пока нет добавленных SSH-ключей.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Название</th>
                    <th>Ключ</th>
                    <th>Создан</th>
                    <th>Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $key): ?>
                    <tr>
                        <td><?= e((string) $key['key_name']) ?></td>
                        <td>
                            <div style="display:flex; gap:8px; align-items:flex-start; min-width:420px;">
                                <textarea readonly rows="3" style="margin-bottom:0; resize:vertical; font-family:monospace;"><?= e((string) $key['public_key']) ?></textarea>
                                <button
                                    class="btn btn-secondary"
                                    type="button"
                                    data-copy-key="<?= e((string) $key['public_key']) ?>"
                                >Копировать</button>
                            </div>
                        </td>
                        <td><?= e((string) $key['created_at']) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Удалить этот SSH-ключ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="key_id" value="<?= e((string) $key['id']) ?>">
                                <button class="btn btn-danger" type="submit">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
// Копирует SSH-ключ в буфер обмена по кнопке "Копировать".
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-copy-key]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const key = button.getAttribute('data-copy-key') || '';
            if (!key) return;

            try {
                await navigator.clipboard.writeText(key);
                const originalText = button.textContent;
                button.textContent = 'Скопировано';
                setTimeout(function () {
                    button.textContent = originalText;
                }, 1200);
            } catch (e) {
                alert('Не удалось скопировать ключ.');
            }
        });
    });
});
</script>
<?php require INCLUDES_PATH . '/footer.php'; ?>
