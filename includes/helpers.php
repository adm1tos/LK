<?php

declare(strict_types=1);

// Экранирует текст для безопасного вывода в HTML.
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Формирует абсолютный URL из относительного пути для удобства
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    if ($path === '') {
        return BASE_URL === '' ? '/' : BASE_URL . '/';
    }

    return (BASE_URL === '' ? '' : BASE_URL) . '/' . $path;
}

//Перенаправляет браузер на указанный путь и завершает выполнение скрипта
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}


// Сохраняет или читает одноразовое сообщение в сессии
function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }

    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $message;
}
//показ имени
function user_display_name(array $user): string
{
    $firstName = trim((string) ($user['first_name'] ?? ''));
    $lastName = trim((string) ($user['last_name'] ?? ''));

    $fullName = trim($firstName . ' ' . $lastName);

    if ($fullName !== '') {
        return $fullName;
    }

    return (string) ($user['email'] ?? $user['username'] ?? 'Пользователь');
}