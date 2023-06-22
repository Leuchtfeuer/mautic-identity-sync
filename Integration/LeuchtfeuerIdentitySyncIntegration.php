<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class LeuchtfeuerIdentitySyncIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME = 'leuchtfeueridentitysync';
    public const DISPLAY_NAME = 'Identity-Sync';

    /**
     * @return string
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @return string
     */
    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    /**
     * @return string
     */
    public function getIcon(): string
    {
        return 'plugins/LeuchtfeuerIdentitySyncBundle/Assets/img/leuchtfeuer-mautic-identitysync.png';
    }
}