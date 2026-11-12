<?php
namespace InstallerFixtures {
    class Connection {
        public $exists = false;
        public $columns = [];
        public $statements = [];
        public function isTableExists($table) { return $this->exists; }
        public function queryExecute($sql) {
            $this->statements[] = $sql;
            if (strpos($sql, 'CREATE TABLE') === 0) { $this->exists = true; }
            if (strpos($sql, 'DROP TABLE') === 0) { $this->exists = false; $this->columns = []; }
            if (preg_match('/ADD COLUMN ([A-Z_]+)/', $sql, $match)) { $this->columns[] = $match[1]; }
        }
        public function query($sql) { return new Rows(array_map(static function ($column) { return ['Field'=>$column]; }, $this->columns)); }
    }
    class Rows {
        private $rows;
        public function __construct($rows) { $this->rows = $rows; }
        public function fetchAll() { return $this->rows; }
    }
    class User { public $admin = true; public function IsAdmin() { return $this->admin; } }
    class Admin {
        public $errors = [];
        public $included;
        public function ThrowException($message) { $this->errors[] = $message; }
        public function IncludeAdminFile($title, $path) { $this->included = $path; }
    }
}
namespace Bitrix\Main\Localization {
    class Loc {
        public static $messages = [];
        public static function loadMessages($file) {
            $path = dirname(__DIR__) . '/lang/ru/install/index.php';
            $MESS = []; require $path; self::$messages = $MESS;
        }
        public static function getMessage($key) { return self::$messages[$key] ?? $key; }
    }
}
namespace Bitrix\Main {
    class ModuleManager {
        public static $installed = [];
        public static function registerModule($id) { self::$installed[$id] = true; }
        public static function unRegisterModule($id) { unset(self::$installed[$id]); }
    }
    class Application { public static $connection; public static function getConnection() { return self::$connection; } }
    class EventManager {
        public static $handlers = [];
        public static function getInstance() { return new self(); }
        public function registerEventHandler(...$args) { self::$handlers[] = $args; }
        public function unRegisterEventHandler(...$args) { self::$handlers = array_values(array_filter(self::$handlers, static function ($handler) use ($args) { return $handler !== $args; })); }
    }
    class Loader {
        public static $sale = true;
        public static $module = true;
        public static function includeModule($id) {
            if ($id === 'sale') { return self::$sale; }
            if (!self::$module) { return false; }
            require __DIR__ . '/../include.php';
            return true;
        }
        public static function registerAutoLoadClasses($module, $classes) {
            spl_autoload_register(static function ($name) use ($classes) {
                if (isset($classes[$name])) { require_once __DIR__ . '/../' . $classes[$name]; }
            });
        }
    }
}
namespace Bitrix\Main\Config {
    class Option {
        public static $values = [];
        public static function get($module, $key, $default) { return self::$values[$key] ?? $default; }
        public static function set($module, $key, $value) { self::$values[$key] = $value; }
        public static function delete($module) { self::$values = []; }
    }
}
namespace {
    define('B_PROLOG_INCLUDED', true);
    set_error_handler(static function ($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    class CModule {}
    class CAgent {
        public static $agents = [];
        public static function RemoveModuleAgents($id) { self::$agents = []; }
        public static function AddAgent(...$args) { self::$agents[] = $args; }
    }
    function check_bitrix_sessid() { return $GLOBALS['validSession']; }
    function CopyDirFiles($source, $target, $rewrite, $recursive) {
        if (!$GLOBALS['copyWorks']) { return false; }
        return copy($source . '/pv_abandonedcart_list.php', $target . '/pv_abandonedcart_list.php');
    }
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    $root = sys_get_temp_dir() . '/pv-installer-' . bin2hex(random_bytes(6));
    mkdir($root . '/bitrix/admin', 0700, true);
    $_SERVER['DOCUMENT_ROOT'] = $root;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $validSession = true; $copyWorks = true;
    $USER = new \InstallerFixtures\User();
    $APPLICATION = new \InstallerFixtures\Admin();
    $connection = new \InstallerFixtures\Connection();
    \Bitrix\Main\Application::$connection = $connection;
    require __DIR__ . '/../install/index.php';
    $module = new pv_abandonedcart();
    try {
        $USER->admin = false;
        check($module->DoInstall() === false && !$connection->exists, 'Install denied without administrator');
        $USER->admin = true; $validSession = false;
        check($module->DoInstall() === false && !$connection->exists, 'Install denied without valid session');
        $validSession = true; \Bitrix\Main\Loader::$sale = false;
        check($module->DoInstall() === false && !$connection->exists, 'Sale module prerequisite');
        \Bitrix\Main\Loader::$sale = true;
        check($module->DoInstall() === true, 'Clean installation includes module autoload before upgrade');
        check($connection->exists && count($connection->columns) === 6, 'Schema installed');
        check(count(\Bitrix\Main\EventManager::$handlers) === 3 && count(CAgent::$agents) === 1, 'All handlers and one agent registered');
        check(is_file($root . '/bitrix/admin/pv_abandonedcart_list.php'), 'Public admin entry copied');
        \Bitrix\Main\Config\Option::$values['timeout_minutes'] = '65';
        check($module->DoInstall() === true, 'Repeated installation');
        check(count(\Bitrix\Main\EventManager::$handlers) === 3 && count(CAgent::$agents) === 1 && count($connection->columns) === 6, 'No duplicate handlers, agents or columns');
        check(\Bitrix\Main\Config\Option::$values['timeout_minutes'] === '65', 'Existing settings preserved');
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
        $module->DoUninstall();
        check($connection->exists && isset(\Bitrix\Main\ModuleManager::$installed[$module->MODULE_ID]), 'First uninstall step only asks about data retention');
        check(basename($APPLICATION->included) === 'unstep.php', 'Uninstall confirmation form');
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['step'=>'2','savedata'=>'Y']; $validSession = false;
        check($module->DoUninstall() === false && $connection->exists, 'Uninstall CSRF protection');
        $validSession = true;
        check($module->DoUninstall() === true && $connection->exists, 'Explicit keep-data uninstall');
        check(!\Bitrix\Main\EventManager::$handlers && !CAgent::$agents && !\Bitrix\Main\ModuleManager::$installed, 'No dangling registrations');
        check(!is_file($root . '/bitrix/admin/pv_abandonedcart_list.php'), 'Admin entry removed');
        check($module->DoInstall() === true, 'Reinstallation with retained schema');
        $_POST = ['step'=>'2'];
        check($module->DoUninstall() === true && !$connection->exists, 'Explicit data-removal uninstall');
        $copyWorks = false;
        check($module->DoInstall() === false && !\Bitrix\Main\ModuleManager::$installed && !\Bitrix\Main\EventManager::$handlers && !CAgent::$agents, 'Copy failure reported and registrations rolled back');
        echo "PASS: clean install, autoload, prerequisites, session and access, repeat install, retained settings, both uninstall modes, copy-failure rollback\n";
    } finally {
        $entry = $root . '/bitrix/admin/pv_abandonedcart_list.php'; if (is_file($entry)) { unlink($entry); }
        rmdir($root . '/bitrix/admin'); rmdir($root . '/bitrix'); rmdir($root);
    }
}
