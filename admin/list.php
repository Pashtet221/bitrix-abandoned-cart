<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

$moduleId = 'pv.abandonedcart';
if (!$USER->IsAdmin() || !Loader::includeModule($moduleId)) {
    $APPLICATION->AuthForm('Недостаточно прав');
    return;
}
$APPLICATION->SetTitle('Брошенные корзины');
$connection = Application::getConnection();
$helper = $connection->getSqlHelper();
if (!$connection->isTableExists('pv_abandoned_cart')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError('Таблица pv_abandoned_cart отсутствует. Проверьте установку модуля.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!check_bitrix_sessid()) {
        $error = 'Сессия истекла. Обновите страницу и повторите действие.';
    } elseif (($_POST['action'] ?? '') === 'delete_selected') {
        $submitted = $_POST['cart_ids'] ?? [];
        $ids = [];
        if (!is_array($submitted) || count($submitted) > 200) {
            $error = 'Выберите не более 200 записей за один раз.';
        } else {
            foreach ($submitted as $value) {
                if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int)$value > 2147483647) {
                    $error = 'Некорректный список записей для удаления.';
                    break;
                }
                $ids[(int)$value] = (int)$value;
            }
            if (!$ids && $error === '') { $error = 'Сначала выберите записи для удаления.'; }
        }
        if ($error === '') {
            // Delete module records only, never sale orders, baskets or products.
            $connection->queryExecute('DELETE FROM pv_abandoned_cart WHERE ID IN (' . implode(',', $ids) . ')');
            $deleted = (int)$connection->getAffectedRowsCount();
            LocalRedirect($APPLICATION->GetCurPageParam('deleted=' . $deleted, ['deleted', 'saved', 'upgraded', 'pv_refresh']));
            return;
        }
    } elseif (isset($_POST['upgrade_module'])) {
        \PV\AbandonedCart\Checkout::upgrade();
        LocalRedirect($APPLICATION->GetCurPageParam('upgraded=1', ['upgraded', 'deleted', 'pv_refresh']));
        return;
    } elseif (($_POST['action'] ?? '') === 'save_settings') {
        Option::set($moduleId, 'timeout_minutes', (string)max(5, min(10080, (int)($_POST['timeout_minutes'] ?? 30))));
        Option::set($moduleId, 'capture_contacts', ($_POST['capture_contacts'] ?? '') === 'Y' ? 'Y' : 'N');
        Option::set($moduleId, 'retention_days', (string)max(1, min(3650, (int)($_POST['retention_days'] ?? 90))));
        LocalRedirect($APPLICATION->GetCurPageParam('saved=1', ['saved', 'deleted', 'pv_refresh']));
        return;
    } else {
        $error = 'Неизвестное действие.';
    }
}
$status = (string)($_GET['status'] ?? 'abandoned');
$allowed = ['all', 'active', 'abandoned', 'ordered'];
if (!in_array($status, $allowed, true)) { $status = 'abandoned'; }
$where = $status === 'all' ? '' : " WHERE STATUS='" . $helper->forSql($status) . "'";
$stats = $connection->query('SELECT STATUS, COUNT(*) CNT FROM pv_abandoned_cart GROUP BY STATUS')->fetchAll();
$counts = ['all'=>0, 'active'=>0, 'abandoned'=>0, 'ordered'=>0];
foreach ($stats as $stat) {
    $counts['all'] += (int)$stat['CNT'];
    if (array_key_exists($stat['STATUS'], $counts) && $stat['STATUS'] !== 'all') { $counts[$stat['STATUS']] = (int)$stat['CNT']; }
}
$abandonedTotals = $connection->query("SELECT CURRENCY, SUM(TOTAL) AMOUNT FROM pv_abandoned_cart WHERE STATUS='abandoned' GROUP BY CURRENCY")->fetchAll();
$rows = $connection->query('SELECT * FROM pv_abandoned_cart' . $where . ' ORDER BY UPDATED_AT DESC, ID DESC LIMIT 200')->fetchAll();
$isRefresh = $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pv_refresh']) && $_GET['pv_refresh'] === 'Y';
if (!$isRefresh) { require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; }
function pvEscape($value): string { return htmlspecialcharsbx((string)$value); }
function pvStatus($value): string {
    return ['active'=>'Активная', 'abandoned'=>'Брошенная', 'ordered'=>'Заказ оформлен', 'all'=>'Все'][$value] ?? (string)$value;
}
ob_start();
?>
<div class="pv-cart-summary">
    <div><span>Брошенных корзин</span><strong id="pv-abandoned-count"><?= (int)$counts['abandoned'] ?></strong></div>
    <div><span>Сумма брошенных корзин</span><strong><?php if (!$abandonedTotals): ?>0<?php else: foreach ($abandonedTotals as $total): ?><span class="pv-cart-currency"><?=pvEscape(number_format((float)$total['AMOUNT'], 2, '.', ' '))?> <?=pvEscape($total['CURRENCY'])?></span><?php endforeach; endif; ?></strong></div>
    <div><span>Всего записей</span><strong><?= (int)$counts['all'] ?></strong></div>
