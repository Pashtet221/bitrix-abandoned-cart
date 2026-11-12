<?php
// Run only via admin.js: fake Bitrix bootstraps are created in a temporary directory.
namespace Bitrix\Main {
    class Loader { public static function includeModule($name) { return true; } }
    class Application { public static function getConnection() { return new \AdminFixtures\Connection(); } }
}
namespace Bitrix\Main\Config {
    class Option { public static function get($module, $key, $default) { return $default; } }
}
namespace PV\AbandonedCart {
    class Checkout { public static function methodName($kind, $value) { return $value; } }
}
namespace AdminFixtures {
    class Rows {
        private $rows;
        public function __construct($rows) { $this->rows = $rows; }
        public function fetchAll() { return $this->rows; }
    }
    class Connection {
        public function getSqlHelper() { return $this; }
        public function forSql($value) { return $value; }
        public function isTableExists($name) { return true; }
        public function query($sql) {
            if (strpos($sql, 'GROUP BY') !== false) { return new Rows([['STATUS'=>'active', 'CNT'=>1, 'AMOUNT'=>200]]); }
            return new Rows([[
                'ID'=>5, 'STATUS'=>'active', 'FUSER_ID'=>42, 'SITE_ID'=>'s1',
                'CUSTOMER_NAME'=>'<script>bad()</script>', 'EMAIL'=>getenv('PV_TEST_EMAIL'), 'PHONE'=>'+7999',
                'ITEMS_JSON'=>'[{"name":"Товар","quantity":2}]', 'TOTAL'=>'200', 'CURRENCY'=>'RUB',
                'UPDATED_AT'=>'2026-11-12 16:50:00', 'ORDER_ID'=>null,
                'DELIVERY_ADDRESS'=>'Санкт-Петербург, Невский, 1', 'ORDER_COMMENT'=>null,
                'DELIVERY_METHOD'=>'Курьер', 'PAYMENT_METHOD'=>'Наличными', 'CHECKOUT_UPDATED_AT'=>'2026-11-12 16:50:01',
            ]]);
        }
    }
    class User { public function IsAdmin() { return true; } }
    class AdminApplication {
        public function SetTitle($title) {}
        public function RestartBuffer() { while (ob_get_level()) { ob_end_clean(); } }
    }
}
namespace {
    set_error_handler(static function ($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    $_SERVER['DOCUMENT_ROOT'] = getenv('PV_TEST_DOCUMENT_ROOT');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['status'=>'all'];
    if (($argv[1] ?? '') === 'refresh') { $_GET['pv_refresh'] = 'Y'; }
    $USER = new \AdminFixtures\User();
    $APPLICATION = new \AdminFixtures\AdminApplication();
    function htmlspecialcharsbx($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function bitrix_sessid_post() { return '<input type="hidden" name="sessid" value="test">'; }
    require __DIR__ . '/../admin/list.php';
}
