<?php
// Isolated regression tests for the Bitrix adapter; no production database is used.
namespace Fixtures {
    class Rows {
        private $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function fetchAll() { return $this->rows; }
        public function fetch() { return array_shift($this->rows) ?: false; }
    }
    class Connection {
        public $status = 'active';
        public $updates = [];
        public $columns = [];
        public $alterations = 0;
        public function isTableExists($table) { return true; }
        public function getSqlHelper() { return $this; }
        public function forSql($value) { return str_replace("'", "''", $value); }
        public function query($sql) { if (strpos($sql, 'SHOW COLUMNS') === 0) { return new Rows(array_map(static function ($column) { return ['Field' => $column]; }, $this->columns)); } return new Rows([['ID' => 12, 'STATUS' => $this->status]]); }
        public function queryExecute($sql) {
            if (preg_match('/ADD COLUMN ([A-Z_]+)/', $sql, $match)) { $this->columns[] = $match[1]; ++$this->alterations; return; }
            $this->updates[] = $sql;
        }
    }
}
namespace Bitrix\Main {
    class EventManager {
        public static function getInstance() { return new self(); }
        public function unRegisterEventHandler(...$args) {}
        public function registerEventHandler(...$args) {}
    }
    class Loader { public static function includeModule($module) { return true; } }
    class Application { public static $connection; public static function getConnection() { return self::$connection; } }
}
namespace Bitrix\Sale {
    class Fuser { public static function getId() { return 42; } }
}
namespace Bitrix\Sale\Internals {
    class OrderPropsTable {
        public static function getList($options) {
            $rows = [
                ['ID'=>1, 'CODE'=>'FIO', 'TYPE'=>'STRING', 'IS_PAYER'=>'Y'],
                ['ID'=>2, 'CODE'=>'CUSTOM_MAIL', 'TYPE'=>'STRING', 'IS_EMAIL'=>'Y'],
                ['ID'=>3, 'CODE'=>'CUSTOM_PHONE', 'TYPE'=>'STRING', 'IS_PHONE'=>'Y'],
                ['ID'=>4, 'CODE'=>'LOCATION', 'TYPE'=>'LOCATION'],
                ['ID'=>5, 'CODE'=>'ZIP', 'TYPE'=>'STRING'],
                ['ID'=>6, 'CODE'=>'ADDRESS', 'TYPE'=>'STRING'],
                ['ID'=>7, 'CODE'=>'UNRELATED', 'TYPE'=>'STRING'],
            ];
            return new \Fixtures\Rows(array_filter($rows, static function ($row) use ($options) { return in_array($row['ID'], $options['filter']['@ID']); }));
        }
    }
}
namespace Bitrix\Sale\Location {
    class LocationTable {
        public static function getList($options) {
            return new \Fixtures\Rows($options['filter']['=CODE'] === '0000073738' ? [['LOCATION_NAME'=>'Санкт-Петербург']] : []);
        }
    }
}
namespace Bitrix\Sale\Delivery\Services {
    class Manager { public static function getById($id) { return $id === 3 ? ['NAME'=>'Курьер'] : false; } }
}
namespace Bitrix\Sale\PaySystem {
    class Manager { public static function getById($id) { return $id === 2 ? ['NAME'=>'Наличными при получении'] : false; } }
}
namespace {
    set_error_handler(static function ($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    define('SITE_ID', 's1');
    require __DIR__ . '/../lib/checkout.php';
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    $connection = new \Fixtures\Connection();
    \Bitrix\Main\Application::$connection = $connection;
    $input = ['properties'=>json_encode([1=>'Иван Иванов', 2=>'test@example.test', 3=>'+7 999 123-45-67', 4=>'0000073738', 5=>'190000', 6=>"Невский, д. 1", 7=>'do-not-save']), 'delivery'=>'3', 'payment'=>'2'];
    check(\PV\AbandonedCart\Checkout::capture($input)['ok'], 'capture succeeds');
    $sql = $connection->updates[0];
    foreach (['Иван Иванов', 'test@example.test', '+7 999', 'Санкт-Петербург', '190000', 'Невский', 'Курьер', 'Наличными при получении'] as $expected) {
        check(strpos($sql, $expected) !== false, 'Missing ' . $expected);
    }
    check(strpos($sql, 'do-not-save') === false, 'Unrelated property excluded');
    check(\PV\AbandonedCart\Checkout::methodName('delivery', '3') === 'Курьер', 'Legacy delivery ID');
    check(\PV\AbandonedCart\Checkout::methodName('payment', '2') === 'Наличными при получении', 'Legacy payment ID');
    check(\PV\AbandonedCart\Checkout::methodName('delivery', '999') === '999', 'Deleted service preserves ID');
    $connection->status = 'ordered';
    check(!\PV\AbandonedCart\Checkout::capture($input)['ok'] && count($connection->updates) === 1, 'Completed order protected');
    $connection->status = 'active';
    check(\PV\AbandonedCart\Checkout::capture(['properties'=>['invalid']])['ok'], 'Malformed properties ignored');
    check(count($connection->updates) === 1, 'Malformed properties do not write');
    \PV\AbandonedCart\Checkout::upgrade();
    \PV\AbandonedCart\Checkout::upgrade();
    check($connection->alterations === 4 && count($connection->updates) === 1, 'Upgrade repeatable without deleting basket data');
    echo "PASS: numeric properties, contacts, location, service names, legacy IDs, malformed input, ordered guard, repeatable upgrade\n";
}
