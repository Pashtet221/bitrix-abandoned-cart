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
        $modulePath = strpos(str_replace('\\', '/', __DIR__), '/local/modules/') !== false ? '/local/modules' : '/bitrix/modules';
        Asset::getInstance()->addJs($modulePath . '/pv.abandonedcart/assets/checkout.js?v=1.0.3');
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

    private static function resolveInput(array $input): array
    {
        // Standard sale.order.ajax uses ORDER_PROP_<numeric ID>, without field codes.
        $raw = $input['properties'] ?? '{}';
        $properties = is_string($raw) && strlen($raw) <= 32768 ? json_decode($raw, true) : null;
        if (is_array($properties) && $properties) {
            $ids = array_filter(array_map('intval', array_keys($properties)), static function ($id) { return $id > 0; });
            if ($ids) {
                $rows = \Bitrix\Sale\Internals\OrderPropsTable::getList([
                    'filter' => ['@ID' => $ids, '=ACTIVE' => 'Y'],
                ]);
                $address = [];
                $names = [];
                while ($property = $rows->fetch()) {
                    $value = $properties[$property['ID']] ?? null;
                    if (!is_string($value) || trim($value) === '') { continue; }
                    $code = strtoupper((string)$property['CODE']);
                    if (($property['IS_EMAIL'] ?? 'N') === 'Y' || $code === 'EMAIL') { $input['email'] = $value; }
                    elseif (($property['IS_PHONE'] ?? 'N') === 'Y' || in_array($code, ['PHONE', 'MOBILE'], true)) { $input['phone'] = $value; }
                    elseif (($property['IS_PAYER'] ?? 'N') === 'Y' || in_array($code, ['FIO', 'NAME', 'LAST_NAME', 'SECOND_NAME'], true)) { $names[] = $value; }
                    elseif ($property['TYPE'] === 'LOCATION' || ($property['IS_LOCATION'] ?? 'N') === 'Y') {
                        $location = \Bitrix\Sale\Location\LocationTable::getList([
                            'filter' => ['=CODE' => $value, '=NAME.LANGUAGE_ID' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'],
                            'select' => ['LOCATION_NAME' => 'NAME.NAME'], 'limit' => 1,
                        ])->fetch();
                        if ($location && $location['LOCATION_NAME']) { $address[] = $location['LOCATION_NAME']; }
                    } elseif (($property['IS_ADDRESS'] ?? 'N') === 'Y' || ($property['IS_ZIP'] ?? 'N') === 'Y' || in_array($code, ['ADDRESS', 'CITY', 'ZIP', 'STREET', 'HOUSE', 'FLAT'], true)) {
                        $address[] = $value;
                    }
                }
                if ($names) { $input['name'] = implode(' ', array_unique($names)); }
                if ($address) { $input['address'] = implode(', ', array_unique($address)); }
            }
        }
        foreach (['delivery', 'payment'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = self::methodName($key, $input[$key]);
            }
        }
        return $input;
    }

    public static function methodName(string $kind, string $value): string
    {
        if (!preg_match('/^[0-9]+$/D', $value) || !Loader::includeModule('sale')) { return $value; }
        static $cache = [];
        $key = $kind . ':' . $value;
        if (!array_key_exists($key, $cache)) {
            $service = $kind === 'delivery'
                ? \Bitrix\Sale\Delivery\Services\Manager::getById((int)$value)
                : \Bitrix\Sale\PaySystem\Manager::getById((int)$value);
            $cache[$key] = $service && !empty($service['NAME']) ? (string)$service['NAME'] : $value;
        }
        return $cache[$key];
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
        $input = self::resolveInput($input);
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
