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

            $processedMessage = self::htmlToText($mess);
            $postParams = array(
                'chat_id'    => $tokenAr['chat_id'],
                'text'       => $processedMessage,
                'parse_mode' => 'HTML' // Всегда включаем HTML, так как htmlToText гарантированно возвращает валидный HTML
            );

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
     * Конвертирует HTML/Markdown в текст для Telegram и жестко ограничивает длину до лимита API.
     * Поддерживаемые теги Telegram: <b>, <strong>, <i>, <em>, <u>, <s>, <strike>, <code>, <pre>, <a>
     *
     * @param string $html
     * @param int $maxLength Максимальная длина сообщения (безопасный лимит для Telegram — 3800)
     * @return string
     */
    public static function htmlToText(string $html, int $maxLength = 3800): string
    {
        // ОЧИСТКА ВНУТРЕННИХ ПЕРЕНОСОВ СТРОК В ЯЧЕЙКАХ
        if (strpos($html, '|') !== false) {
            $html = preg_replace_callback('/(\|[^\n|]*)\n+(?![ \t]*\|)([^\n|]*)/u', function($matches) {
                return $matches[1] . ' ' . $matches[2];
            }, $html);

            $html = preg_replace_callback('/(\|[^\n|]*)\n+(?![ \t]*\|)([^\n|]*)/u', function($matches) {
                return $matches[1] . ' ' . $matches[2];
            }, $html);
        }

        // ИДЕНТИФИКАЦИЯ И ОБЕРТКА MARKDOWN-ТАБЛИЦ В <pre>
        // Исключаем markdown-таблицы из одной колонки (где нет разделителей '|' внутри строки)
        if (preg_match('/\|[ \t]*:-?-?.*?\|/i', $html) || preg_match('/\|[ \t]*-{3,}[ \t]*\|/i', $html)) {
            $html = preg_replace_callback('/((?:^[ \t]*\|.*\|[ \t]*$\n?)+)/m', function($matches) {
                if (strpos($matches[1], '<pre>') !== false) {
                    return $matches[1];
                }

                // Проверяем, является ли markdown-таблица одноколоночной
                $lines = explode("\n", trim($matches[1]));
                $isSingleColumn = true;
                foreach ($lines as $line) {
                    if (trim($line) === '') continue;
                    // Если в строке больше 2 символов '|' (начало и конец), значит колонок > 1
                    if (substr_count($line, '|') > 2) {
                        $isSingleColumn = false;
                        break;
                    }
                }

                if ($isSingleColumn) {
                    // Превращаем в обычный текст без знаков таблицы
                    $cleanLines = [];
                    foreach ($lines as $line) {
                        if (preg_match('/^[ \t]*\|[ \t]*:-?--*.*?\|[ \t]*$/', $line) || preg_match('/^[ \t]*\|[ \t]*-{3,}[ \t]*\|[ \t]*$/', $line)) {
                            continue; // Пропускаем разделитель
                        }
                        $cleanLine = trim($line, " \t|");
                        if ($cleanLine !== '') {
                            $cleanLines[] = $cleanLine;
                        }
                    }
                    return "\n" . implode("\n", $cleanLines) . "\n";
                }

                $cleanTable = preg_replace('/^[ \t]*$\n/m', '', trim($matches[1]));
                return "\n<pre>\n" . $cleanTable . "\n</pre>\n";
            }, $html);
        }

        // 1. Удаление DOCTYPE, XML и служебных тегов
        $html = preg_replace('/<!DOCTYPE[^>]*>/i', '', $html);
        $html = preg_replace('/<\?xml[^>]*\?>/i', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<\/?(html|body)[^>]*>/i', '', $html);

        // 2. Базовая разметка блоков
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<hr[^>]*>/i', "\n" . str_repeat('-', 20) . "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);
        $html = preg_replace('/<\/h([1-6])>/i', "\n\n", $html);
        $html = preg_replace('/<h([1-6])[^>]*>/i', '', $html);

        // 3. Обработка списков
        $html = preg_replace('/<\/?(ul|ol)[^>]*>/i', '', $html);
        $html = preg_replace('/<li[^>]*>/i', "\n• ", $html);
        $html = preg_replace('/<\/li>/i', '', $html);

        // 4. ОБРАБОТКА HTML-ТАБЛИЦ (теги <table>)
        $html = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function($matches) {
            $tableContent = $matches[1];

            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tableContent, $trMatches);
            $rows = [];
            $colWidths = [];

            foreach ($trMatches[1] as $trContent) {
                $trContent = str_replace(["\r\n", "\r", "\n"], ' ', $trContent);
                preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $trContent, $tdMatches);

                $rowCells = [];
                foreach ($tdMatches[1] as $cellIdx => $cellHtml) {
                    $cellText = trim(strip_tags($cellHtml));
                    $cellText = html_entity_decode($cellText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $cellText = preg_replace('/\s+/', ' ', $cellText);
                    $rowCells[$cellIdx] = $cellText;

                    $cellLen = mb_strlen($cellText, 'UTF-8');
                    if (!isset($colWidths[$cellIdx]) || $cellLen > $colWidths[$cellIdx]) {
                        $colWidths[$cellIdx] = $cellLen;
                    }
                }
                if (!empty($rowCells)) {
                    $rows[] = $rowCells;
                }
            }

            if (empty($rows)) {
                return '';
            }

            // ПРОВЕРКА НА ОДНУ КОЛОНКУ
            if (count($colWidths) <= 1) {
                $plainLines = [];
                foreach ($rows as $cells) {
                    if (isset($cells[0]) && $cells[0] !== '') {
                        $plainLines[] = $cells[0];
                    }
                }
                return "\n" . implode("\n", $plainLines) . "\n";
            }

            $formattedLines = [];
            foreach ($rows as $rowIndex => $cells) {
                $lineCells = [];
                foreach ($cells as $cellIdx => $cellText) {
                    $width = $colWidths[$cellIdx];
                    $lineCells[] = $cellText . str_repeat(' ', $width - mb_strlen($cellText, 'UTF-8'));
                }
                $formattedLines[] = '| ' . implode(' | ', $lineCells) . ' |';

                if ($rowIndex === 0) {
                    $sepCells = [];
                    foreach ($colWidths as $width) {
                        $sepCells[] = str_repeat('-', $width);
                    }
                    $formattedLines[] = '|-' . implode('-|-', $sepCells) . '-|';
                }
            }

            return "\n<pre>\n" . implode("\n", $formattedLines) . "\n</pre>\n";
        }, $html);

        // 5. Фильтрация неподдерживаемых тегов
        $text = preg_replace('/<(?!\/?(?:b|strong|i|em|u|s|strike|code|pre|a\b)[^>]*>)[^>]+>/i', '', $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 6. Построчная нормализация пробелов
        $lines = explode("\n", $text);
        $insidePre = false;
        $processedLines = [];

        foreach ($lines as $line) {
            if (strpos($line, '<pre>') !== false) {
                $insidePre = true;
            }

            if ($insidePre) {
                if (trim($line) !== '' || strpos($line, '<pre>') !== false || strpos($line, '</pre>') !== false) {
                    $processedLines[] = $line;
                }
            } else {
                $processedLines[] = trim($line);
            }

            if (strpos($line, '</pre>') !== false) {
                $insidePre = false;
            }
        }

        $text = implode("\n", $processedLines);
        $text = preg_replace('/(?<!<\/pre>)\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]*\n+<pre>/i', "\n<pre>", $text);
        $text = preg_replace('/<\/pre>\n+[ \t]*/i', "</pre>\n", $text);
        $text = trim($text);

        // 7. УМНОЕ ПОСТРОЧНОЕ ОБРЕЗАНИЕ (Защита структуры)
        if (mb_strlen($text, 'UTF-8') > $maxLength) {
            $finalLines = explode("\n", $text);
            $currentLength = 0;
            $allowedLines = [];
            $insidePreTag = false;

            foreach ($finalLines as $line) {
                if (strpos($line, '<pre>') !== false) {
                    $insidePreTag = true;
                }

                $lineLength = mb_strlen($line, 'UTF-8') + 1; // +1 за перевод строки
                if (($currentLength + $lineLength) > ($maxLength - 60)) {
                    // Если мы режем текст внутри таблицы, нужно ПРИНУДИТЕЛЬНО закрыть тег таблицы
                    if ($insidePreTag) {
                        $allowedLines[] = '</pre>';
                    }
                    $allowedLines[] = '...';
                    break;
                }

                $allowedLines[] = $line;
                $currentLength += $lineLength;

                if (strpos($line, '</pre>') !== false) {
                    $insidePreTag = false;
                }
            }

            $text = implode("\n", $allowedLines);
            $text = preg_replace('/<[^>]*$/u', '', $text); // Чистим обрубки тегов на самом конце

            // Финальная проверка на закрытие всех остальных базовых тегов
            $supportedTags = ['b', 'strong', 'i', 'em', 'u', 's', 'strike', 'code', 'pre', 'a'];
            foreach ($supportedTags as $tag) {
                $opened = mb_substr_count($text, "<{$tag}>", 'UTF-8') + mb_substr_count($text, "<{$tag} ", 'UTF-8');
                $closed = mb_substr_count($text, "</{$tag}>", 'UTF-8');
                if ($opened > $closed) {
                    $text .= "</{$tag}>";
                }
            }
        }

        return $text;
    }
}