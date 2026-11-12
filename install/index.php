<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) { die(); }
use Bitrix\Main\ModuleManager;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class pv_abandonedcart extends CModule
{
    public $MODULE_ID;
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI = '';

    public function __construct()
    {
        $this->MODULE_ID = 'pv.abandonedcart';
        $this->MODULE_NAME = Loc::getMessage('PV_ABANDONEDCART_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('PV_ABANDONEDCART_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('PV_ABANDONEDCART_PARTNER_NAME');
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $APPLICATION, $USER;
        if (!is_object($USER) || !$USER->IsAdmin() || !check_bitrix_sessid()) {
            $APPLICATION->ThrowException(Loc::getMessage('PV_ABANDONEDCART_ACCESS_DENIED'));
            return false;
        }
        if (PHP_VERSION_ID < 80100 || !function_exists('mb_substr')) {
            $APPLICATION->ThrowException(Loc::getMessage('PV_ABANDONEDCART_PHP_REQUIRED'));
            return false;
        }
        if (!Loader::includeModule('sale')) {
            $APPLICATION->ThrowException(Loc::getMessage('PV_ABANDONEDCART_SALE_REQUIRED'));
            return false;
        }
        $connection = Application::getConnection();
        $table = 'pv_abandoned_cart';
        if (!$connection->isTableExists($table)) {
            $connection->queryExecute("CREATE TABLE {$table} (
                ID INT NOT NULL AUTO_INCREMENT,
                FUSER_ID INT NOT NULL,
                USER_ID INT NULL,
                SITE_ID VARCHAR(2) NOT NULL,
                STATUS VARCHAR(20) NOT NULL DEFAULT 'active',
                EMAIL VARCHAR(255) NULL,
                PHONE VARCHAR(80) NULL,
                CUSTOMER_NAME VARCHAR(255) NULL,
                ITEMS_JSON LONGTEXT NULL,
                ITEMS_COUNT INT NOT NULL DEFAULT 0,
                TOTAL DECIMAL(18,2) NOT NULL DEFAULT 0,
                CURRENCY VARCHAR(3) NOT NULL DEFAULT 'RUB',
                CREATED_AT DATETIME NOT NULL,
                UPDATED_AT DATETIME NOT NULL,
                ORDER_ID INT NULL,
                PRIMARY KEY (ID),
                UNIQUE KEY ux_fuser_site (FUSER_ID, SITE_ID),
                KEY ix_status_updated (STATUS, UPDATED_AT)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        ModuleManager::registerModule($this->MODULE_ID);
        // Registering a module does not include its autoloader on a clean installation.
        if (!Loader::includeModule($this->MODULE_ID)) {
            ModuleManager::unRegisterModule($this->MODULE_ID);
            $APPLICATION->ThrowException(Loc::getMessage('PV_ABANDONEDCART_INSTALL_ERROR'));
            return false;
        }
        \PV\AbandonedCart\Checkout::upgrade();
        EventManager::getInstance()->unRegisterEventHandler('sale', 'OnSaleBasketSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onBasketSaved');
        EventManager::getInstance()->unRegisterEventHandler('sale', 'OnSaleOrderSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onOrderSaved');
        EventManager::getInstance()->registerEventHandler('sale', 'OnSaleBasketSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onBasketSaved');
        EventManager::getInstance()->registerEventHandler('sale', 'OnSaleOrderSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onOrderSaved');
        if (Option::get($this->MODULE_ID, 'timeout_minutes', '') === '') {
            Option::set($this->MODULE_ID, 'timeout_minutes', '30');
        }
        if (Option::get($this->MODULE_ID, 'retention_days', '') === '') {
            Option::set($this->MODULE_ID, 'retention_days', '90');
        }
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        CAgent::AddAgent('\\PV\\AbandonedCart\\Tracker::agent();', $this->MODULE_ID, 'N', 600, '', 'Y');
        // Copy the public admin entry point, not the module's internal admin/list.php.
        if (!CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true)) {
            $this->removeRegistration();
            $APPLICATION->ThrowException(Loc::getMessage('PV_ABANDONEDCART_INSTALL_ERROR'));
            return false;
        }
        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION, $USER;
        if (!is_object($USER) || !$USER->IsAdmin()) {
            $APPLICATION->ThrowException(Loc::getMessage('PV_ABANDONEDCART_ACCESS_DENIED'));
            return false;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (int)($_POST['step'] ?? 0) !== 2) {
            $APPLICATION->IncludeAdminFile(Loc::getMessage('PV_ABANDONEDCART_UNINSTALL_TITLE'), __DIR__ . '/unstep.php');
            return;
        }
        if (!check_bitrix_sessid()) {
            $APPLICATION->ThrowException(Loc::getMessage('PV_ABANDONEDCART_ACCESS_DENIED'));
            return false;
        }
        if (($_POST['savedata'] ?? '') !== 'Y') {
            $connection = Application::getConnection();
            if ($connection->isTableExists('pv_abandoned_cart')) {
                $connection->queryExecute('DROP TABLE pv_abandoned_cart');
            }
        }
        $this->removeRegistration();
        Option::delete($this->MODULE_ID);
        $file = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/pv_abandonedcart_list.php';
        if (is_file($file)) { unlink($file); }
        return true;
    }

    private function removeRegistration(): void
    {
        $events = EventManager::getInstance();
        $events->unRegisterEventHandler('sale', 'OnSaleBasketSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onBasketSaved');
        $events->unRegisterEventHandler('sale', 'OnSaleOrderSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onOrderSaved');
        $events->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, '\\PV\\AbandonedCart\\Checkout', 'onProlog');
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }
}
