<?php
// Загрузчик - стартует сессию, подключает сервисы

declare(strict_types=1);

session_start();

define('BASE_PATH', dirname(__DIR__));
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('STORAGE_PATH', BASE_PATH . '/storage');
//библиотеки php
require_once BASE_PATH . '/vendor/autoload.php';
//необходимое для сайта
$config = require_once INCLUDES_PATH . '/config.php';
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/helpers.php';
require_once INCLUDES_PATH . '/csrf.php';
require_once INCLUDES_PATH . '/auth.php';
//настройки сайта
require_once INCLUDES_PATH . '/services/SettingsService.php';
//связь с роутером
require_once INCLUDES_PATH . '/services/MikrotikService.php';
// конфиги
require_once INCLUDES_PATH . '/services/WireGuardService.php';
require_once INCLUDES_PATH . '/services/VpnService.php';
//сервисы для ссш и днс
require_once INCLUDES_PATH . '/services/ProxmoxService.php';
require_once INCLUDES_PATH . '/services/DnsService.php';
//ауф
require_once INCLUDES_PATH . '/services/YandexAuthService.php';
require_once INCLUDES_PATH . '/services/LoggerService.php';
