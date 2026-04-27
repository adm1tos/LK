<?php
// Обращается к файлу с командами, который установлен на вм
declare(strict_types=1);

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

final class VmSshService
{
    private string $privateKeyPath;

    // Подготавливает путь к приватному ключу сайта для SSH-подключений
    public function __construct(private readonly array $config)
    {
        $path = (string) ($this->config['ssh_admin']['private_key_path'] ?? '');

        $this->privateKeyPath = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $path)
            ? $path
            : BASE_PATH . '/' . ltrim($path, '/');
    }

    // Возвращает SSH-параметры только для разрешенной управляемой VM
    private function getManagedVmEndpoint(string $node, int $vmid, string $type): array
    {
        $vm = $this->config['test_ssh_vm'];

        if (
            $node !== (string) $vm['node'] ||
            $vmid !== (int) $vm['vmid'] ||
            $type !== (string) $vm['type']
        ) {
            throw new RuntimeException('Для этой VM SSH-управление пока не настроено в тестовом стенде.');
        }

        return [
            'ssh_host' => (string) $vm['host'],
            'ssh_port' => (int) $vm['port'],
            'ssh_user' => (string) $vm['user'],
        ];
    }

    // Устанавливает SSH-подключение к VM с авторизацией по приватному ключу
    private function connect(array $endpoint): SSH2
    {
        if (!is_file($this->privateKeyPath)) {
            throw new RuntimeException('Приватный ключ сайта не найден: ' . $this->privateKeyPath);
        }

        $ssh = new SSH2((string) $endpoint['ssh_host'], (int) $endpoint['ssh_port']);
        $key = PublicKeyLoader::loadPrivateKey(file_get_contents($this->privateKeyPath));

        $ok = $ssh->login((string) $endpoint['ssh_user'], $key);
        if (!$ok) {
            throw new RuntimeException('Не удалось подключиться к VM по SSH под пользователем ' . $endpoint['ssh_user']);
        }

        return $ssh;
    }

    // Получает список Linux-пользователей, доступных для управления SSH-ключами
    public function listUsers(string $node, int $vmid, string $type): array
    {
        $endpoint = $this->getManagedVmEndpoint($node, $vmid, $type);
        $ssh = $this->connect($endpoint);

        $output = $ssh->exec('sudo -n /usr/local/bin/lk-ssh-admin list-users');
        $exit = $ssh->getExitStatus();

        if ($exit !== 0 && $exit !== null) {
            throw new RuntimeException('Не удалось получить список пользователей VM.');
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $output);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines);

        return array_values(array_unique($lines));
    }

    // Добавляет публичный SSH-ключ указанному пользователю внутри VM
    public function addKey(string $node, int $vmid, string $type, string $linuxUser, string $publicKey): void
    {
        $endpoint = $this->getManagedVmEndpoint($node, $vmid, $type);
        $ssh = $this->connect($endpoint);

        $cmd = sprintf(
            'sudo -n /usr/local/bin/lk-ssh-admin add-key %s %s',
            escapeshellarg($linuxUser),
            escapeshellarg($publicKey)
        );

        $ssh->exec($cmd);
        $exit = $ssh->getExitStatus();

        if ($exit !== 0 && $exit !== null) {
            throw new RuntimeException('Не удалось добавить SSH-ключ пользователю ' . $linuxUser);
        }
    }

    // Удаляет публичный SSH-ключ у указанного пользователя внутри VM
    public function removeKey(string $node, int $vmid, string $type, string $linuxUser, string $publicKey): void
    {
        $endpoint = $this->getManagedVmEndpoint($node, $vmid, $type);
        $ssh = $this->connect($endpoint);

        $cmd = sprintf(
            'sudo -n /usr/local/bin/lk-ssh-admin remove-key %s %s',
            escapeshellarg($linuxUser),
            escapeshellarg($publicKey)
        );

        $ssh->exec($cmd);
        $exit = $ssh->getExitStatus();

        if ($exit !== 0 && $exit !== null) {
            throw new RuntimeException('Не удалось удалить SSH-ключ у пользователя ' . $linuxUser);
        }
    }

    // Проверяет, относится ли VM к разрешенным для SSH-управления
    public function isManagedVm(string $node, int $vmid, string $type): bool
    {
        $vm = $this->config['test_ssh_vm'];

        return (
            $node === (string) $vm['node'] &&
            $vmid === (int) $vm['vmid'] &&
            $type === (string) $vm['type']
        );
    }
}