<?php
// Для чтения и записи значений в таблицу settings

declare(strict_types=1);

final class SettingsService
{
    // Возвращает строковое значение настройки по ключу или значение по умолчанию
    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        if ($row === false) {
            return $default;
        }

        return (string) $row['setting_value'];
    }

    // Возвращает значение настройки как целое число
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    // Возвращает значение настройки как логический флаг
    public function getBool(string $key, bool $default = false): bool
    {
        return $this->get($key, $default ? '1' : '0') === '1';
    }

    // Сохраняет настройку обновляя значение при существующем ключе
    public function set(string $key, string $value): void
    {
        $stmt = db()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $value,
        ]);
    }
}
