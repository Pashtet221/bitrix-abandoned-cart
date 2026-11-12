<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
use Bitrix\Main\Loader;
Loader::registerAutoLoadClasses('pv.abandonedcart', [
    'PV\\AbandonedCart\\Tracker' => 'lib/tracker.php',
    'PV\\AbandonedCart\\Checkout' => 'lib/checkout.php',
]);
