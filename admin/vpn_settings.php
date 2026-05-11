<?php
// страница управления настройками VPN
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$admin = current_user();
// получаем данные для управления настройками VPN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $allowRegen = isset($_POST['allow_regen']) ? '1' : '0';
    $lastIpCount = trim($_POST['last_ip_count'] ?? '2');

    $moduleVpnEnabled = isset($_POST['module_vpn_enabled']) ? '1' : '0';
    $moduleSshEnabled = isset($_POST['module_ssh_enabled']) ? '1' : '0';
    $moduleDnsEnabled = isset($_POST['module_dns_enabled']) ? '1' : '0';

    if (!ctype_digit($lastIpCount)) {
        flash('error', 'Счетчик IP должен быть целым числом.');
        redirect('admin/vpn_settings.php');
    }

    $settingsService = new SettingsService();
    $settingsService->set('allow_regen', $allowRegen);
    $settingsService->set('last_ip_count', $lastIpCount);
    $settingsService->set('module_vpn_enabled', $moduleVpnEnabled);
    $settingsService->set('module_ssh_enabled', $moduleSshEnabled);
    $settingsService->set('module_dns_enabled', $moduleDnsEnabled);

// записываем в лог изменения настроек через LoggerService
    $logger = new LoggerService(db());
    $logger->log(
        'settings_update',
        'settings',
        null,
        'Обновлены системные настройки'
    );

    flash('success', 'Настройки обновлены.');
    redirect('admin/vpn_settings.php');
}

$pageTitle = 'Настройки сайта';
$pageSubtitle = 'Глобальные параметры сайта и модулей.';

$settingsService = new SettingsService();
$allowRegenChecked = $settingsService->getBool('allow_regen', false);
$lastIpCountValue = $settingsService->get('last_ip_count', '2');
$moduleVpnChecked = $settingsService->getBool('module_vpn_enabled', true);
$moduleSshChecked = $settingsService->getBool('module_ssh_enabled', true);
$moduleDnsChecked = $settingsService->getBool('module_dns_enabled', true);

require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <form method="post" class="settings-form" data-dirty-form>
        <?= csrf_field() ?>

        <h3>Видимость разделов</h3>
        <label class="checkbox-row" for="module_vpn_enabled">
            <input id="module_vpn_enabled" type="checkbox" name="module_vpn_enabled" value="1" <?= $moduleVpnChecked ? 'checked' : '' ?> data-initial="<?= $moduleVpnChecked ? '1' : '0' ?>">
            <span>Модуль VPN включен</span>
        </label>
        
        <label class="checkbox-row" for="module_ssh_enabled">
            <input id="module_ssh_enabled" type="checkbox" name="module_ssh_enabled" value="1" <?= $moduleSshChecked ? 'checked' : '' ?> data-initial="<?= $moduleSshChecked ? '1' : '0' ?>">
            <span>Модуль SSH включен</span>
        </label>
        
        <label class="checkbox-row" for="module_dns_enabled">
            <input id="module_dns_enabled" type="checkbox" name="module_dns_enabled" value="1" <?= $moduleDnsChecked ? 'checked' : '' ?> data-initial="<?= $moduleDnsChecked ? '1' : '0' ?>">
            <span>Модуль DNS включен</span>
        </label>

        <h3 style="margin-top: 20px;">Настройки VPN</h3>
        <label class="checkbox-row" for="allow_regen">
            <input
                id="allow_regen"
                type="checkbox"
                name="allow_regen"
                value="1"
                <?= $allowRegenChecked ? 'checked' : '' ?>
                data-initial="<?= $allowRegenChecked ? '1' : '0' ?>"
            >
            <span>Разрешить повторную генерацию конфигов</span>
        </label>

        <label for="last_ip_count">Текущее значение счетчика IP</label>
        <input
            id="last_ip_count"
            name="last_ip_count"
            value="<?= e($lastIpCountValue) ?>"
            data-initial="<?= e($lastIpCountValue) ?>"
        >

        <button class="btn btn-disabled" type="submit" id="save-settings-btn" disabled style="margin-top: 20px;">
            Сохранить изменения
        </button>
    </form>
</section>

<script>
// скрипт для управления настройками VPN
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-dirty-form]');
    if (!form) return;

    const submitBtn = document.getElementById('save-settings-btn');
    const trackedFields = form.querySelectorAll('input[name="allow_regen"], input[name="last_ip_count"], input[name="module_vpn_enabled"], input[name="module_ssh_enabled"], input[name="module_dns_enabled"]');

    const isChanged = (field) => {
        const initial = field.dataset.initial ?? '';

        if (field.type === 'checkbox') {
            return (field.checked ? '1' : '0') !== initial;
        }

        return field.value !== initial;
    };
// обновляем состояние кнопки сохранения
    const updateButtonState = () => {
        const changed = Array.from(trackedFields).some(isChanged);
        submitBtn.disabled = !changed;
        submitBtn.classList.toggle('btn-disabled', !changed);
    };

    trackedFields.forEach((field) => {
        field.addEventListener('input', updateButtonState);
        field.addEventListener('change', updateButtonState);
    });

    updateButtonState();
});
</script>
<?php require INCLUDES_PATH . '/footer.php'; ?>