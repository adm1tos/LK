<?php
// старт авторизации через Яндекс
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

try {
    $state = bin2hex(random_bytes(32));
    // регистрация или привязка к уже имеющейся Учётке
    $_SESSION['yandex_oauth_state'] = $state;
    // 
    $_SESSION['yandex_oauth_mode'] = current_user() ? 'link' : 'login';

    $service = new YandexAuthService($config);
    $url = $service->buildAuthorizeUrl($state);

    header('Location: ' . $url);
    exit;
} catch (Throwable $e) {
    flash('error', 'Ошибка запуска авторизации через Яндекс: ' . $e->getMessage());
    redirect('auth/login.php');
}