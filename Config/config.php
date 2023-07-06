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
        'controllers' => [
            'leuchtfeueridentitysync.controller.public' => [
                'class' => \MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller\PublicController::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'leuchtfeueridentitysync.model.page',
                    'mautic.helper.cookie',
                ],
            ],
        ],
        'other' => [
            'leuchtfeueridentitysync.config' => [
                'class' => \MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Config::class,
                'arguments' => [
                    'mautic.integrations.helper',
                ],
            ],
        ],
        'events' => [],
        'forms' => [],
        'models' => [
            'leuchtfeueridentitysync.model.page' => [
                'class' => \MauticPlugin\LeuchtfeuerIdentitySyncBundle\Model\PageModel::class,
                'arguments' => [
                    'mautic.helper.cookie',
                    'mautic.helper.ip_lookup',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.field',
                    'mautic.page.model.redirect',
                    'mautic.page.model.trackable',
                    'mautic.queue.service',
                    'mautic.lead.model.company',
                    'mautic.tracker.device',
                    'mautic.tracker.contact',
                    'mautic.helper.core_parameters',
                ],
                'methodCalls' => [
                    'setCatInUrl' => [
                        '%mautic.cat_in_page_url%',
                    ],
                ],
            ],
        ],
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