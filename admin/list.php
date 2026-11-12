<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

$moduleId = 'pv.abandonedcart';
if (!$USER->IsAdmin() || !Loader::includeModule($moduleId)) {
    $APPLICATION->AuthForm('Недостаточно прав');
}
$APPLICATION->SetTitle('Брошенные корзины');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if (isset($_POST['upgrade_module'])) {
        \PV\AbandonedCart\Checkout::upgrade();
        LocalRedirect($APPLICATION->GetCurPageParam('upgraded=1', ['upgraded']));
    }
    Option::set($moduleId, 'timeout_minutes', (string)max(5, min(10080, (int)($_POST['timeout_minutes'] ?? 30))));
    Option::set($moduleId, 'retention_days', (string)max(1, min(3650, (int)($_POST['retention_days'] ?? 90))));
    LocalRedirect($APPLICATION->GetCurPageParam('saved=1', ['saved']));
}
$connection = Application::getConnection();
$helper = $connection->getSqlHelper();
$status = (string)($_GET['status'] ?? 'abandoned');
$allowed = ['all', 'active', 'abandoned', 'ordered'];
if (!in_array($status, $allowed, true)) { $status = 'abandoned'; }
$where = $status === 'all' ? '' : " WHERE STATUS='" . $helper->forSql($status) . "'";
if (!$connection->isTableExists('pv_abandoned_cart')) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    ShowError('Таблица pv_abandoned_cart отсутствует. Проверьте установку модуля.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}
$stats = $connection->query('SELECT STATUS, COUNT(*) CNT, SUM(TOTAL) AMOUNT FROM pv_abandoned_cart GROUP BY STATUS')->fetchAll();
$rows = $connection->query('SELECT * FROM pv_abandoned_cart' . $where . ' ORDER BY UPDATED_AT DESC LIMIT 200')->fetchAll();
$isRefresh = isset($_GET['pv_refresh']) && $_GET['pv_refresh'] === 'Y';
if (!$isRefresh) { require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php'; }
function pvEscape($value): string { return htmlspecialcharsbx((string)$value); }
function pvStatus($value): string {
    return ['active'=>'Активная', 'abandoned'=>'Брошенная', 'ordered'=>'Заказ оформлен', 'all'=>'Все'][$value] ?? (string)$value;
}
ob_start();
?>
<h2>Статистика</h2>
<table class="adm-list-table" style="max-width:700px;width:100%"><thead><tr class="adm-list-table-header"><td>Статус</td><td>Корзин</td><td>Сумма*</td></tr></thead><tbody>
<?php foreach ($stats as $s): ?><tr class="adm-list-table-row"><td><?=pvEscape(pvStatus($s['STATUS']))?></td><td><?= (int)$s['CNT'] ?></td><td><?=pvEscape(number_format((float)$s['AMOUNT'], 2, '.', ' '))?></td></tr><?php endforeach; ?>
</tbody></table>
<p>*Суммы объединены без конвертации валют; для мультивалютных магазинов итог не является финансовым показателем.</p>
<p>Показать: <?php foreach ($allowed as $v): ?><a style="margin-right:12px" href="?status=<?=urlencode($v)?>"><?=pvEscape(pvStatus($v))?></a><?php endforeach; ?></p>
<div style="overflow:auto"><table class="adm-list-table" style="width:100%"><thead><tr class="adm-list-table-header"><td>ID</td><td>Статус</td><td>Покупатель</td><td>Товары</td><td>Сумма</td><td>Обновлено</td><td>Оформленный заказ</td><td>Данные оформления</td></tr></thead><tbody>
<?php foreach ($rows as $row): $items = json_decode((string)$row['ITEMS_JSON'], true) ?: []; ?>
<tr class="adm-list-table-row"><td><?= (int)$row['ID'] ?></td><td><?=pvEscape(pvStatus($row['STATUS']))?></td><td><?=pvEscape($row['CUSTOMER_NAME'] ?: 'Имя не указано')?><div><?=pvEscape($row['EMAIL'])?></div><div><?=pvEscape($row['PHONE'])?></div><small title="Внутренний FUSER_ID Битрикс; это не номер заказа и не ID учётной записи">Владелец корзины №<?= (int)$row['FUSER_ID'] ?> · сайт <?=pvEscape($row['SITE_ID'])?></small></td><td><?php foreach ($items as $item): ?><div><?=pvEscape($item['name'] ?? '')?> × <?=pvEscape($item['quantity'] ?? '')?></div><?php endforeach; ?></td><td><?=pvEscape($row['TOTAL'])?> <?=pvEscape($row['CURRENCY'])?></td><td><?=pvEscape($row['UPDATED_AT'])?></td><td><?= (int)$row['ORDER_ID'] > 0 ? '№' . (int)$row['ORDER_ID'] : 'Ещё не оформлен' ?></td><td><?php foreach (['DELIVERY_ADDRESS'=>'Адрес', 'ORDER_COMMENT'=>'Комментарий', 'DELIVERY_METHOD'=>'Доставка', 'PAYMENT_METHOD'=>'Оплата'] as $column=>$label): if (!empty($row[$column])): ?><div><b><?=pvEscape($label)?>:</b> <?=pvEscape(in_array($column, ['DELIVERY_METHOD', 'PAYMENT_METHOD'], true) ? \PV\AbandonedCart\Checkout::methodName($column === 'DELIVERY_METHOD' ? 'delivery' : 'payment', (string)$row[$column]) : $row[$column])?></div><?php endif; endforeach; ?><?php if (!empty($row['CHECKOUT_UPDATED_AT'])): ?><small>Данные формы обновлены: <?=pvEscape($row['CHECKOUT_UPDATED_AT'])?></small><?php else: ?><small>Данные формы ещё не получены</small><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p>Отображаются последние 200 записей. Для просмотра других статусов используйте фильтр выше.</p>
<p>Контакты и адрес поступают из формы оформления при разрешённом сборе данных. Номер заказа появляется после его оформления. Одна строка соответствует владельцу корзины на конкретном сайте; изменение формы обновляет эту же строку.</p>
<p>Список обновляется автоматически каждые 10 секунд. Получено: <?=pvEscape(date('d.m.Y H:i:s'))?></p>
<?php
$liveHtml = ob_get_clean();
if ($isRefresh) {
    $APPLICATION->RestartBuffer();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['html' => $liveHtml], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<div id="pv-cart-live"><?=$liveHtml?></div>
<script>
(function () {
    var pending = false;
    setInterval(function () {
        if (pending || document.hidden) return;
        pending = true;
        var url = new URL(window.location.href);
        url.searchParams.set('pv_refresh', 'Y');
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 10000);
        fetch(url.toString(), {credentials: 'same-origin', cache: 'no-store', signal: controller.signal})
            .then(function (response) { if (!response.ok) throw new Error('Refresh unavailable'); return response.json(); })
            .then(function (data) {
                if (typeof data.html === 'string') document.getElementById('pv-cart-live').innerHTML = data.html;
            }).catch(function () {}).finally(function () { clearTimeout(timeout); pending = false; });
    }, 10000);
})();
</script>
<h2>Обновление модуля</h2>
<?php if (isset($_GET['upgraded'])): ?><div class="adm-info-message">Обновление обработчиков и таблицы выполнено</div><?php endif; ?>
<form method="post"><?=bitrix_sessid_post()?><input type="hidden" name="upgrade_module" value="1"><button class="adm-btn" type="submit">Применить обновление 1.0.4</button></form>
<h2>Настройки</h2>
<?php if (isset($_GET['saved'])): ?><div class="adm-info-message">Настройки сохранены</div><?php endif; ?>
<form method="post"><?=bitrix_sessid_post()?><label>Считать брошенной через (мин): <input type="number" min="5" max="10080" name="timeout_minutes" value="<?= (int)Option::get($moduleId, 'timeout_minutes', '30') ?>"></label><br><br><label>Хранить данные (дней): <input type="number" min="1" max="3650" name="retention_days" value="<?= (int)Option::get($moduleId, 'retention_days', '90') ?>"></label><br><br><button class="adm-btn-save" type="submit">Сохранить</button></form>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php'; ?>
