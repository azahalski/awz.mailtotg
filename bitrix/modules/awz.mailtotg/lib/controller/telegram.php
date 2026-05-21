<?php

namespace Awz\Mailtotg\Controller;

use Awz\Mailtotg\Filters\HookAuth;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Config\Option;

class Telegram extends Controller
{
    /**
     * Конфигурация действий контроллера
     *
     * @return array
     */
    public function configureActions()
    {
        $config = [
            'webhook' => [
                'prefilters' => [
                    new HookAuth(['keyOption' => 'HOOK_KEY'])
                ]
            ],
        ];

        return $config;
    }

    /**
     * Обработка вебхука от Telegram
     * Получает chat_id из сообщения и сохраняет в настройках модуля
     *
     * @param string $key Ключ доступа (проверяется в префильтре HookAuth)
     * @return array
     */
    public function webhookAction($key = '')
    {
        $request = $this->getRequest();
        $rawData = file_get_contents('php://input');

        if (!$rawData) {
            // Пробуем получить данные из POST (для тестов)
            $rawData = $request->getPostList()->toArray();
        } else {
            $rawData = json_decode($rawData, true);
        }

        if (empty($rawData)) {
            $this->addError(new Error('No data received from Telegram'));
            return ['status' => 'error', 'message' => 'No data received'];
        }

        // Telegram webhook структура: {"update_id":..., "message":{"chat":{"id":...}}}
        // или {"update_id":..., "callback_query":{"message":{"chat":{"id":...}}}}

        $chatId = null;

        if (isset($rawData['message']['chat']['id'])) {
            $chatId = $rawData['message']['chat']['id'];
        } elseif (isset($rawData['callback_query']['message']['chat']['id'])) {
            $chatId = $rawData['callback_query']['message']['chat']['id'];
        } elseif (isset($rawData['chat_id'])) {
            // Для тестирования
            $chatId = $rawData['chat_id'];
        }

        if (!$chatId) {
            $this->addError(new Error('Chat ID not found in webhook data'));
            return ['status' => 'error', 'message' => 'Chat ID not found'];
        }

        // Сохраняем chat_id в настройках модуля
        Option::set('awz.mailtotg', 'TGID', (string)$chatId, "");

        return [
            'status' => 'success',
            'message' => 'Chat ID saved successfully',
            'chat_id' => $chatId
        ];
    }

    /**
     * Финализация ответа - вывод в формате JSON для Telegram
     *
     * @param \Bitrix\Main\Response $response
     */
    public function finalizeResponse(\Bitrix\Main\Response $response)
    {
        $content = $response->getContent();
        $response->setContent($content);
    }
}