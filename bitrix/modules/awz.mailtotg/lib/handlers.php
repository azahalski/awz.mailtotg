<?php
namespace Awz\Mailtotg;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\EventMessageCompiler;
use Bitrix\Main\Mail\StopException;
use Bitrix\Main\Mail\Internal\EventMessageTable;

class Handlers {

    public static function OnBeforeEventSend(&$arFields, &$eventMessage)
    {
        //$eventMessage['EVENT_NAME'] - тип события
        //$eventMessage['ID'] - ид шаблона
        //$eventMessage['LID'] - ид сайта

        if (Helper::isActive((int)$eventMessage['ID']))
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