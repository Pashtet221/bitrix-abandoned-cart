<?php
namespace PV\AbandonedCart;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Fuser;

class Tracker
{
    private const TABLE = 'pv_abandoned_cart';

    public static function onBasketSaved(\Bitrix\Main\Event $event): void
    {
        if (!Loader::includeModule('sale')) { return; }
        $basket = $event->getParameter('ENTITY');
        if (!$basket instanceof \Bitrix\Sale\BasketBase) { return; }
        $fuserId = (int)$basket->getFUserId();
        $siteId = (string)$basket->getSiteId();
        if ($fuserId <= 0 || $siteId === '') { return; }
        $items = [];
        $total = 0.0;
        $count = 0;
        $currency = 'RUB';
        foreach ($basket as $item) {
            if ($item->isDelay() || !$item->canBuy()) { continue; }
            $quantity = (float)$item->getQuantity();
            $price = (float)$item->getPrice();
            $currency = (string)$item->getCurrency();
            $count += (int)ceil($quantity);
            $total += $quantity * $price;
            $items[] = [
                'product_id' => (int)$item->getProductId(),
                'name' => (string)$item->getField('NAME'),
                'quantity' => $quantity,
                'price' => $price,
                'currency' => $currency,
            ];
        }
        $connection = Application::getConnection();
        $sql = $connection->getSqlHelper();
        $existing = $connection->query('SELECT ID, STATUS FROM ' . self::TABLE . ' WHERE FUSER_ID=' . $fuserId . " AND SITE_ID='" . $sql->forSql($siteId) . "'")->fetch();
        if (!$items) {
            if ($existing && $existing['STATUS'] !== 'ordered') {
                $connection->queryExecute('DELETE FROM ' . self::TABLE . ' WHERE ID=' . (int)$existing['ID']);
            }
            return;
        }
        $currentUser = $GLOBALS['USER'] ?? null;
        $userId = is_object($currentUser) && method_exists($currentUser, 'GetID') ? (int)$currentUser->GetID() : 0;
        $json = $sql->forSql(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $fields = "USER_ID=" . ($userId ?: 'NULL') . ", ITEMS_JSON='" . $json . "', ITEMS_COUNT=" . $count . ", TOTAL=" . round($total, 2) . ", CURRENCY='" . $sql->forSql($currency) . "', UPDATED_AT=NOW()";
        if ($existing) {
            // Новая корзина после заказа снова становится активной.
            $connection->queryExecute('UPDATE ' . self::TABLE . " SET {$fields}, STATUS='active', ORDER_ID=NULL WHERE ID=" . (int)$existing['ID']);
        } else {
            $connection->queryExecute('INSERT INTO ' . self::TABLE . " SET FUSER_ID={$fuserId}, SITE_ID='" . $sql->forSql($siteId) . "', {$fields}, STATUS='active', CREATED_AT=NOW()");
        }
    }

    public static function onOrderSaved(\Bitrix\Main\Event $event): void
    {
        $order = $event->getParameter('ENTITY');
        if (!$order instanceof \Bitrix\Sale\Order || !$order->getId()) { return; }
        $orderBasket = $order->getBasket();
        if (!$orderBasket) { return; }
        $fuserId = (int)$orderBasket->getFUserId();
        if (!$fuserId) { return; }
        $connection = Application::getConnection();
        $site = $connection->getSqlHelper()->forSql((string)$order->getSiteId());
        $connection->queryExecute('UPDATE ' . self::TABLE . " SET STATUS='ordered', ORDER_ID=" . (int)$order->getId() . " WHERE FUSER_ID={$fuserId} AND SITE_ID='{$site}' AND STATUS IN ('active','abandoned')");
    }

    public static function saveContact(int $fuserId, string $siteId, array $data): bool
    {
        if ($fuserId <= 0 || $siteId === '') { return false; }
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $set = [];
        foreach (['email' => 'EMAIL', 'phone' => 'PHONE', 'name' => 'CUSTOMER_NAME'] as $key => $column) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') { $set[] = $column . "='" . $helper->forSql(mb_substr($value, 0, 255)) . "'"; }
        }
        if (!$set) { return false; }
        $site = $helper->forSql($siteId);
        $connection->queryExecute('UPDATE ' . self::TABLE . ' SET ' . implode(',', $set) . " WHERE FUSER_ID={$fuserId} AND SITE_ID='{$site}' AND STATUS IN ('active','abandoned')");
        return true;
    }

    public static function agent(): string
    {
        $connection = Application::getConnection();
        $minutes = max(5, min(10080, (int)Option::get('pv.abandonedcart', 'timeout_minutes', '30')));
        $days = max(1, min(3650, (int)Option::get('pv.abandonedcart', 'retention_days', '90')));
        $connection->queryExecute('UPDATE ' . self::TABLE . " SET STATUS='abandoned' WHERE STATUS='active' AND UPDATED_AT < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)");
        $connection->queryExecute('DELETE FROM ' . self::TABLE . " WHERE UPDATED_AT < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
        return '\\PV\\AbandonedCart\\Tracker::agent();';
    }
}