</div>
<nav class="pv-cart-filters" aria-label="Статус корзин">
<?php foreach ($allowed as $value): ?><a href="?status=<?=urlencode($value)?>"<?=$status === $value ? ' aria-current="page"' : ''?>><?=pvEscape(pvStatus($value))?> <span><?= (int)$counts[$value] ?></span></a><?php endforeach; ?>
</nav>
<form method="post" id="pv-cart-bulk-form">
    <?=bitrix_sessid_post()?>
    <input type="hidden" name="action" value="delete_selected">
    <div class="pv-cart-toolbar">
        <span id="pv-cart-selection-count" aria-live="polite">Выбрано: 0</span>
        <button class="adm-btn" type="submit" id="pv-cart-delete" disabled>Удалить выбранные</button>
        <span class="pv-cart-muted">Удаляются только записи модуля, заказы магазина сохраняются.</span>
    </div>
    <div class="pv-cart-table-scroll"><table class="adm-list-table pv-cart-table">
        <thead><tr class="adm-list-table-header">
            <th><input type="checkbox" id="pv-cart-select-all" aria-label="Выбрать все показанные записи" title="Выбрать все показанные записи"></th>
            <th><span class="pv-cart-sr-only">Подробности</span></th><th>ID</th><th>Статус</th><th>Покупатель</th><th>Товары</th><th>Сумма</th><th>Обновлено</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row):
            $id = (int)$row['ID'];
            $items = json_decode((string)$row['ITEMS_JSON'], true) ?: [];
            $first = $items ? reset($items) : [];
        ?>
        <tr class="adm-list-table-row pv-cart-row">
            <td><input type="checkbox" name="cart_ids[]" value="<?=$id?>" id="pv-cart-check-<?=$id?>" data-cart-id="<?=$id?>" aria-label="Выбрать корзину №<?=$id?>"></td>
            <td><button type="button" class="pv-cart-toggle" id="pv-cart-toggle-<?=$id?>" data-cart-toggle="<?=$id?>" aria-expanded="false" aria-controls="pv-cart-details-<?=$id?>" aria-label="Подробности корзины №<?=$id?>"><span aria-hidden="true">▸</span></button></td>
            <td><?=$id?></td><td><span class="pv-cart-status"><?=pvEscape(pvStatus($row['STATUS']))?></span></td>
            <td><div class="pv-cart-name"><?=pvEscape($row['CUSTOMER_NAME'] ?: 'Имя не указано')?></div><div class="pv-cart-muted"><?=pvEscape($row['PHONE'] ?: $row['EMAIL'])?></div></td>
            <td><div class="pv-cart-product" title="<?=pvEscape($first['name'] ?? '')?>"><?=pvEscape($first['name'] ?? 'Нет товаров')?><?php if ($first): ?> × <?=pvEscape($first['quantity'] ?? '')?><?php endif; ?></div><?php if (count($items) > 1): ?><small class="pv-cart-muted">Ещё товаров: <?=count($items) - 1?></small><?php endif; ?></td>
            <td class="pv-cart-nowrap"><?=pvEscape(number_format((float)$row['TOTAL'], 2, '.', ' '))?> <?=pvEscape($row['CURRENCY'])?></td><td class="pv-cart-nowrap"><?=pvEscape($row['UPDATED_AT'])?></td>
        </tr>
        <tr id="pv-cart-details-<?=$id?>" class="pv-cart-details" hidden><td colspan="8">
            <div class="pv-cart-detail-grid">
                <section><h3>Покупатель</h3><dl><dt>Имя</dt><dd><?=pvEscape($row['CUSTOMER_NAME'] ?: 'Не указано')?></dd><dt>Телефон</dt><dd><?=pvEscape($row['PHONE'] ?: 'Не указан')?></dd><dt>Email</dt><dd><?=pvEscape($row['EMAIL'] ?: 'Не указан')?></dd></dl></section>
                <section><h3>Доставка и оплата</h3><dl>
                <?php foreach (['DELIVERY_ADDRESS'=>'Адрес', 'DELIVERY_METHOD'=>'Доставка', 'PAYMENT_METHOD'=>'Оплата', 'ORDER_COMMENT'=>'Комментарий'] as $column=>$label):
                    $detail = (string)($row[$column] ?? '');
                    if (in_array($column, ['DELIVERY_METHOD', 'PAYMENT_METHOD'], true) && $detail !== '') { $detail = \PV\AbandonedCart\Checkout::methodName($column === 'DELIVERY_METHOD' ? 'delivery' : 'payment', $detail); }
                ?><dt><?=pvEscape($label)?></dt><dd><?=pvEscape($detail !== '' ? $detail : 'Не указано')?></dd><?php endforeach; ?>
                </dl></section>
                <section><h3>Состав корзины</h3><ul><?php foreach ($items as $item): ?><li><?=pvEscape($item['name'] ?? '')?> × <?=pvEscape($item['quantity'] ?? '')?></li><?php endforeach; ?></ul><p><b>Оформленный заказ:</b> <?= (int)$row['ORDER_ID'] > 0 ? '№' . (int)$row['ORDER_ID'] : 'Ещё не оформлен' ?></p><p class="pv-cart-muted"><?php if (!empty($row['CHECKOUT_UPDATED_AT'])): ?>Данные формы обновлены: <?=pvEscape($row['CHECKOUT_UPDATED_AT'])?><?php else: ?>Данные формы ещё не получены<?php endif; ?></p></section>
            </div>
        </td></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="pv-cart-empty">Корзин с выбранным статусом нет.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</form>
