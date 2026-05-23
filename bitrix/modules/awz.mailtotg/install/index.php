<?php
use Bitrix\Main\Localization\Loc,
    Bitrix\Main\EventManager,
    Bitrix\Main\ModuleManager,
	Bitrix\Main\Config\Option,
    Bitrix\Main\Application;

Loc::loadMessages(__FILE__);

class awz_mailtotg extends CModule
{
	var $MODULE_ID = "awz.mailtotg";
	var $MODULE_VERSION;
	var $MODULE_VERSION_DATE;
	var $MODULE_NAME;
	var $MODULE_DESCRIPTION;

    public function __construct()
	{
        $arModuleVersion = array();
        include(__DIR__.'/version.php');

        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];

        $this->MODULE_NAME = Loc::getMessage("AWZ_MAILTOTG_MODULE_NAME");
        $this->MODULE_DESCRIPTION = Loc::getMessage("AWZ_MAILTOTG_MODULE_DESCRIPTION");
		$this->PARTNER_NAME = Loc::getMessage("AWZ_PARTNER_NAME");
		$this->PARTNER_URI = "https://zahalski.dev/";

		return true;
	}

    function DoInstall()
    {
        global $APPLICATION, $step;

        $this->InstallFiles();
        $this->InstallDB();
        $this->checkOldInstallTables();
        $this->InstallEvents();
        $this->createAgents();

        ModuleManager::RegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage("AWZ_MAILTOTG_MODULE_NAME"),
            $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'. $this->MODULE_ID .'/install/solution.php'
        );

        return true;
    }

    function DoUninstall()
    {
        global $APPLICATION, $step;

        $step = intval($step);
        if($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('AWZ_MAILTOTG_INSTALL_TITLE'),
                $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'. $this->MODULE_ID .'/install/unstep.php'
            );
        }
        elseif($step == 2) {
            if($_REQUEST['save'] != 'Y' && !isset($_REQUEST['save'])) {
                $this->UnInstallDB();
            }
            $this->UnInstallFiles();
            $this->UnInstallEvents();
            $this->deleteAgents();

            if($_REQUEST['saveopts'] != 'Y' && !isset($_REQUEST['saveopts'])) {
                \Bitrix\Main\Config\Option::delete($this->MODULE_ID);
            }

            ModuleManager::UnRegisterModule($this->MODULE_ID);
            return true;
        }
		
    }

    function InstallDB()
    {
        global $DB, $DBType, $APPLICATION;
        $connection = \Bitrix\Main\Application::getConnection();
        $this->errors = false;
        if(!$this->errors && !$DB->TableExists(implode('_', explode('.',$this->MODULE_ID)).'_permission')) {
            $this->errors = $DB->RunSQLBatch($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/" . $this->MODULE_ID . "/install/db/".$connection->getType()."/access.sql");
        }
        if (!$this->errors) {
            return true;
        } else {
            $APPLICATION->ThrowException(implode("", $this->errors));
            return $this->errors;
        }
    }

    function UnInstallDB()
    {
        global $DB, $DBType, $APPLICATION;
        $connection = \Bitrix\Main\Application::getConnection();
        $this->errors = false;
        if (!$this->errors) {
            $this->errors = $DB->RunSQLBatch($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/" . $this->MODULE_ID . "/install/db/" . $connection->getType() . "/unaccess.sql");
        }
        if (!$this->errors) {
            return true;
        }
        else {
            $APPLICATION->ThrowException(implode("", $this->errors));
            return $this->errors;
        }
    }

    function InstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnAfterUserUpdate',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnAfterUserAdd',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnBeforeEventSend',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'OnBeforeEventSend'
        );
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnPageStart',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'onPageStart'
        );
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnBeforeProlog',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'OnBeforeProlog'
        );
        $eventManager->registerEventHandlerCompatible(
            'main', 'OnAdminTabControlBegin',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'OnAdminTabControlBegin'
        );
        return true;
    }

    function UnInstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $eventManager->unRegisterEventHandler(
            'main', 'OnAfterUserUpdate',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->unRegisterEventHandler(
            'main', 'OnAfterUserAdd',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Access\\Handlers', 'OnAfterUserUpdate'
        );
        $eventManager->unRegisterEventHandler(
            'main', 'OnBeforeEventSend',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'OnBeforeEventSend'
        );
        $eventManager->unRegisterEventHandler(
            'main', 'OnPageStart',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'onPageStart'
        );
        $eventManager->unRegisterEventHandler(
            'main', 'OnBeforeProlog',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'OnBeforeProlog'
        );
        $eventManager->unRegisterEventHandler(
            'main', 'OnAdminTabControlBegin',
            $this->MODULE_ID, '\\Awz\\Mailtotg\\Handlers', 'OnAdminTabControlBegin'
        );
        return true;
    }

    function InstallFiles()
    {
        CopyDirFiles(
            $_SERVER['DOCUMENT_ROOT']."/bitrix/modules/".$this->MODULE_ID."/install/components/mailtotg.config.permissions/",
            $_SERVER['DOCUMENT_ROOT']."/bitrix/components/awz/mailtotg.config.permissions",
            true, true
        );
        return true;
    }

    function UnInstallFiles()
    {
        DeleteDirFilesEx("/bitrix/components/awz/mailtotg.config.permissions");
        return true;
    }

    function createAgents() {
        return true;
    }

    function deleteAgents() {
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        return true;
    }

    function checkOldInstallTables()
    {
        return true;
    }

}