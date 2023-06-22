<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Support;

use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\LeuchtfeuerIdentitySyncIntegration;

class ConfigSupport extends LeuchtfeuerIdentitySyncIntegration implements ConfigFormInterface
{
    use DefaultConfigFormTrait;

    public function __construct()
    {}
}