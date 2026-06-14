<?php

declare(strict_types=1);

// Возвращает текущего авторизованного пользователя или null для гостя.
function current_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }
    //извлечение идентификатора
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        $user = null;
        return $user;
    }
    //запрос в БД на получение
    $stmt = db()->prepare('
        SELECT id, first_name, last_name, username, email, yandex_id, role, is_active, created_at
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch() ?: null;
    // какой статус у пользователя
    if ($user && (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        $user = null;
    }

    return $user;
}

// Проверяет, что текущий посетитель не авторизован.
function is_guest(): bool
{
    return current_user() === null;
}

// Проверяет, что у текущего авторизованного пользователя роль администратора.
function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

// Требует авторизацию и перенаправляет гостей на страницу входа.
function require_auth(): void
{
    if (is_guest()) {
        flash('error', 'Сначала войдите в аккаунт.');
        redirect('auth/login.php');
    }
}

// Требует роль администратора и перенаправляет обычных пользователей.
function require_admin(): void
{
    require_auth();
    if (!is_admin()) {
        flash('error', 'У вас нет доступа к этому разделу.');
        redirect('mainmenu/dashboard.php');
    }
}

// Выполняет вход пользователя, сохраняя его id в сессии.
function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

// Выполняет выход текущего пользователя и очищает данные сессии.
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
