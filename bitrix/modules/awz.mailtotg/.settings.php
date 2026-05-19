<?php
return [
    'ui.entity-selector' => [
        'value' => [
            'entities' => [
                [
                    'entityId' => 'awzmailtotg-user',
                    'provider' => [
                        'moduleId' => 'awz.mailtotg',
                        'className' => '\\Awz\\Mailtotg\\Access\\EntitySelectors\\User'
                    ],
                ],
                [
                    'entityId' => 'awzmailtotg-group',
                    'provider' => [
                        'moduleId' => 'awz.mailtotg',
                        'className' => '\\Awz\\Mailtotg\\Access\\EntitySelectors\\Group'
                    ],
                ],
            ]
        ],
        'readonly' => true,
    ]
];