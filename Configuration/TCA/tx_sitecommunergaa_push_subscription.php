<?php

return [
    'ctrl' => [
        'title' => 'Abonnements Push Mobile (PWA)',
        'label' => 'endpoint',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'searchFields' => 'endpoint,user_agent',
        'iconfile' => 'EXT:site_commune_rgaa/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => ['showitem' => 'hidden, endpoint, p256dh, auth, user_agent, crdate'],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.visible',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true
                    ]
                ],
            ],
        ],
        'endpoint' => [
            'exclude' => true,
            'label' => 'Endpoint WebPush',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'readOnly' => true,
            ],
        ],
        'p256dh' => [
            'exclude' => true,
            'label' => 'Clef Client P256DH',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'readOnly' => true,
            ],
        ],
        'auth' => [
            'exclude' => true,
            'label' => 'Clef Client Auth',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'readOnly' => true,
            ],
        ],
        'user_agent' => [
            'exclude' => true,
            'label' => 'Navigateur / Device',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'readOnly' => true,
            ],
        ],
        'crdate' => [
            'exclude' => true,
            'label' => 'Date d\'abonnement',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'readOnly' => true,
            ],
        ],
    ],
];
