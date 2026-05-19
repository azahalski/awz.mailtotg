<?php
namespace Awz\Mailtotg;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Web\HttpClient;


class Helper {

    const MODULE_ID = 'awz.mailtotg';
    const MASK_ACTIVE = 2;
    const MASK_DECLINE = 4;

    public static function isActive(int $id): bool{
        return self::getMask($id) & self::MASK_ACTIVE;
    }

    public static function isDecline(int $id): bool
    {
        return self::getMask($id) & self::MASK_DECLINE;
    }

    public static function getMask(int $id){

        static $opts;

        if(!$opts){
            try{
                $opts = unserialize(Option::get(static::MODULE_ID, "OPTS", "",""), ['allowed_classes'=>false]);
            }catch (\Exception $e){
                $opts = [];
            }
        }

        return $opts[$id] ?? 0;
    }

    public static function sendTelegram(string $mess): Result
    {
        $result = new Result();
        $tokenAr = array(
            'token'=>Option::get(static::MODULE_ID, "TGKEY", "",""),
            'chat_id'=>Option::get(static::MODULE_ID, "TGID", "","")
        );

        if(!$tokenAr['token']) $result->setError(new Error("token is required"));
        if(!$tokenAr['chat_id']) $result->setError(new Error("chat_id is required"));

        if(empty($result->getErrors())){
            $url = 'https://api.telegram.org/bot'.$tokenAr['token'].'/sendMessage';

            $httpClient = new HttpClient();
            $httpClient->disableSslVerification();

            $postParams = array(
                'chat_id'=>$tokenAr['chat_id'],
                'text'=>$mess
            );

            // Добавляем parse_mode только если сообщение содержит HTML-разметку
            if (self::isHtml($mess)) {
                $postParams['parse_mode'] = 'HTML';
                $postParams['text'] = self::htmlToText($postParams['text']);
            }

            $r = $httpClient->post($url, $postParams);

            try{
                $jsonData = \Bitrix\Main\Web\Json::decode($r);
            }catch (\Exception $e){
                $jsonData = [];
            }

            if(isset($jsonData['error_code'])){
                \CEventLog::Add(
                    array(
                        'SEVERITY' => 'DEBUG',
                        'AUDIT_TYPE_ID' => 'RESPONSE',
                        'MODULE_ID' => self::MODULE_ID,
                        'DESCRIPTION' => print_r([$jsonData['description'], $postParams], true)
                    )
                );
                unset($postParams['parse_mode']);
                $postParams['text'] = $jsonData['description']."\n\n".$postParams['text'];
                $r = $httpClient->post($url, $postParams);

            }

            $result->setData(['response'=>$r]);
        }

        return $result;
    }

    /**
     * Проверяет, содержит ли строка HTML-теги
     *
     * @param string $text
     * @return bool
     */
    public static function isHtml(string $text): bool
    {
        // Проверяем наличие HTML-тегов
        return (bool) preg_match('/<[^>]+>/', $text);
    }

    /**
     * Конвертирует HTML в текст для Telegram (с сохранением поддерживаемой разметки)
     * Поддерживаемые теги Telegram: <b>, <strong>, <i>, <em>, <u>, <s>, <strike>, <code>, <pre>, <a>
     *
     * @param string $html
     * @return string
     */
    public static function htmlToText(string $html): string
    {
        // Удаление DOCTYPE и XML-деклараций
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        $html = preg_replace('/<\?xml[^>]*\?>/i', '', $html);

        // Удаление тегов <html> и </html>
        $html = preg_replace('/<\/?html[^>]*>/i', '', $html);

        // Удаление тегов <body> и </body>
        $html = preg_replace('/<\/?body[^>]*>/i', '', $html);

        // Удаление тегов <style> вместе с содержимым
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);

        // Удаление тегов <script> вместе с содержимым
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        // Замена <br> и <br/> на переводы строк
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Замена <hr> на разделитель
        $html = preg_replace('/<hr[^>]*>/i', "\n" . str_repeat('-', 20) . "\n", $html);

        // Замена </p> на двойной перевод строки
        $html = preg_replace('/<\/p>/i', "\n\n", $html);

        // Замена </div> на перевод строки
        $html = preg_replace('/<\/div>/i', "\n", $html);

        // Замена </h1>-</h6> на двойной перевод строки с подчёркиванием
        $html = preg_replace('/<\/h([1-6])>/i', "\n\n", $html);

        // Добавление подчёркивания перед <h1>-<h6> (для заголовков)
        $html = preg_replace('/<h([1-6])[^>]*>/i', '', $html);

        // Обработка списков
        $html = preg_replace('/<ul[^>]*>/i', '', $html);
        $html = preg_replace('/<\/ul>/i', '', $html);
        $html = preg_replace('/<ol[^>]*>/i', '', $html);
        $html = preg_replace('/<\/ol>/i', '', $html);
        $html = preg_replace('/<li[^>]*>/i', "\n• ", $html);
        $html = preg_replace('/<\/li>/i', '', $html);

        // Замена <table> и </table>
        $html = preg_replace('/<table[^>]*>/i', '', $html);
        $html = preg_replace('/<\/table>/i', '', $html);

        // <tr> — новая строка
        $html = preg_replace('/<tr[^>]*>/i', "\n", $html);
        $html = preg_replace('/<\/tr>/i', '', $html);

        // <td> — добавляем пробел для разделения ячеек
        $html = preg_replace('/<td[^>]*>/i', ' ', $html);
        $html = preg_replace('/<\/td>/i', ' | ', $html);

        // <th> — заголовок ячейки
        $html = preg_replace('/<th[^>]*>/i', ' <b>', $html);
        $html = preg_replace('/<\/th>/i', '</b> | ', $html);

        // Удаление всех оставшихся HTML-тегов, кроме поддерживаемых Telegram
        // Поддерживаемые: b, strong, i, em, u, s, strike, code, pre, a
        $text = preg_replace('/<(?!\/?(?:b|strong|i|em|u|s|strike|code|pre|a\b)[^>]*>)[^>]+>/i', '', $html);

        // Декодирование HTML-сущностей
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Нормализация пробельных симвылов
        $lines = explode("\n", $text);
        $lines = array_map(function ($line) {
            return trim($line);
        }, $lines);
        $text = implode("\n", $lines);

        // Схлопывание более 2 переводов строк в 2
        $text = preg_replace('/(\n[ \t|]*){2,}/', "\n\n", $text);

        return trim($text);
    }

}