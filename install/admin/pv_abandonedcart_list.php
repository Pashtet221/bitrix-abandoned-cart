<?php
// Bitrix admin entry point. The module may be installed under /local/modules or /bitrix/modules.
$moduleAdmin = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/pv.abandonedcart/admin/list.php';
if (!is_file($moduleAdmin)) {
    $moduleAdmin = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/pv.abandonedcart/admin/list.php';
}
if (!is_file($moduleAdmin)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';
    ShowError('Не найден административный файл модуля pv.abandonedcart.');
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    return;
}
require $moduleAdmin;
