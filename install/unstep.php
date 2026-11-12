<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__DIR__ . '/index.php');
?>
<form method="post" action="<?=htmlspecialcharsbx($APPLICATION->GetCurPage())?>">
    <?=bitrix_sessid_post()?>
    <input type="hidden" name="lang" value="<?=htmlspecialcharsbx(LANGUAGE_ID)?>">
    <input type="hidden" name="id" value="pv.abandonedcart">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <p><label><input type="checkbox" name="savedata" value="Y" checked> <?=htmlspecialcharsbx(Loc::getMessage('PV_ABANDONEDCART_KEEP_DATA'))?></label></p>
    <p><?=htmlspecialcharsbx(Loc::getMessage('PV_ABANDONEDCART_UNINSTALL_NOTICE'))?></p>
    <button type="submit" name="inst" value="Y"><?=htmlspecialcharsbx(Loc::getMessage('PV_ABANDONEDCART_UNINSTALL_BUTTON'))?></button>
</form>
