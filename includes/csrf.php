<?php
// используется для защиты от CSRF-атак
declare(strict_types=1);

// Возвращает текущий CSRF-токен, создавая его один раз на сессию.
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

// Генерирует скрытое поле формы с CSRF-токеном.
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// Проверяет CSRF-токен из POST-запроса на совпадение с токеном в сессии.
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['_csrf_token'] ?? '';

    if (!$token || !$sessionToken || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        exit('Invalid CSRF token');
    }
}
