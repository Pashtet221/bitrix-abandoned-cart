<?php
namespace PV\AbandonedCart;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Sale\Fuser;

class Checkout
{
    public static function onProlog(): void
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) { return; }
        if (!Loader::includeModule('sale')) { return; }
        Asset::getInstance()->addJs('/local/modules/pv.abandonedcart/assets/checkout.js');
    }

    public static function upgrade(): void
    {
        $connection = Application::getConnection();
        if (!$connection->isTableExists('pv_abandoned_cart')) {
            throw new \RuntimeException('Table pv_abandoned_cart not found');
        }
        $columns = $connection->query('SHOW COLUMNS FROM pv_abandoned_cart')->fetchAll();
        $existing = array_column($columns, 'Field');
        foreach (['DELIVERY_ADDRESS' => 2000, 'ORDER_COMMENT' => 2000, 'DELIVERY_METHOD' => 255, 'PAYMENT_METHOD' => 255] as $column => $length) {
            if (!in_array($column, $existing, true)) {
                $connection->queryExecute('ALTER TABLE pv_abandoned_cart ADD COLUMN ' . $column . ' VARCHAR(' . $length . ') NULL');
            }
        }
        $events = \Bitrix\Main\EventManager::getInstance();
        $events->unRegisterEventHandler('main', 'OnProlog', 'pv.abandonedcart', '\\PV\\AbandonedCart\\Checkout', 'onProlog');
        $events->registerEventHandler('main', 'OnProlog', 'pv.abandonedcart', '\\PV\\AbandonedCart\\Checkout', 'onProlog');
    }

    public static function capture(array $input): array
    {
        if (!Loader::includeModule('sale')) { return ['ok' => false, 'message' => 'Sale unavailable']; }
        $siteId = defined('SITE_ID') ? (string)SITE_ID : '';
        $fuserId = (int)Fuser::getId();
        if ($siteId === '' || $fuserId <= 0) { return ['ok' => false, 'message' => 'Basket unavailable']; }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $table = 'pv_abandoned_cart';
        if (!$connection->isTableExists($table)) { return ['ok' => false, 'message' => 'Module table missing']; }
        $exists = $connection->query('SELECT ID, STATUS FROM ' . $table . ' WHERE FUSER_ID=' . $fuserId . " AND SITE_ID='" . $helper->forSql($siteId) . "'")->fetch();
        // Never create a contact record without a basket; do not overwrite completed orders.
        if (!$exists || $exists['STATUS'] === 'ordered') { return ['ok' => false, 'message' => 'No active basket']; }
        $map = [
            'name' => ['CUSTOMER_NAME', 255], 'email' => ['EMAIL', 255],
            'phone' => ['PHONE', 80], 'address' => ['DELIVERY_ADDRESS', 2000],
            'comment' => ['ORDER_COMMENT', 2000], 'delivery' => ['DELIVERY_METHOD', 255],
            'payment' => ['PAYMENT_METHOD', 255],
        ];
        $sets = [];
        foreach ($map as $key => [$column, $max]) {
            if (!array_key_exists($key, $input) || !is_string($input[$key])) { continue; }
            $value = trim(strip_tags($input[$key]));
            $value = mb_substr($value, 0, $max);
            $sets[] = $column . "='" . $helper->forSql($value) . "'";
        }
        if (!$sets) { return ['ok' => true]; }
        $sets[] = 'UPDATED_AT=NOW()';
        $connection->queryExecute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ID=' . (int)$exists['ID'] . " AND STATUS IN ('active','abandoned')");
        return ['ok' => true];
    }
}
