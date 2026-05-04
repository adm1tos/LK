<?php

if (!is_guest()):
?>
    <aside class="sidebar">
        <div class="sidebar-title">LK</div>

        <nav class="sidebar-nav">
            <a href="/mainmenu/dashboard.php">Главная</a>
            <a href="/lk_vpn/index.php">VPN конфиги</a>
            <a href="/lk_ssh/index.php">SSH ключи</a>
            <a href="/lk_ssh/machines.php">Доступные машины</a>
            <a href="/lk_dns/index.php">DNS</a>

            <?php if (is_admin()): ?>
                <hr class="sidebar-divider">

                <a href="/admin/vpn_requests.php">Заявки VPN</a>
                <a href="/admin/vpn_settings.php">Настройки VPN</a>
                <a href="/admin/vpn_peers.php">Пиры VPN</a>
            <?php endif; ?>
        </nav>
    </aside>
<?php
endif;
?>