<?php
// Isolated API adapters: these regression tests do not replace a live Bitrix test.
namespace Fixtures {
    class Rows {
        private $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function fetchAll() { return $this->rows; }
        public function fetch() { return array_shift($this->rows) ?: false; }
    }
    class Connection {
        public $record = ['ID'=>12, 'STATUS'=>'active', 'CHECKOUT_DATA'=>null];
        public $updates = [];
        public $queries = [];
        public $columns = [];
        public $alterations = 0;
        public function isTableExists($table) { return true; }
        public function getSqlHelper() { return $this; }
        public function forSql($value) { return str_replace("'", "''", $value); }
        public function query($sql) {
            $this->queries[] = $sql;
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return new Rows(array_map(static function ($column) { return ['Field'=>$column]; }, $this->columns));
            }
            return new Rows($this->record ? [$this->record] : []);
        }
        public function queryExecute($sql) {
            if (preg_match('/ADD COLUMN ([A-Z_]+)/', $sql, $match)) {
                $this->columns[] = $match[1]; ++$this->alterations; return;
            }
            $this->updates[] = $sql;
            if (strpos($sql, 'INSERT') === 0) { $this->record = ['ID'=>13, 'STATUS'=>'active', 'CHECKOUT_DATA'=>null]; }
            if (strpos($sql, 'DELETE') === 0) { $this->record = null; return; }
            if (preg_match("/STATUS='([a-z]+)'/", $sql, $match)) { $this->record['STATUS'] = $match[1]; }
            if (preg_match("/CHECKOUT_DATA='((?:''|[^'])*)'/", $sql, $match)) { $this->record['CHECKOUT_DATA'] = str_replace("''", "'", $match[1]); }
            if (strpos($sql, 'CHECKOUT_DATA=NULL') !== false) { $this->record['CHECKOUT_DATA'] = null; }
        }
        public function latest() { return end($this->updates); }
    }
    class Item {
        public function isDelay() { return false; }
        public function canBuy() { return true; }
        public function getQuantity() { return 2; }
        public function getPrice() { return 100; }
        public function getCurrency() { return 'RUB'; }
        public function getProductId() { return 99; }
        public function getField($field) { return 'Тестовый товар'; }
    }
}
namespace Bitrix\Main {
    class EventManager {
        public static function getInstance() { return new self(); }
        public function unRegisterEventHandler(...$args) {}
        public function registerEventHandler(...$args) {}
    }
    class Event {
        private $parameters;
        public function __construct(array $parameters) { $this->parameters = $parameters; }
        public function getParameter($name) { return $this->parameters[$name] ?? null; }
    }
    class Loader { public static function includeModule($module) { return true; } }
    class Application { public static $connection; public static function getConnection() { return self::$connection; } }
    class SiteTable {
        public static function getById($id) { return new \Fixtures\Rows(in_array($id, ['s1','s2'], true) ? [['LID'=>$id, 'ACTIVE'=>'Y']] : []); }
    }
}
namespace Bitrix\Sale {
    class Fuser { public static function getId($skipCreate = false) { return 42; } }
    class BasketBase implements \IteratorAggregate {
        public $items = [];
        public $orderId = 0;
        public function getFUserId() { return 42; }
        public function getSiteId() { return 's1'; }
        public function getOrderId() { return $this->orderId; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
    }
    class Basket extends BasketBase {
        public static $loaded;
        public static function loadItemsForFUser($fuser, $site) { return self::$loaded; }
    }
    class Order {
        public function getId() { return 123; }
        public function getBasket() { return new Basket(); }
        public function getSiteId() { return 's1'; }
    }
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
            return new \Fixtures\Rows(array_filter($rows, static function ($row) use ($options, $rows) { return in_array($row['ID'], ($options['filter']['@ID'] ?? array_column($rows, 'ID'))); }));
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
namespace Bitrix\Main\Config {
    class Option {
        public static $capture = 'Y';
        public static function get($module, $name, $default) { return self::$capture; }
    }
}
namespace Bitrix\Main\Page {
    class Asset {
        public static $strings = [];
        public static $scripts = [];
        public static function getInstance() { return new self(); }
        public function addString($value) { self::$strings[] = $value; }
        public function addJs($value) { self::$scripts[] = $value; }
    }
}
namespace {
    use PV\AbandonedCart\Checkout;
    use PV\AbandonedCart\Tracker;
    set_error_handler(static function ($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    define('SITE_ID', 's1');
    require __DIR__ . '/../lib/checkout.php';
    require __DIR__ . '/../lib/tracker.php';
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    function capture(array $input) { return Checkout::capture($input + ['consent'=>'Y']); }
    function containsAll($sql, array $values) {
        foreach ($values as $value) { check(strpos($sql, $value) !== false, 'Missing ' . $value); }
    }
    $connection = new \Fixtures\Connection();
    \Bitrix\Main\Application::$connection = $connection;
    \Bitrix\Sale\Basket::$loaded = new \Bitrix\Sale\Basket();
    $input = ['properties'=>json_encode([1=>'Иван Иванов', 2=>'test@example.test', 3=>'+7 999 123-45-67', 4=>'0000073738', 5=>'190000', 6=>"Невский, д. 1", 7=>'do-not-save']), 'delivery'=>'3', 'payment'=>'2'];
    check(capture($input)['ok'], 'capture succeeds');
    containsAll($connection->latest(), ['Иван Иванов','test@example.test','+7 999','Санкт-Петербург','190000','Невский','Курьер','Наличными при получении','CHECKOUT_UPDATED_AT=NOW()']);
    check(strpos($connection->latest(), 'do-not-save') === false, 'Unrelated property excluded');
    check(Checkout::methodName('delivery', '3') === 'Курьер', 'Legacy delivery ID');
    check(Checkout::methodName('payment', '2') === 'Наличными при получении', 'Legacy payment ID');
    check(Checkout::methodName('delivery', '999') === '999', 'Deleted service preserves ID');
    $id = $connection->record['ID'];
    check(capture(['properties'=>json_encode([3=>'+7 999 000-00-00', 5=>'190001'])])['ok'], 'Partial checkout update');
    check($connection->record['ID'] === $id, 'Updates same basket row');
    containsAll($connection->latest(), ['Иван Иванов','test@example.test','+7 999 000-00-00','Санкт-Петербург','190001','Невский']);
    check(capture(['properties'=>json_encode([2=>''])])['ok'], 'Explicit field clearing');
    containsAll($connection->latest(), ["EMAIL=''", 'Санкт-Петербург','Невский']);
    $connection->record['STATUS'] = 'abandoned';
    check(capture(['comment'=>'Продолжаю оформление'])['ok'], 'Resuming abandoned checkout');
    check($connection->record['STATUS'] === 'active', 'Customer activity reactivates basket');
    $connection->record['STATUS'] = 'ordered';
    $before = count($connection->updates);
    check(!capture($input)['ok'] && count($connection->updates) === $before, 'Completed order protected from late capture when customer basket empty');
    $orderBasket = new \Bitrix\Sale\Basket();
    $orderBasket->orderId = 123;
    $orderBasket->items = [new \Fixtures\Item()];
    Tracker::syncBasket($orderBasket);
    check(count($connection->updates) === $before, 'Order basket save must not reopen order');
    Tracker::onOrderSaved(new \Bitrix\Main\Event(['ENTITY'=>new \Bitrix\Sale\Order(), 'IS_NEW'=>false]));
    check(count($connection->updates) === $before, 'Editing old order cannot close new basket');
    $newBasket = new \Bitrix\Sale\Basket(); $newBasket->items = [new \Fixtures\Item()];
    \Bitrix\Sale\Basket::$loaded = $newBasket;
    check(capture(['properties'=>json_encode([3=>'+7 111'])])['ok'], 'New customer basket after completed order');
    containsAll($connection->updates[$before], ['EMAIL=NULL', 'CHECKOUT_DATA=NULL', 'ORDER_ID=NULL']);
    check(strpos($connection->latest(), 'test@example.test') === false, 'New basket does not inherit old contacts');
    $connection->record = null;
    check(capture($input)['ok'], 'Backfill basket that predates module event tracking');
    $createdId = $connection->record['ID'];
    check(capture(['phone'=>'+7 222'])['ok'] && $connection->record['ID'] === $createdId, 'No duplicate row on repeated capture');
    containsAll($connection->latest(), ["PHONE='+7 222'"]);
    $connection->record = null;
    \Bitrix\Sale\Basket::$loaded = new \Bitrix\Sale\Basket();
    $before = count($connection->updates);
    check(!capture($input)['ok'] && count($connection->updates) === $before, 'No contacts-only row without a real basket');
    check(!capture(['site_id'=>'bad'])['ok'], 'Unknown site rejected');
    \Bitrix\Sale\Basket::$loaded = $newBasket;
    check(capture(['site_id'=>'s2', 'phone'=>'+7 333'])['ok'], 'Explicit current checkout site');
    check(strpos(end($connection->queries), "SITE_ID='s2'") !== false, 'Request uses originating site, not endpoint default site');
    $connection->record['CHECKOUT_DATA'] = null;
    $before = count($connection->updates);
    check(capture(['properties'=>['invalid']])['ok'], 'Malformed properties ignored');
    check(count($connection->updates) === $before, 'Malformed properties do not write');
    \Bitrix\Main\Config\Option::$capture = 'N';
    check(!capture($input)['ok'], 'Server opt-in required');
    \Bitrix\Main\Config\Option::$capture = 'Y';
    check(!Checkout::capture($input)['ok'], 'Server requires consent claim');
    Checkout::upgrade(); Checkout::upgrade();
    check($connection->alterations === 6 && count($connection->updates) === $before, 'Upgrade repeatable without deleting basket data');
    Checkout::onProlog();
    containsAll(\Bitrix\Main\Page\Asset::$strings[0], ['captureUrl', 'pv.abandonedcart/tools/capture.php', 'siteId', 's1']);
    check(strpos(\Bitrix\Main\Page\Asset::$scripts[0], 'v=1.0.6') !== false, 'Versioned script');
    echo "PASS: contacts, location, service names, partial updates, clearing, stable row, activity, order guards, new basket reset, basket backfill, site context, repeatable upgrade, asset config\n";
}
