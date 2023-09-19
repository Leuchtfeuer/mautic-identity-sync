<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormFeatureSettingsInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\LeuchtfeuerIdentitySyncIntegration;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Form\Type\ConfigFeaturesType;

class ConfigSupport extends LeuchtfeuerIdentitySyncIntegration implements ConfigFormInterface, ConfigFormFeatureSettingsInterface
{
    use DefaultConfigFormTrait;

    public function __construct()
    {}

    public function getFeatureSettingsConfigFormName(): string
    {
        return ConfigFeaturesType::class;
    }
}