<?php
namespace PV\AbandonedCart;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Page\Asset;
use Bitrix\Sale\Fuser;

class Checkout
{
    public static function onProlog(): void
    {
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) { return; }
        if (!Loader::includeModule('sale')) { return; }
        $modulePath = strpos(str_replace('\\', '/', __DIR__), '/local/modules/') !== false ? '/local/modules' : '/bitrix/modules';
        $contactCapture = Option::get('pv.abandonedcart', 'capture_contacts', 'N') === 'Y';
        $allowedProperties = [];
        if ($contactCapture) {
            $properties = \Bitrix\Sale\Internals\OrderPropsTable::getList(['filter' => ['=ACTIVE' => 'Y']]);
            while ($property = $properties->fetch()) {
                if (self::propertyRole($property) !== null) { $allowedProperties[] = (string)$property['ID']; }
            }
        }
        $config = json_encode([
            'captureUrl' => $modulePath . '/pv.abandonedcart/tools/capture.php',
            'siteId' => defined('SITE_ID') ? SITE_ID : '',
            'contactCapture' => $contactCapture,
            'allowedProperties' => $allowedProperties,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        Asset::getInstance()->addString('<script>window.PVAbandonedCartConfig=' . $config . ';</script>');
        Asset::getInstance()->addJs($modulePath . '/pv.abandonedcart/assets/checkout.js?v=1.0.6');
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
        if (!in_array('CHECKOUT_DATA', $existing, true)) {
            $connection->queryExecute('ALTER TABLE pv_abandoned_cart ADD COLUMN CHECKOUT_DATA LONGTEXT NULL');
        }
        if (!in_array('CHECKOUT_UPDATED_AT', $existing, true)) {
            $connection->queryExecute('ALTER TABLE pv_abandoned_cart ADD COLUMN CHECKOUT_UPDATED_AT DATETIME NULL');
        }
        $events = \Bitrix\Main\EventManager::getInstance();
        $events->unRegisterEventHandler('main', 'OnProlog', 'pv.abandonedcart', '\\PV\\AbandonedCart\\Checkout', 'onProlog');
        $events->registerEventHandler('main', 'OnProlog', 'pv.abandonedcart', '\\PV\\AbandonedCart\\Checkout', 'onProlog');
    }

    private static function propertyRole(array $property): ?string
    {
        $code = strtoupper((string)($property['CODE'] ?? ''));
        if (($property['IS_EMAIL'] ?? 'N') === 'Y' || $code === 'EMAIL') { return 'email'; }
        if (($property['IS_PHONE'] ?? 'N') === 'Y' || in_array($code, ['PHONE', 'MOBILE'], true)) { return 'phone'; }
        if (($property['IS_PAYER'] ?? 'N') === 'Y' || in_array($code, ['FIO', 'NAME', 'LAST_NAME', 'SECOND_NAME'], true)) { return 'name'; }
        if (($property['TYPE'] ?? '') === 'LOCATION' || ($property['IS_LOCATION'] ?? 'N') === 'Y') { return 'location'; }
        if (($property['IS_ADDRESS'] ?? 'N') === 'Y' || ($property['IS_ZIP'] ?? 'N') === 'Y' || in_array($code, ['ADDRESS', 'CITY', 'ZIP', 'STREET', 'HOUSE', 'FLAT'], true)) { return 'address'; }
        return null;
    }

    private static function resolveInput(array $input, array $savedProperties = []): array
    {
        // Standard sale.order.ajax uses ORDER_PROP_<numeric ID>, without field codes.
        $raw = $input['properties'] ?? '{}';
        $properties = is_string($raw) && strlen($raw) <= 32768 ? json_decode($raw, true) : null;
        $incomingProperties = is_array($properties) ? $properties : [];
        $properties = array_replace($savedProperties, $incomingProperties);
        if ($properties) {
            $ids = array_filter(array_map('intval', array_keys($properties)), static function ($id) { return $id > 0; });
            if ($ids) {
                $rows = \Bitrix\Sale\Internals\OrderPropsTable::getList([
                    'filter' => ['@ID' => $ids, '=ACTIVE' => 'Y'],
                ]);
                $address = [];
                $names = [];
                $knownProperties = [];
                while ($property = $rows->fetch()) {
                    $value = $properties[$property['ID']] ?? null;
                    if (!is_string($value)) { continue; }
                    $value = mb_substr(trim(strip_tags($value)), 0, 2000);
                    $known = true;
                    $role = self::propertyRole($property);
                    if ($role === 'email') {
                        if (!array_key_exists($property['ID'], $incomingProperties) && isset($input['email']) && is_string($input['email'])) { $value = $input['email']; }
                        $input['email'] = $value;
                    }
                    elseif ($role === 'phone') {
                        if (!array_key_exists($property['ID'], $incomingProperties) && isset($input['phone']) && is_string($input['phone'])) { $value = $input['phone']; }
                        $input['phone'] = $value;
                    }
                    elseif ($role === 'name') { $names[] = $value; }
                    elseif ($role === 'location') {
                        $location = \Bitrix\Sale\Location\LocationTable::getList([
                            'filter' => ['=CODE' => $value, '=NAME.LANGUAGE_ID' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'],
                            'select' => ['LOCATION_NAME' => 'NAME.NAME'], 'limit' => 1,
                        ])->fetch();
                        if ($location && $location['LOCATION_NAME']) { $address[] = $location['LOCATION_NAME']; }
                        elseif ($value === '') { $address[] = ''; }
                    } elseif ($role === 'address') {
                        $address[] = $value;
                    } else { $known = false; }
                    if ($known) { $knownProperties[$property['ID']] = mb_substr(trim(strip_tags($value)), 0, 2000); }
                }
                $input['_properties'] = $knownProperties;
                if ($names) { $input['name'] = implode(' ', array_unique(array_filter($names, 'strlen'))); }
                if ($address) { $input['address'] = implode(', ', array_unique(array_filter($address, 'strlen'))); }
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
        if (Option::get('pv.abandonedcart', 'capture_contacts', 'N') !== 'Y' || ($input['consent'] ?? '') !== 'Y') {
            return ['ok' => false, 'message' => 'Contact capture is disabled or consent is missing'];
        }
        if (!Loader::includeModule('sale')) { return ['ok' => false, 'message' => 'Sale unavailable']; }
        $siteId = defined('SITE_ID') ? (string)SITE_ID : '';
        if (!empty($input['site_id']) && is_string($input['site_id'])) {
            $site = \Bitrix\Main\SiteTable::getById($input['site_id'])->fetch();
            if (!$site || $site['ACTIVE'] !== 'Y') { return ['ok' => false, 'message' => 'Site unavailable']; }
            $siteId = (string)$site['LID'];
        }
        $fuserId = (int)Fuser::getId(true);
        if ($siteId === '' || $fuserId <= 0) { return ['ok' => false, 'message' => 'Basket unavailable']; }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $table = 'pv_abandoned_cart';
        if (!$connection->isTableExists($table)) { return ['ok' => false, 'message' => 'Module table missing']; }
        $query = 'SELECT ID, STATUS, CHECKOUT_DATA FROM ' . $table . ' WHERE FUSER_ID=' . $fuserId . " AND SITE_ID='" . $helper->forSql($siteId) . "'";
        $exists = $connection->query($query)->fetch();
        // Event tracking may not have created the row yet (e.g. module installed after basket creation).
        if (!$exists || $exists['STATUS'] === 'ordered') {
            $basket = \Bitrix\Sale\Basket::loadItemsForFUser($fuserId, $siteId);
            Tracker::syncBasket($basket);
            $exists = $connection->query($query)->fetch();
        }
        // Never create a contact record without a basket; do not overwrite completed orders.
        if (!$exists || $exists['STATUS'] === 'ordered') { return ['ok' => false, 'message' => 'No active basket']; }
        $saved = json_decode((string)($exists['CHECKOUT_DATA'] ?? '{}'), true);
        $input = self::resolveInput($input, is_array($saved) ? $saved : []);
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
        if (isset($input['_properties'])) {
            $json = json_encode($input['_properties'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $sets[] = "CHECKOUT_DATA='" . $helper->forSql($json) . "'";
        }
        if (!$sets) { return ['ok' => true]; }
        $sets[] = 'UPDATED_AT=NOW()';
        $sets[] = 'CHECKOUT_UPDATED_AT=NOW()';
        $sets[] = "STATUS='active'";
        $connection->queryExecute('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ID=' . (int)$exists['ID'] . " AND STATUS IN ('active','abandoned')");
        return ['ok' => true];
    }
}
