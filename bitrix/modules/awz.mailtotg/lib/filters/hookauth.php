<?php
namespace Awz\Mailtotg\Filters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Config\Option;

class HookAuth extends Base
{

    protected $keyOption = 'HOOK_KEY';

    /**
     * @param array $params
     */
    public function __construct(array $params = array())
    {
        parent::__construct($params);
        if (isset($params['keyOption'])) {
            $this->keyOption = $params['keyOption'];
        }
    }

    /**
     * Проверка ключа в action параметрах
     *
     * @param Event $event
     * @return EventResult|null
     */
    public function onBeforeAction(Event $event)
    {
        $controller = $this->getAction()->getController();
        $request = $controller->getRequest();

        // Получаем ключ из action параметров
        $providedKey = $request->get('key');

        if (!$providedKey) {
            $this->addError(new Error('Access key is required'));
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        // Получаем сохранённый ключ из настроек модуля
        $savedKey = Option::get('awz.mailtotg', $this->keyOption, '', '');

        if (!$savedKey) {
            $this->addError(new Error('Hook key not configured'));
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        // Проверка ключа
        if ($providedKey !== $savedKey) {
            $this->addError(new Error('Invalid access key'));
            return new EventResult(EventResult::ERROR, null, null, $this);
        }

        return new EventResult(EventResult::SUCCESS, null, null, $this);
    }
}