<?php
// Used by admin.js with a temporary fake document root and isolated JSON state.
namespace Bitrix\Main {
    class Loader { public static function includeModule($name) { return true; } }
    class Application { public static function getConnection() { return new \AdminFixtures\Connection(); } }
}
namespace Bitrix\Main\Config {
    class Option {
        public static function get($module, $key, $default) { return $default; }
        public static function set($module, $key, $value) { \AdminFixtures\record(['setting'=>$key]); }
    }
}
namespace PV\AbandonedCart {
    class Checkout {
        public static function methodName($kind, $value) { return $value; }
        public static function upgrade() { \AdminFixtures\record(['upgrade'=>true]); }
    }
}
namespace AdminFixtures {
    function record(array $entry) { file_put_contents(getenv('PV_TEST_LOG'), json_encode($entry) . "\n", FILE_APPEND); }
    function rows() { return json_decode(file_get_contents(getenv('PV_TEST_STATE')), true); }
    class Rows {
        private $rows;
        public function __construct($rows) { $this->rows = $rows; }
        public function fetchAll() { return $this->rows; }
    }
    class Connection {
        private $affected = 0;
        public function getSqlHelper() { return $this; }
        public function forSql($value) { return $value; }
        public function isTableExists($name) { return true; }
        public function query($sql) {
            $rows = rows();
            if (strpos($sql, 'GROUP BY STATUS') !== false) {
                $counts = [];
                foreach ($rows as $row) { $counts[$row['STATUS']] = ($counts[$row['STATUS']] ?? 0) + 1; }
                $stats = [];
                foreach ($counts as $status=>$count) { $stats[] = ['STATUS'=>$status, 'CNT'=>$count]; }
                return new Rows($stats);
            }
            if (strpos($sql, 'GROUP BY CURRENCY') !== false) {
                $totals = [];
                foreach ($rows as $row) {
                    if ($row['STATUS'] === 'abandoned') { $totals[$row['CURRENCY']] = ($totals[$row['CURRENCY']] ?? 0) + $row['TOTAL']; }
                }
                $stats = [];
                foreach ($totals as $currency=>$amount) { $stats[] = ['CURRENCY'=>$currency, 'AMOUNT'=>$amount]; }
                return new Rows($stats);
            }
            if (preg_match("/WHERE STATUS='([a-z]+)'/", $sql, $match)) {
                $rows = array_values(array_filter($rows, static function ($row) use ($match) { return $row['STATUS'] === $match[1]; }));
            }
            foreach ($rows as &$row) { if ($row['ID'] === 5) { $row['EMAIL'] = getenv('PV_TEST_EMAIL'); } }
            return new Rows(array_slice($rows, 0, 200));
        }
        public function queryExecute($sql) {
            record(['sql'=>$sql]);
            if (!preg_match('/^DELETE FROM pv_abandoned_cart WHERE ID IN \(([0-9,]+)\)$/D', $sql, $match)) { throw new \RuntimeException('Unexpected mutation'); }
            $ids = array_map('intval', explode(',', $match[1]));
            $before = rows();
            $after = array_values(array_filter($before, static function ($row) use ($ids) { return !in_array($row['ID'], $ids, true); }));
            $this->affected = count($before) - count($after);
            file_put_contents(getenv('PV_TEST_STATE'), json_encode($after));
        }
        public function getAffectedRowsCount() { return $this->affected; }
    }
    class User { public function IsAdmin() { return getenv('PV_TEST_ADMIN') !== 'no'; } }
    class AdminApplication {
        public function SetTitle($title) {}
        public function RestartBuffer() { while (ob_get_level()) { ob_end_clean(); } }
        public function GetCurPageParam($query, $remove) { return '/bitrix/admin/pv_abandonedcart_list.php?status=all&' . $query; }
        public function AuthForm($message) { echo json_encode(['denied'=>true]); exit; }
    }
}
namespace {
    set_error_handler(static function ($severity, $message) { throw new \ErrorException($message, 0, $severity); });
    $_SERVER['DOCUMENT_ROOT'] = getenv('PV_TEST_DOCUMENT_ROOT');
    $_SERVER['REQUEST_METHOD'] = ($argv[1] ?? '') === 'post' ? 'POST' : 'GET';
    $_GET = ['status'=>getenv('PV_TEST_STATUS') ?: 'all'];
    $_POST = json_decode(getenv('PV_TEST_POST') ?: '{}', true);
    if (($argv[1] ?? '') === 'refresh') { $_GET['pv_refresh'] = 'Y'; }
    $USER = new \AdminFixtures\User();
    $APPLICATION = new \AdminFixtures\AdminApplication();
    function htmlspecialcharsbx($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function bitrix_sessid_post() { return '<input type="hidden" name="sessid" value="test">'; }
    function check_bitrix_sessid() { return ($_POST['sessid'] ?? '') === 'test'; }
    function LocalRedirect($url) { echo json_encode(['redirect'=>$url]); exit; }
    require __DIR__ . '/../admin/list.php';
}
