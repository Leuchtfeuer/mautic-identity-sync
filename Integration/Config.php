<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;

class Config
{
    /**
     * @var IntegrationsHelper
     */
    private IntegrationsHelper $integrationsHelper;

    /**
     * @param IntegrationsHelper $integrationsHelper
     */
    public function __construct(IntegrationsHelper $integrationsHelper)
    {
        $this->integrationsHelper = $integrationsHelper;
    }

    /**
     * @return bool
     */
    public function isPublished(): bool
    {
        try {
            $integration = $this->getIntegrationEntity();
            return (bool)$integration->getIsPublished();
        } catch (IntegrationNotFoundException $e) {
            return false;
        }
    }

    /**
     * @return array
     */
    public function getFeatureSettings(): array
    {
        try {
            $integration = $this->getIntegrationEntity();
            return ($integration->getFeatureSettings()['integration'] ?? []) ?: [];
        } catch (IntegrationNotFoundException $e) {
            return [];
        }
    }

    /**
     * @throws IntegrationNotFoundException
     */
    public function getIntegrationEntity(): Integration
    {
        $integrationObject = $this->integrationsHelper->getIntegration(LeuchtfeuerIdentitySyncIntegration::NAME);
        return $integrationObject->getIntegrationConfiguration();
    }
}