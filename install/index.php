<?php
use Bitrix\Main\ModuleManager;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class pv_abandonedcart extends CModule
{
    public $MODULE_ID = 'pv.abandonedcart';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'Брошенные корзины (PV)';
    public $MODULE_DESCRIPTION = 'Отслеживание корзин интернет-магазина';
    public $PARTNER_NAME = 'PV Development';
    public $PARTNER_URI = '';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        global $APPLICATION;
        if (!Loader::includeModule('sale')) {
            $APPLICATION->ThrowException('Для работы требуется модуль Интернет-магазин (sale).');
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
        \PV\AbandonedCart\Checkout::upgrade();
        EventManager::getInstance()->registerEventHandler('sale', 'OnSaleBasketSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onBasketSaved');
        EventManager::getInstance()->registerEventHandler('sale', 'OnSaleOrderSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onOrderSaved');
        if (Option::get($this->MODULE_ID, 'timeout_minutes', '') === '') {
            Option::set($this->MODULE_ID, 'timeout_minutes', '30');
        }
        if (Option::get($this->MODULE_ID, 'retention_days', '') === '') {
            Option::set($this->MODULE_ID, 'retention_days', '90');
        }
        CAgent::AddAgent('\\PV\\AbandonedCart\\Tracker::agent();', $this->MODULE_ID, 'N', 600, '', 'Y');
        // Copy the public admin entry point, not the module's internal admin/list.php.
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        return true;
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        $em = EventManager::getInstance();
        $em->unRegisterEventHandler('sale', 'OnSaleBasketSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onBasketSaved');
        $em->unRegisterEventHandler('sale', 'OnSaleOrderSaved', $this->MODULE_ID, '\\PV\\AbandonedCart\\Tracker', 'onOrderSaved');
        $em->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, '\\PV\\AbandonedCart\\Checkout', 'onProlog');
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
        @unlink($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/pv_abandonedcart_list.php');
        // Данные сохраняются при удалении модуля, чтобы избежать случайной потери.
        return true;
    }
}
