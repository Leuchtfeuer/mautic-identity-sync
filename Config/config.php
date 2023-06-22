<?php

return [
    'name' => 'Identity-Sync',
    'description' => 'Allow sync of Mautic lead identity from external systems (e.g. CMS login) through a control-pixel with query-parameter.',
    'version' => '1.0.0',
    'author' => 'Leuchtfeuer',
    'routes' => [
        'public' => [
            'identity_control' => [
                'path' => '/identity-control.gif',
                'controller' => 'LeuchtfeuerIdentitySyncBundle:Public:identityControlImage',
            ],
        ],
    ],
    'services' => [
        'command' => [],
        'other' => [
            'identitysync.config' => [
                'class' => \MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Config::class,
                'arguments' => [
                    'mautic.integrations.helper',
                ],
            ],
        ],
        'events' => [],
        'forms' => [],
        'models' => [],
        'fixtures' => [],
        'integrations' => [
            'mautic.integration.leuchtfeueridentitysync' => [
                'class' => \MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\LeuchtfeuerIdentitySyncIntegration::class,
                'tags' => [
                    'mautic.integration',
                    'mautic.basic_integration',
                ],
            ],
            'leuchtfeueridentitysync.integration.configuration' => [
                'class' => \MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Support\ConfigSupport::class,
                'tags' => [
                    'mautic.config_integration',
                ],
            ],
        ],
    ],
];