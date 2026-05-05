<?php

if (!is_guest()):
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

    $isActive = static function (string $path) use ($currentPath): string {
        $path = '/' . ltrim($path, '/');

        return str_ends_with($currentPath, $path) ? 'active' : '';
    };

    $isGroupOpen = static function (array $paths) use ($currentPath): bool {
        foreach ($paths as $path) {
            $path = '/' . ltrim((string) $path, '/');

            if (str_ends_with($currentPath, $path)) {
                return true;
            }
        }

        return false;
    };
?>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-title">LK</div>

            <button
                class="sidebar-toggle"
                type="button"
                id="sidebarToggle"
                aria-label="Свернуть меню"
                aria-expanded="true"
            >
                ☰
            </button>
        </div>

        <nav class="sidebar-nav">
            <a
                href="<?= e(url('mainmenu/dashboard.php')) ?>"
                class="<?= e($isActive('mainmenu/dashboard.php')) ?>"
            >
                Главная
            </a>

            <details
                class="sidebar-group"
                <?= $isGroupOpen(['lk_vpn/index.php']) ? 'open' : '' ?>
            >
                <summary class="sidebar-group-title">VPN</summary>

                <div class="sidebar-subnav">
                    <a
                        href="<?= e(url('lk_vpn/index.php')) ?>"
                        class="<?= e($isActive('lk_vpn/index.php')) ?>"
                    >
                        VPN конфиги
                    </a>
                </div>
            </details>

            <details
                class="sidebar-group"
                <?= $isGroupOpen(['lk_ssh/index.php', 'lk_ssh/machines.php']) ? 'open' : '' ?>
            >
                <summary class="sidebar-group-title">SSH</summary>

                <div class="sidebar-subnav">
                    <a
                        href="<?= e(url('lk_ssh/index.php')) ?>"
                        class="<?= e($isActive('lk_ssh/index.php')) ?>"
                    >
                        SSH ключи
                    </a>

                    <a
                        href="<?= e(url('lk_ssh/machines.php')) ?>"
                        class="<?= e($isActive('lk_ssh/machines.php')) ?>"
                    >
                        Доступные машины
                    </a>
                </div>
            </details>

            <details
                class="sidebar-group"
                <?= $isGroupOpen(['lk_dns/index.php', 'lk_dns/records.php']) ? 'open' : '' ?>
            >
                <summary class="sidebar-group-title">DNS</summary>

                <div class="sidebar-subnav">
                    <a
                        href="<?= e(url('lk_dns/index.php')) ?>"
                        class="<?= e($isActive('lk_dns/index.php')) ?>"
                    >
                        Добавить DNS
                    </a>

                    <a
                        href="<?= e(url('lk_dns/records.php')) ?>"
                        class="<?= e($isActive('lk_dns/records.php')) ?>"
                    >
                        DNS-записи
                    </a>
                </div>
            </details>

            <?php if (is_admin()): ?>
                <hr class="sidebar-divider">

                <details
                    class="sidebar-group"
                    <?= $isGroupOpen([
                        'admin/vpn_requests.php',
                        'admin/vpn_settings.php',
                        'admin/vpn_peers.php',
                    ]) ? 'open' : '' ?>
                >
                    <summary class="sidebar-group-title">Администрирование</summary>

                    <div class="sidebar-subnav">
                        <a
                            href="<?= e(url('admin/vpn_requests.php')) ?>"
                            class="<?= e($isActive('admin/vpn_requests.php')) ?>"
                        >
                            Заявки VPN
                        </a>

                        <a
                            href="<?= e(url('admin/vpn_settings.php')) ?>"
                            class="<?= e($isActive('admin/vpn_settings.php')) ?>"
                        >
                            Настройки VPN
                        </a>

                        <a
                            href="<?= e(url('admin/vpn_peers.php')) ?>"
                            class="<?= e($isActive('admin/vpn_peers.php')) ?>"
                        >
                            Пиры VPN
                        </a>
                                                <a
                            href="<?= e(url('admin/dns_records.php')) ?>"
                            class="<?= e($isActive('admin/dns_records.php')) ?>"
                        >
                            DNS-записи
                        </a>
                    </div>
                </details>
            <?php endif; ?>
        </nav>
    </aside>
<?php
endif;
?>