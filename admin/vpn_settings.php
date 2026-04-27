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

    if (!ctype_digit($lastIpCount)) {
        flash('error', 'Счетчик IP должен быть целым числом.');
        redirect('admin/vpn_settings.php');
    }
// обновляем настройки в базе данных
    $update = db()->prepare('UPDATE settings SET setting_value = :value WHERE setting_key = :key');
    $update->execute(['value' => $allowRegen, 'key' => 'allow_regen']);
    $update->execute(['value' => $lastIpCount, 'key' => 'last_ip_count']);
// записываем в лог изменения настроек
    $log = db()->prepare('INSERT INTO admin_logs (admin_id, action_type, target_type, details) VALUES (:admin_id, :action_type, :target_type, :details)');
    $log->execute([
        'admin_id' => $admin['id'],
        'action_type' => 'vpn_settings_update',
        'target_type' => 'settings',
        'details' => 'allow_regen=' . $allowRegen . '; last_ip_count=' . $lastIpCount,
    ]);

    flash('success', 'VPN настройки обновлены.');
    redirect('admin/vpn_settings.php');
}

$pageTitle = 'VPN настройки';
$pageSubtitle = 'Глобальные параметры VPN-модуля.';
// получаем список настроек
$stmt = db()->query('SELECT setting_key, setting_value FROM settings');
$rawSettings = $stmt->fetchAll();
$settings = [];
foreach ($rawSettings as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
// получаем значение checkbox для разрешения повторной генерации конфигов
$allowRegenChecked = ($settings['allow_regen'] ?? '0') === '1';
// получаем значение счетчика IP
$lastIpCountValue = $settings['last_ip_count'] ?? '2';

require INCLUDES_PATH . '/header.php';
?>
<section class="card">
    <form method="post" class="settings-form" data-dirty-form>
        <?= csrf_field() ?>

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

        <button class="btn btn-disabled" type="submit" id="save-settings-btn" disabled>
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
    const trackedFields = form.querySelectorAll('input[name="allow_regen"], input[name="last_ip_count"]');

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