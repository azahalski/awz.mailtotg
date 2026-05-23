<?php
namespace Awz\Mailtotg;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\EventMessageCompiler;
use Bitrix\Main\Mail\StopException;
use Bitrix\Main\Mail\Internal\EventMessageTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Awz\Mailtotg\Access\AccessController;

class Handlers {

    public static function OnBeforeProlog()
    {
        $moduleId = Helper::MODULE_ID;

        $request = Application::getInstance()->getContext()->getRequest();
        if ($request->getRequestMethod() !== 'POST') {
            return;
        }

        // Проверяем, что мы на странице редактирования почтового шаблона
        $curPage = $request->getRequestUri();
        if (strpos($curPage, '/bitrix/admin/message_edit.php') === false) {
            return;
        }

        if($request->get('AWZ_MAILTOTG_HIDDEN') != 'Y') {
            return;
        }

        if (!Loader::includeModule($moduleId)) {
            return;
        }


        // Проверяем права на редактирование настроек
        if (!AccessController::isEditSettings()) {
            return;
        }

        $templateId = (int) $request->get('ID');
        if (!$templateId) {
            return;
        }

        $finOptions = [];
        try {
            $opts = unserialize(Option::get($moduleId, "OPTS", "", ""), ['allowed_classes' => false]);
            if (is_array($opts)) {
                $finOptions = $opts;
            }
        } catch (\Exception $e) {
            $finOptions = [];
        }

        $active = $request->get('AWZ_MAILTOTG_ACTIVE') === 'Y';
        $decline = $request->get('AWZ_MAILTOTG_DECLINE') === 'Y';

        $mask = 0;
        if ($active) {
            $mask = $mask | Helper::MASK_ACTIVE;
        }
        if ($decline) {
            $mask = $mask | Helper::MASK_DECLINE;
        }

        if ($mask > 0) {
            $finOptions[$templateId] = $mask;
        } else {
            unset($finOptions[$templateId]);
        }

        Option::set($moduleId, "OPTS", serialize($finOptions), "");
    }

    public static function OnAdminTabControlBegin(&$form)
    {
        $moduleId = Helper::MODULE_ID;
        $request = Application::getInstance()->getContext()->getRequest();

        $curPage = $request->getRequestUri();
        if (strpos($curPage, '/bitrix/admin/message_edit.php') === false) {
            return;
        }

        if (!Loader::includeModule($moduleId)) {
            return;
        }

        // Проверяем права на просмотр настроек
        if (!AccessController::isViewSettings()) {
            return;
        }

        // Получаем ID шаблона из URL или запроса

        $templateId = (int) $request->get('ID');
        if (!$templateId) {
            return;
        }

        $isActive = Helper::isActive($templateId);
        $isDecline = Helper::isDecline($templateId);
        $canEdit = AccessController::isEditSettings();

        Loc::loadMessages(__FILE__);

        // Формируем HTML для вставки после tr с LANGUAGE_ID
        $html = '
<tr class="heading">
    <td colspan="2">' . Loc::getMessage('AWZ_MAILTOTG_ADMIN_TITLE') . '
    <input type="hidden" name="AWZ_MAILTOTG_HIDDEN" value="Y">
    </td>
</tr>
<tr>
    <td style="width:40%">' . Loc::getMessage('AWZ_MAILTOTG_ADMIN_ACTIVE') . '</td>
    <td style="width:60%">
        <input type="checkbox" value="Y" name="AWZ_MAILTOTG_ACTIVE" ' . ($isActive ? 'checked' : '') . ' ' . (!$canEdit ? 'disabled' : '') . '>
    </td>
</tr>
<tr>
    <td style="width:40%">' . Loc::getMessage('AWZ_MAILTOTG_ADMIN_DECLINE') . '</td>
    <td style="width:60%">
        <input type="checkbox" value="Y" name="AWZ_MAILTOTG_DECLINE" ' . ($isDecline ? 'checked' : '') . ' ' . (!$canEdit ? 'disabled' : '') . '>
    </td>
</tr>';
        if(Option::get($moduleId, 'DISABLED', 'N', '') == 'Y'){
            $html .= '<tr>
    <td colspan="2" style="text-align: center;">
        '.Loc::getMessage('AWZ_MAILTOTG_ADMIN_DISABLED').'
    </td>
</tr>';
        }

        // Добавляем вкладку с настройками модуля на страницу почтового шаблона
        $form->tabs[] = array(
            "DIV" => "awz_mailtotg_settings",
            "TAB" => Loc::getMessage('AWZ_MAILTOTG_ADMIN_TAB'),
            "ICON" => "",
            "TITLE" => Loc::getMessage('AWZ_MAILTOTG_ADMIN_TITLE'),
            "CONTENT" => '' . $html . ''
        );


    }

    public static function OnBeforeEventSend(&$arFields, &$eventMessage)
    {
        // Проверяем, отключен ли модуль
        $disabled = Option::get(Helper::MODULE_ID, 'DISABLED', 'N', '');
        if ($disabled === 'Y') {
            return null;
        }

        //$eventMessage['EVENT_NAME'] - тип события
        //$eventMessage['ID'] - ид шаблона
        //$eventMessage['LID'] - ид сайта

        if (
            Helper::isActive((int)$eventMessage['ID'])
            && !empty(Option::get(Helper::MODULE_ID, 'TGKEY', '', ''))
            && !empty(Option::get(Helper::MODULE_ID, 'TGID', '', ''))
        )
        {
            $arEvent = EventMessageTable::getRowById((int)$eventMessage['ID']);
            $arSites = explode(",", $eventMessage["LID"]);

            // get message object for send mail
            $arMessageParams = array(
                'EVENT' => $arEvent,
                'FIELDS' => $arFields,
                'MESSAGE' => $eventMessage,
                'SITE' => $arSites,
                'CHARSET' => 'utf8'
            );
            $message = EventMessageCompiler::createInstance($arMessageParams);
            $msg = '';
            try
            {
                $message->compile();
                // Конвертируем HTML в текст для Telegram (с сохранением поддерживаемой разметки)
                $msg = Helper::htmlToText($message->getMailBody());
            }
            catch(StopException $e)
            {
                $msg = $e->getMessage();
            }
            $msg = preg_replace('/(\r?\n){3,}/', "\n\n", $msg);
            Helper::sendTelegram($msg);
        }

        if (Helper::isDecline((int)$eventMessage['ID']))
        {
            return false;
        }

    }

}