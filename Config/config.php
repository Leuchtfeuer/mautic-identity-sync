<?php

return [
    'name'        => 'Identity-Sync',
    'description' => 'Allow sync of Mautic lead identity from external systems (e.g. CMS login) through a control-pixel with query-parameter.',
    'version'     => '7.0.0',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
    'routes'      => [
        'public' => [
            'identity_control' => [
                'path'       => '/mcontrol.gif',
                'controller' => 'MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller\PublicController::identityControlImageAction',
            ],
        ],
    ],
];
