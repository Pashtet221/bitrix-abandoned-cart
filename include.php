<?php
use Bitrix\Main\Loader;
Loader::registerAutoLoadClasses('pv.abandonedcart', [
    'PV\\AbandonedCart\\Tracker' => 'lib/tracker.php',
    'PV\\AbandonedCart\\Checkout' => 'lib/checkout.php',
]);
