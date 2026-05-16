<?php
// альтернативная версия созданная с божей помощью. не использует библиотеку phpseclib
// использует системный ssh
declare(strict_types=1);

final class VmSshService
{
    private string $privateKeyPath;
    private string $sshBinary;
    private string $nullKnownHosts;

    private function escapeRemoteArg(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    public function __construct(private readonly array $config)
    {
        $path = (string) ($this->config['ssh_admin']['private_key_path'] ?? '');

        $this->privateKeyPath = $this->normalizePath($path);
        $this->sshBinary = 'ssh';
        $this->nullKnownHosts = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (
            str_starts_with($path, DIRECTORY_SEPARATOR) ||
            preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
        ) {
            return $path;
        }

        return BASE_PATH . '/' . ltrim($path, '/');
    }

    private function isTestMode(): bool
    {
        return (bool) ($this->config['ssh_admin']['test_mode'] ?? true);
    }

    public function isManagedVm(string $node, int $vmid, string $type): bool
    {
        if ($this->isTestMode()) {
            $vm = $this->config['test_ssh_vm'];
            return (
                $node === (string) $vm['node'] &&
                $vmid === (int) $vm['vmid'] &&
                $type === (string) $vm['type']
            );
        }

        return $type === 'qemu';
    }

    private function getManagedVmEndpoint(string $node, int $vmid, string $type): array
    {
        if (!$this->isManagedVm($node, $vmid, $type)) {
            throw new RuntimeException('Для этой VM SSH-управление пока не настроено.');
        }

        if ($this->isTestMode()) {
            $vm = $this->config['test_ssh_vm'];
            return [
                'ssh_host' => (string) $vm['host'],
                'ssh_port' => (int) $vm['port'],
                'ssh_user' => (string) $vm['user'],
            ];
        }

        $proxmox = new ProxmoxService($this->config);
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

    /**
     * @return array{stdout:string,stderr:string,exit_code:int}
     */
    private function runRemoteCommand(array $endpoint, string $remoteCommand): array
    {
        if ($this->privateKeyPath === '') {
            throw new RuntimeException('Не задан путь к приватному ключу сайта.');
        }

        if (!is_file($this->privateKeyPath)) {
            throw new RuntimeException('Приватный ключ сайта не найден: ' . $this->privateKeyPath);
        }

        if (!is_readable($this->privateKeyPath)) {
            throw new RuntimeException('Нет прав на чтение приватного ключа: ' . $this->privateKeyPath);
        }

        $destination = (string) $endpoint['ssh_user'] . '@' . (string) $endpoint['ssh_host'];

        $command = implode(' ', [
            escapeshellarg($this->sshBinary),
            '-i',
            escapeshellarg($this->privateKeyPath),
            '-p',
            (string) ((int) $endpoint['ssh_port']),
            '-o',
            escapeshellarg('BatchMode=yes'),
            '-o',
            escapeshellarg('StrictHostKeyChecking=no'),
            '-o',
            escapeshellarg('UserKnownHostsFile=' . $this->nullKnownHosts),
            escapeshellarg($destination),
            escapeshellarg($remoteCommand),
        ]);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, BASE_PATH);

        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить локальную ssh-команду.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
            'exit_code' => is_int($exitCode) ? $exitCode : 1,
        ];
    }

    public function listUsers(string $node, int $vmid, string $type): array
    {
        $endpoint = $this->getManagedVmEndpoint($node, $vmid, $type);

        $result = $this->runRemoteCommand(
            $endpoint,
            'sudo -n /usr/local/bin/lk-ssh-admin list-users'
        );

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(
                "Не удалось получить список пользователей VM.\n" .
                "Exit code: {$result['exit_code']}\n" .
                "STDERR:\n{$result['stderr']}\n" .
                "STDOUT:\n{$result['stdout']}"
            );
        }

        $lines = preg_split('/\r\n|\r|\n/', $result['stdout']);
        $lines = array_map('trim', $lines ?: []);
        $lines = array_filter($lines, static fn(string $line): bool => $line !== '');

        return array_values(array_unique($lines));
    }

    public function addKey(string $node, int $vmid, string $type, string $linuxUser, string $publicKey): void
    {
        $endpoint = $this->getManagedVmEndpoint($node, $vmid, $type);

        $publicKey = trim(str_replace(["\r", "\n"], '', $publicKey));

        $remoteCommand = sprintf(
            'sudo -n /usr/local/bin/lk-ssh-admin add-key %s %s',
            $this->escapeRemoteArg($linuxUser),
            $this->escapeRemoteArg($publicKey)
        );

        $result = $this->runRemoteCommand($endpoint, $remoteCommand);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(
                "Не удалось добавить SSH-ключ пользователю {$linuxUser}.\n" .
                "Exit code: {$result['exit_code']}\n" .
                "STDERR:\n{$result['stderr']}\n" .
                "STDOUT:\n{$result['stdout']}"
            );
        }
    }

    public function removeKey(string $node, int $vmid, string $type, string $linuxUser, string $publicKey): void
    {
        $endpoint = $this->getManagedVmEndpoint($node, $vmid, $type);

        $publicKey = trim(str_replace(["\r", "\n"], '', $publicKey));

        $remoteCommand = sprintf(
            'sudo -n /usr/local/bin/lk-ssh-admin remove-key %s %s',
            $this->escapeRemoteArg($linuxUser),
            $this->escapeRemoteArg($publicKey)
        );

        $result = $this->runRemoteCommand($endpoint, $remoteCommand);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException(
                "Не удалось удалить SSH-ключ у пользователя {$linuxUser}.\n" .
                "Exit code: {$result['exit_code']}\n" .
                "STDERR:\n{$result['stderr']}\n" .
                "STDOUT:\n{$result['stdout']}"
            );
        }
    }
}