<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $excludes = [
    ];

    $services->load('MauticPlugin\\LeuchtfeuerIdentitySyncBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, ['rector.php'])).'}');

    $services->get(MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\LeuchtfeuerIdentitySyncIntegration::class)
        ->tag('mautic.integration')
        ->tag('mautic.basic_integration');

    $services->get(MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Support\ConfigSupport::class)
        ->tag('mautic.config_integration');

    $services->alias('mautic.integration.leuchtfeueridentitysync', MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\LeuchtfeuerIdentitySyncIntegration::class)
        ->public();
};
