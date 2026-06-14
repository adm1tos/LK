<?php
// сервис для авторизации через Yandex OAuth
declare(strict_types=1);

final class YandexAuthService
{
    private const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';
    private const TOKEN_URL = 'https://oauth.yandex.ru/token';
    private const USER_INFO_URL = 'https://login.yandex.ru/info?format=json';

    public function __construct(
        private readonly array $config
    ) {
    }

    // Формирует ссылку, на которую пользователь будет отправлен для входа через Яндекс
    public function buildAuthorizeUrl(string $state): string
    {   
        //

        $clientId = trim((string) ($this->config['yandex']['client_id'] ?? ''));
        $redirectUri = trim((string) ($this->config['yandex']['redirect_uri'] ?? ''));
        //если ссылка не указана
        if ($clientId === '' || $redirectUri === '') {
            throw new RuntimeException('Не настроены параметры Yandex OAuth.');
        }
        // сбор ссылки с айди и ссылкой из .env
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    // Обменивает code на OAuth-токен
    public function exchangeCodeForToken(string $code): array
    {
        $clientId = trim((string) ($this->config['yandex']['client_id'] ?? ''));
        $clientSecret = trim((string) ($this->config['yandex']['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Не настроены Client ID или Client Secret для Yandex OAuth.');
        }

        $response = $this->postForm(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Яндекс не вернул access_token.');
        }

        return $response;
    }

    // Получает профиль пользователя Яндекса по OAuth-токену
    public function getUserInfo(string $accessToken): array
    {
        $ch = curl_init(self::USER_INFO_URL);

        //успешно ли создался cURL дескриптор
        if (!$ch) {
            throw new RuntimeException('Не удалось инициализировать curl.');
        }
        // параметры запроса время таймаут
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: OAuth ' . $accessToken,
            ],
        ]);
        //запрос к яндексу
        $raw = curl_exec($ch);
        //ошибки?
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new RuntimeException('Ошибка запроса профиля Яндекса: ' . $error);
        }
        // получили ответ
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // закрыли сессию
        curl_close($ch);
        // расшифровали ответ в массив
        $data = json_decode((string) $raw, true);

        if ($statusCode < 200 || $statusCode >= 300 || !is_array($data)) {
            throw new RuntimeException('Яндекс вернул некорректный ответ профиля.');
        }

        return $data;
    }

    // Выполняет POST application/x-www-form-urlencoded
    private function postForm(string $url, array $data): array
    {
        $ch = curl_init($url);

        if (!$ch) {
            throw new RuntimeException('Не удалось инициализировать curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw = curl_exec($ch);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new RuntimeException('Ошибка запроса к Яндексу: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);

        if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
            throw new RuntimeException('Яндекс вернул ошибку при обмене code на token.');
        }

        return $decoded;
    }
}