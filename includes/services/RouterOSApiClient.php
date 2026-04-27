<?php
//для создания соединения с роутером, получения ответа и отправления команд
declare(strict_types=1);

final class RouterOSApiClient
{
    /** @var resource|null */
    private $socket = null;

    // Сохраняет параметры подключения к MikroTik API.
    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password,
        private readonly int $port = 8728,
        private readonly bool $useSsl = false,
        private readonly int $timeout = 10,
    ) {
    }

    // Открывает сокет, выполняет логин и подготавливает соединение к командам
    public function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }

        $target = ($this->useSsl ? 'ssl://' : '') . $this->host;
        $socket = @fsockopen($target, $this->port, $errno, $errstr, $this->timeout);
        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf(
                'Не удалось подключиться к MikroTik (%s:%d): %s (%d)',
                $this->host,
                $this->port,
                $errstr ?: 'unknown error',
                $errno,
            ));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->writeSentence([
            '/login',
            '=name=' . $this->username,
            '=password=' . $this->password,
        ]);

        $this->readReply();
    }

    // Закрывает соединение с MikroTik и освобождает сокет
    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }


    // Отправляет команду API с атрибутами и возвращает набор ответов.
    public function command(string $command, array $attributes = [], array $queries = []): array
    {
        $this->connect();

        $words = [$command];
        foreach ($attributes as $key => $value) {
            $words[] = '=' . $key . '=' . (string) $value;
        }
        foreach ($queries as $key => $value) {
            $words[] = '?' . $key . '=' . (string) $value;
        }

        $this->writeSentence($words);
        return $this->readReply();
    }

    // Гарантированно закрывает сокет при уничтожении объекта?????????
    public function __destruct()
    {
        $this->disconnect();
    }

    // Отправляет в API одно предложение как набор слов??????????
    private function writeSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord($word);
        }
        $this->writeWord('');
    }

    // Отправляет одно слово API с префиксом длины??????????
    private function writeWord(string $word): void
    {
        $this->writeLength(strlen($word));
        $this->writeRaw($word);
    }

    // Кодирует длину слова в формате протокола RouterOS API.
    private function writeLength(int $length): void
    {
        if ($length < 0x80) {
            $this->writeRaw(chr($length));
            return;
        }

        if ($length < 0x4000) {
            $length |= 0x8000;
            $this->writeRaw(chr(($length >> 8) & 0xFF) . chr($length & 0xFF));
            return;
        }

        if ($length < 0x200000) {
            $length |= 0xC00000;
            $this->writeRaw(
                chr(($length >> 16) & 0xFF) .
                chr(($length >> 8) & 0xFF) .
                chr($length & 0xFF)
            );
            return;
        }

        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            $this->writeRaw(
                chr(($length >> 24) & 0xFF) .
                chr(($length >> 16) & 0xFF) .
                chr(($length >> 8) & 0xFF) .
                chr($length & 0xFF)
            );
            return;
        }

        $this->writeRaw(chr(0xF0));
        $this->writeRaw(pack('N', $length));
    }

    // Записывает сырые данные в сокет
    private function writeRaw(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Соединение с MikroTik не установлено.');
        }

        $remaining = $data;
        while ($remaining !== '') {
            $written = fwrite($this->socket, $remaining);
            if ($written === false) {
                throw new RuntimeException('Не удалось записать данные в сокет MikroTik.');
            }
            $remaining = (string) substr($remaining, $written);
        }
    }

    // Читает и разбирает ответ API до маркера завершения !done.
    private function readReply(): array
    {
        $rows = [];

        while (true) {
            $sentence = $this->readSentence();
            if ($sentence === []) {
                continue;
            }

            $replyType = $sentence[0];
            if ($replyType === '!re') {
                $rows[] = $this->parseSentence($sentence);
                continue;
            }

            if ($replyType === '!trap' || $replyType === '!fatal') {
                $message = $this->extractMessage($sentence);
                throw new RuntimeException($message !== '' ? $message : 'MikroTik вернул ошибку API.');
            }

            if ($replyType === '!done') {
                return $rows;
            }
        }
    }

    // Считывает одно предложение из последовательности слов API.
    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $word = $this->readWord();
            if ($word === '') {
                break;
            }
            $words[] = $word;
        }
        return $words;
    }

    // Считывает одно слово апи по его длине...
    private function readWord(): string
    {
        $length = $this->readLength();
        if ($length === 0) {
            return '';
        }
        return $this->readRaw($length);
    }

    // Декодирует длину следующего слова по правилам апи
    private function readLength(): int
    {
        $first = ord($this->readRaw(1));

        if (($first & 0x80) === 0x00) {
            return $first;
        }

        if (($first & 0xC0) === 0x80) {
            $second = ord($this->readRaw(1));
            return (($first & ~0xC0) << 8) + $second;
        }

        if (($first & 0xE0) === 0xC0) {
            $rest = $this->readRaw(2);
            return (($first & ~0xE0) << 16) + (ord($rest[0]) << 8) + ord($rest[1]);
        }

        if (($first & 0xF0) === 0xE0) {
            $rest = $this->readRaw(3);
            return (($first & ~0xF0) << 24) + (ord($rest[0]) << 16) + (ord($rest[1]) << 8) + ord($rest[2]);
        }

        if (($first & 0xF8) === 0xF0) {
            $rest = $this->readRaw(4);
            $unpacked = unpack('Nlength', $rest);
            return (int) $unpacked['length'];
        }

        throw new RuntimeException('Не удалось прочитать длину слова из MikroTik API.');
    }

    // Читает указанное число байт из сокета с обработкой таймаута.
    private function readRaw(int $length): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Соединение с MikroTik не установлено.');
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Превышено время ожидания ответа от MikroTik.');
                }
                throw new RuntimeException('Соединение с MikroTik было закрыто во время чтения ответа.');
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    // Преобразует предложение API в ассоциативный массив ключ-значение.
    private function parseSentence(array $sentence): array
    {
        $result = [];
        foreach ($sentence as $word) {
            if (!str_starts_with($word, '=')) {
                continue;
            }

            $pair = substr($word, 1);
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $result[$key] = $value;
        }

        return $result;
    }

    //?????????Извлекает текст ошибки из trap/fatal-ответа MikroTik API
    private function extractMessage(array $sentence): string
    {
        $parsed = $this->parseSentence($sentence);
        return (string) ($parsed['message'] ?? $parsed['category'] ?? '');
    }
}
