<?php

return [
    'name'        => 'Identity-Sync',
    'description' => 'Allow sync of Mautic lead identity from external systems (e.g. CMS login) through a control-pixel with query-parameter.',
    'version'     => '2.1.0',
    'author'      => 'Leuchtfeuer',
    'routes'      => [
        'public' => [
            'identity_control' => [
                'path'       => '/mcontrol.gif',
                'controller' => 'MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller\PublicController::identityControlImageAction',
            ],
        ],
    ],
    'services' => [
        'other' => [
            'leuchtfeueridentitysync.config' => [
                'class'     => MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Config::class,
                'arguments' => [
                    'mautic.integrations.helper',
                ],
            ],
        ],
        'integrations' => [
            'mautic.integration.leuchtfeueridentitysync' => [
                'class' => MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\LeuchtfeuerIdentitySyncIntegration::class,
                'tags'  => [
                    'mautic.integration',
                    'mautic.basic_integration',
                ],
            ],
            'leuchtfeueridentitysync.integration.configuration' => [
                'class' => MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Support\ConfigSupport::class,
                'tags'  => [
                    'mautic.config_integration',
                ],
            ],
        ],
    ],
];
