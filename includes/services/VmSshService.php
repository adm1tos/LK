<?php
// сервис управления SSH-ключами внутри VM
declare(strict_types=1);

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

final class VmSshService
{
    private string $privateKeyPath;
    private string $adminScriptPath;

    public function __construct(
        private readonly array $config
    ) {
        $path = (string) ($this->config['ssh_admin']['private_key_path'] ?? '');

        $this->privateKeyPath =
            str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $path)
                ? $path
                : BASE_PATH . '/' . ltrim($path, '/');

        $scriptPath = (string) ($this->config['ssh_admin']['script_path'] ?? 'storage/scripts/lk-ssh-admin.sh');

        $this->adminScriptPath =
            str_starts_with($scriptPath, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $scriptPath)
                ? $scriptPath
                : BASE_PATH . '/' . ltrim($scriptPath, '/');
    }

    // Проверяет, включён ли тестовый режим SSH.
    // Тестовый режим можно будет убрать вместе с TEST_VM_* переменными,
    // когда полностью перейдёшь на настоящий гипервизор.
    private function isTestMode(): bool
    {
        return (bool) ($this->config['ssh_admin']['test_mode'] ?? true);
    }

    // Возвращает SSH-параметры подключения к VM
    private function getEndpoint(string $node, int $vmid, string $type): array
    {
        if ($this->isTestMode()) {
            return $this->getTestVmEndpoint($node, $vmid, $type);
        }

        if ($type !== 'qemu') {
            throw new RuntimeException('SSH-управление сейчас поддерживается только для QEMU VM.');
        }

        $proxmox = new ProxmoxService($this->config);

        // Если в ProxmoxService у тебя метод называется getQemuMachineIp(),
        // оставь этот вариант. Он получает IP через QEMU Guest Agent.
        $ipAddress = $proxmox->getQemuMachineIp($node, $vmid);

        if (!$ipAddress) {
            throw new RuntimeException(
                'Не удалось получить IP-адрес VM через QEMU Guest Agent. Проверь, что VM включена, агент установлен и включён в Proxmox.'
            );
        }

        return [
            'ssh_host' => $ipAddress,
            'ssh_port' => (int) ($this->config['ssh_admin']['port'] ?? 22),
            'ssh_user' => (string) ($this->config['ssh_admin']['user'] ?? 'lkadmin'),
        ];
    }

    // Возвращает SSH-параметры только для тестовой VM
    private function getTestVmEndpoint(string $node, int $vmid, string $type): array
    {
        $vm = $this->config['test_ssh_vm'];

        if (
            $node !== (string) $vm['node']
            || $vmid !== (int) $vm['vmid']
            || $type !== (string) $vm['type']
        ) {
            throw new RuntimeException('Для этой VM SSH-управление недоступно в тестовом режиме.');
        }

        return [
            'ssh_host' => (string) $vm['host'],
            'ssh_port' => (int) $vm['port'],
            'ssh_user' => (string) $vm['user'],
        ];
    }

    // Устанавливает SSH-подключение к VM с авторизацией по приватному ключу сайта
    private function connect(array $endpoint): SSH2
    {
        if (!is_file($this->privateKeyPath)) {
            throw new RuntimeException('Приватный ключ сайта не найден: ' . $this->privateKeyPath);
        }

        $connectTimeout = (int) ($this->config['ssh_admin']['connect_timeout'] ?? 10);
        $commandTimeout = (int) ($this->config['ssh_admin']['command_timeout'] ?? 15);

        $ssh = new SSH2(
            (string) $endpoint['ssh_host'],
            (int) $endpoint['ssh_port'],
            $connectTimeout
        );

        $ssh->setTimeout($commandTimeout);

        $keyContent = file_get_contents($this->privateKeyPath);

        if ($keyContent === false) {
            throw new RuntimeException('Не удалось прочитать приватный ключ сайта.');
        }

        $key = PublicKeyLoader::loadPrivateKey($keyContent);

        $ok = $ssh->login((string) $endpoint['ssh_user'], $key);

        if (!$ok) {
            throw new RuntimeException(
                'Не удалось подключиться к VM по SSH под пользователем ' . $endpoint['ssh_user']
            );
        }

        return $ssh;
    }

    // Экранирует аргумент для Linux shell
    private function shQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    // Запускает локальный скрипт сайта на удалённой VM через SSH
    private function runAdminScript(SSH2 $ssh, string $action, array $arguments = []): string
    {
        if (!is_file($this->adminScriptPath)) {
            throw new RuntimeException('SSH admin script не найден: ' . $this->adminScriptPath);
        }

        $script = file_get_contents($this->adminScriptPath);

        if ($script === false || trim($script) === '') {
            throw new RuntimeException('Не удалось прочитать SSH admin script.');
        }

        $encodedScript = base64_encode($script);

        $commandParts = [
            'printf',
            '%s',
            $this->shQuote($encodedScript),
            '|',
            'base64',
            '-d',
            '|',
            'sudo',
            '-n',
            '/bin/bash',
            '-s',
            '--',
            $this->shQuote($action),
        ];

        foreach ($arguments as $argument) {
            $commandParts[] = $this->shQuote((string) $argument);
        }

        $command = implode(' ', $commandParts);

        $output = $ssh->exec($command);
        $exit = $ssh->getExitStatus();

        if ($exit !== 0 && $exit !== null) {
            throw new RuntimeException(
                'Ошибка выполнения SSH admin script. Action: '
                . $action
                . '. Output: '
                . trim((string) $output)
            );
        }

        return (string) $output;
    }

    // Получает список Linux-пользователей, доступных для управления SSH-ключами
    public function listUsers(string $node, int $vmid, string $type): array
    {
        $endpoint = $this->getEndpoint($node, $vmid, $type);
        $ssh = $this->connect($endpoint);

        $output = $this->runAdminScript($ssh, 'list-users');

        $lines = preg_split('/\r\n|\r|\n/', $output);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines);

        return array_values(array_unique($lines));
    }

    // Добавляет публичный SSH-ключ указанному пользователю внутри VM
    public function addKey(
        string $node,
        int $vmid,
        string $type,
        string $linuxUser,
        string $publicKey
    ): void {
        $endpoint = $this->getEndpoint($node, $vmid, $type);
        $ssh = $this->connect($endpoint);

        $this->runAdminScript($ssh, 'add-key', [
            $linuxUser,
            $publicKey,
        ]);
    }

    // Удаляет публичный SSH-ключ у указанного пользователя внутри VM
    public function removeKey(
        string $node,
        int $vmid,
        string $type,
        string $linuxUser,
        string $publicKey
    ): void {
        $endpoint = $this->getEndpoint($node, $vmid, $type);
        $ssh = $this->connect($endpoint);

        $this->runAdminScript($ssh, 'remove-key', [
            $linuxUser,
            $publicKey,
        ]);
    }

    // Проверяет, можно ли открыть меню SSH для VM
    public function isManagedVm(string $node, int $vmid, string $type): bool
    {
        if ($this->isTestMode()) {
            $vm = $this->config['test_ssh_vm'];

            return (
                $node === (string) $vm['node']
                && $vmid === (int) $vm['vmid']
                && $type === (string) $vm['type']
            );
        }

        return $type === 'qemu';
    }
}