<p class="pv-cart-muted">Показано: <?=count($rows)?> из <?= (int)$counts[$status] ?>. Максимум 200 записей. Галочка в заголовке выбирает только показанные строки.</p>
<p class="pv-cart-muted">Автообновление каждые 10 секунд · Получено: <?=pvEscape(date('d.m.Y H:i:s'))?></p>
<?php
$liveHtml = ob_get_clean();
if ($isRefresh) {
    $APPLICATION->RestartBuffer();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['html' => $liveHtml], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$modulePath = strpos(str_replace('\\', '/', __DIR__), '/local/modules/') !== false ? '/local/modules' : '/bitrix/modules';
?>
<link rel="stylesheet" href="<?=pvEscape($modulePath)?>/pv.abandonedcart/assets/admin.css?v=1.0.6">
<?php if ($error !== ''): ?><div class="adm-info-message" role="alert"><?=pvEscape($error)?></div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="adm-info-message" role="status">Удалено записей: <?=max(0, (int)$_GET['deleted'])?></div><?php endif; ?>
<div id="pv-cart-live"><?=$liveHtml?></div>
<script src="<?=pvEscape($modulePath)?>/pv.abandonedcart/assets/admin.js?v=1.0.6"></script>
<details class="pv-cart-settings"<?=isset($_GET['saved']) || isset($_GET['upgraded']) ? ' open' : ''?>><summary>Настройки и обновление модуля</summary>
<h2>Настройки</h2>
<?php if (isset($_GET['saved'])): ?><div class="adm-info-message">Настройки сохранены</div><?php endif; ?>
<form method="post"><?=bitrix_sessid_post()?><input type="hidden" name="action" value="save_settings"><p><label><input type="checkbox" name="capture_contacts" value="Y"<?=Option::get($moduleId, 'capture_contacts', 'N') === 'Y' ? ' checked' : ''?>> Собирать контакты и данные оформления</label></p><p class="pv-cart-muted">По умолчанию выключено. Включайте после настройки уведомления об обработке данных и флажка согласия в checkout. Без отмеченного согласия данные формы не отправляются.</p><label>Считать брошенной через (мин): <input type="number" min="5" max="10080" name="timeout_minutes" value="<?= (int)Option::get($moduleId, 'timeout_minutes', '30') ?>"></label><br><br><label>Хранить данные (дней): <input type="number" min="1" max="3650" name="retention_days" value="<?= (int)Option::get($moduleId, 'retention_days', '90') ?>"></label><br><br><button class="adm-btn-save" type="submit">Сохранить</button></form>
<h2>Обновление модуля</h2>
<?php if (isset($_GET['upgraded'])): ?><div class="adm-info-message">Обновление обработчиков и таблицы выполнено</div><?php endif; ?>
<form method="post"><?=bitrix_sessid_post()?><input type="hidden" name="upgrade_module" value="1"><button class="adm-btn" type="submit">Применить обновление 1.0.6</button></form>
</details>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
