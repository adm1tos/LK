<?php
// callback после авторизации через Яндекс
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

try {
    $error = trim((string) ($_GET['error'] ?? ''));

    if ($error !== '') {
        $description = trim((string) ($_GET['error_description'] ?? ''));
        throw new RuntimeException($description !== '' ? $description : 'Пользователь отменил авторизацию.');
    }

    $code = trim((string) ($_GET['code'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? ''));

    if ($code === '') {
        throw new RuntimeException('Яндекс не вернул code.');
    }

    $expectedState = (string) ($_SESSION['yandex_oauth_state'] ?? '');

    unset($_SESSION['yandex_oauth_state'], $_SESSION['yandex_oauth_mode']);

    if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
        throw new RuntimeException('Некорректный state. Попробуй войти снова.');
    }

    $service = new YandexAuthService($config);

    $token = $service->exchangeCodeForToken($code);
    $profile = $service->getUserInfo((string) $token['access_token']);

    $yandexId = trim((string) ($profile['id'] ?? ''));
    $email = strtolower(trim((string) ($profile['default_email'] ?? $profile['email'] ?? '')));
    $firstName = trim((string) ($profile['first_name'] ?? ''));
    $lastName = trim((string) ($profile['last_name'] ?? ''));
    $displayName = trim((string) ($profile['display_name'] ?? ''));
    $login = trim((string) ($profile['login'] ?? ''));

    if ($yandexId === '') {
        throw new RuntimeException('Яндекс не вернул ID пользователя.');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Яндекс не вернул корректный email. Проверь разрешения приложения.');
    }

    if ($firstName === '') {
        $firstName = $displayName !== '' ? $displayName : ($login !== '' ? $login : 'Пользователь');
    }

    $currentUser = current_user();

    if ($currentUser !== null) {
        $stmt = db()->prepare('SELECT id FROM users WHERE yandex_id = :yandex_id AND id != :current_id LIMIT 1');
        $stmt->execute([
            'yandex_id' => $yandexId,
            'current_id' => $currentUser['id'],
        ]);

        if ($stmt->fetch()) {
            throw new RuntimeException('Этот аккаунт Яндекс уже привязан к другому пользователю.');
        }

        $stmt = db()->prepare('
            UPDATE users 
            SET yandex_id = :yandex_id, yandex_email = :yandex_email, yandex_linked_at = NOW() 
            WHERE id = :id
        ');
        $stmt->execute([
            'yandex_id' => $yandexId,
            'yandex_email' => $email,
            'id' => $currentUser['id'],
        ]);

        $logger = new LoggerService(db());
        $logger->log('link_yandex', 'user', (int) $currentUser['id'], 'Привязка аккаунта Яндекс');

        flash('success', 'Аккаунт Яндекс успешно привязан.');
        redirect('mainmenu/dashboard.php');
    }

    // 1. Если Яндекс уже привязан к пользователю — входим.
    $stmt = db()->prepare('
        SELECT id, is_active
        FROM users
        WHERE yandex_id = :yandex_id
        LIMIT 1
    ');

    $stmt->execute([
        'yandex_id' => $yandexId,
    ]);

    $linkedUser = $stmt->fetch();

    if ($linkedUser) {
        if ((int) ($linkedUser['is_active'] ?? 1) !== 1) {
            throw new RuntimeException('Учётная запись отключена.');
        }

        // Логируем вход через Яндекс
        $logger = new LoggerService(db());
        $logger->log('login_yandex', 'user', $linkedUser['id'], 'Вход через Яндекс OAuth');

        login_user((int) $linkedUser['id']);
        flash('success', 'Вход через Яндекс выполнен.');
        redirect('mainmenu/dashboard.php');
    }

    // 2. Если email уже есть, но Яндекс не привязан — не привязываем автоматически.
    $stmt = db()->prepare('
        SELECT id
        FROM users
        WHERE email = :email
        LIMIT 1
    ');

    $stmt->execute([
        'email' => $email,
    ]);

    $existingEmailUser = $stmt->fetch();

    if ($existingEmailUser) {
        flash(
            'error',
            'Пользователь с такой почтой уже существует. Войди обычным способом и позже привяжи Яндекс в профиле.'
        );

        redirect('auth/login.php');
    }

    // 3. Если пользователя нет — создаём нового.
    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $pdo = db();

    $stmt = $pdo->prepare('
        INSERT INTO users (
            first_name,
            last_name,
            username,
            email,
            yandex_id,
            yandex_email,
            yandex_linked_at,
            password_hash,
            role,
            is_active
        )
        VALUES (
            :first_name,
            :last_name,
            :username,
            :email,
            :yandex_id,
            :yandex_email,
            NOW(),
            :password_hash,
            :role,
            :is_active
        )
    ');

    $stmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'username' => $email,
        'email' => $email,
        'yandex_id' => $yandexId,
        'yandex_email' => $email,
        'password_hash' => $passwordHash,
        'role' => 'user',
        'is_active' => 1,
    ]);

    $newUserId = (int) $pdo->lastInsertId();

    if ($newUserId <= 0) {
        throw new RuntimeException('Не удалось получить ID созданного пользователя.');
    }

    // Логируем регистрацию через Яндекс
    $logger = new LoggerService(db());
    $logger->log('register_yandex', 'user', $newUserId, 'Регистрация через Яндекс OAuth');

    login_user($newUserId);

    flash('success', 'Аккаунт создан через Яндекс. Добро пожаловать в LK.');
    redirect('mainmenu/dashboard.php');
} catch (Throwable $e) {
    flash('error', 'Ошибка авторизации через Яндекс: ' . $e->getMessage());
    redirect('auth/login.php');
}