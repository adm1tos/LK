<?php
// сервис управления SSH-ключами внутри VM (через QEMU Guest Agent)
declare(strict_types=1);

final class VmSshService
{
    private ProxmoxService $proxmox;

    public function __construct(
        private readonly array $config
    ) {
        $this->proxmox = new ProxmoxService($this->config);
    }

    // Надежное экранирование аргументов именно для Linux shell (на всякий случай т.к. была ошибка)
    private function escapeForLinuxBash(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    // Запускает bash-скрипт на удалённой VM через QEMU Guest Agent
    private function runAgentScript(string $node, int $vmid, string $type, string $script): string
    {
        if ($type !== 'qemu') {
            throw new RuntimeException('Управление сейчас поддерживается только для QEMU VM.');
        }

        $result = $this->proxmox->execGuestAgentScript($node, $vmid, $script);

        if ($result['exitcode'] !== 0) {
            throw new RuntimeException(
                'Ошибка выполнения команды через QEMU Guest Agent. ' .
                'Код: ' . $result['exitcode'] . '. ' .
                'Вывод: ' . trim($result['out-data'] . ' ' . $result['err-data'])
            );
        }

        return $result['out-data'];
    }

    // Получает список Linux-пользователей, доступных для управления SSH-ключами
    public function listUsers(string $node, int $vmid, string $type): array
    {
        // Команда для поиска пользователей по айди
        $script = "awk -F: '\$3 >= 1000 && \$3 != 65534 {print \$1}' /etc/passwd";
        // запуск скрипта выше от лица агента на ВМ
        $output = $this->runAgentScript($node, $vmid, $type, $script);
            //полученный результат обрабатываем
        $lines = preg_split('/\r\n|\r|\n/', $output);
        $lines = array_map('trim', $lines ?: []);
        $lines = array_filter($lines, static fn(string $line): bool => $line !== '');

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
        $linuxUserEsc = $this->escapeForLinuxBash($linuxUser);
        $publicKeyEsc = $this->escapeForLinuxBash(trim(str_replace(["\r", "\n"], '', $publicKey)));

        $script = <<<BASH
USER_HOME=$(getent passwd {$linuxUserEsc} | cut -d: -f6)
if [ -z "\$USER_HOME" ]; then
    echo "User not found" >&2
    exit 1
fi

mkdir -p "\$USER_HOME/.ssh"
echo {$publicKeyEsc} >> "\$USER_HOME/.ssh/authorized_keys"

# Удаляем возможные дубликаты ключа
awk '!seen[\$0]++' "\$USER_HOME/.ssh/authorized_keys" > "\$USER_HOME/.ssh/authorized_keys.tmp"
mv "\$USER_HOME/.ssh/authorized_keys.tmp" "\$USER_HOME/.ssh/authorized_keys"

chown -R {$linuxUserEsc}:{$linuxUserEsc} "\$USER_HOME/.ssh"
chmod 700 "\$USER_HOME/.ssh"
chmod 600 "\$USER_HOME/.ssh/authorized_keys"
BASH;

        $this->runAgentScript($node, $vmid, $type, $script);
    }

    // Удаляет публичный SSH-ключ у указанного пользователя внутри VM
    public function removeKey(
        string $node,
        int $vmid,
        string $type,
        string $linuxUser,
        string $publicKey
    ): void {
        $linuxUserEsc = $this->escapeForLinuxBash($linuxUser);
        $publicKeyEsc = $this->escapeForLinuxBash(trim(str_replace(["\r", "\n"], '', $publicKey)));

        $script = <<<BASH
USER_HOME=$(getent passwd {$linuxUserEsc} | cut -d: -f6)
if [ -z "\$USER_HOME" ]; then
    echo "User not found" >&2
    exit 1
fi

if [ -f "\$USER_HOME/.ssh/authorized_keys" ]; then
    grep -vxF {$publicKeyEsc} "\$USER_HOME/.ssh/authorized_keys" > "\$USER_HOME/.ssh/authorized_keys.tmp" || true
    mv "\$USER_HOME/.ssh/authorized_keys.tmp" "\$USER_HOME/.ssh/authorized_keys"
    chown {$linuxUserEsc}:{$linuxUserEsc} "\$USER_HOME/.ssh/authorized_keys"
    chmod 600 "\$USER_HOME/.ssh/authorized_keys"
fi
BASH;

        $this->runAgentScript($node, $vmid, $type, $script);
    }

    // Проверяет, можно ли открыть меню SSH для VM
    public function isManagedVm(string $node, int $vmid, string $type): bool
    {
        return $type === 'qemu';
    }
}