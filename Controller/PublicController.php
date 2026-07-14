<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\AbstractFormController;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\LeadBundle\Tracker\DeviceTracker;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Exception\EnforceMatchingException;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Utility\DataProviderUtility;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends AbstractFormController
{
    protected Request $request;

    /** @var array<string, string> */
    protected array $publiclyUpdatableFieldValues = [];

    protected const LOG_PREFIX                    = 'MCONTROL';

    public function __construct(
        protected Config $config,
        protected DataProviderUtility $dataProviderUtility,
        protected ContactTracker $contactTracker,
        protected DeviceTracker $deviceTracker,
        protected CookieHelper $cookieHelper,
        protected IpLookupHelper $ipLookupHelper,
        protected AuditLogModel $auditLogModel,
        protected Logger $logger,
        RequestStack $requestStack,
        ManagerRegistry $doctrine,
        ModelFactory $modelFactory,
        UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        CorePermissions $security,
    ) {
        parent::__construct(
            $doctrine,
            $modelFactory,
            $userHelper,
            $coreParametersHelper,
            $dispatcher,
            $translator,
            $flashBag,
            $requestStack,
            $security
        );
        $this->request = $requestStack->getCurrentRequest();
    }

    public function identityControlImageAction(Request $request, LeadModel $leadModel): Response
    {
        if (!$this->config->isPublished()) {
            return $this->anonymousTrackingResponse();
        }

        $featureSettings = $this->config->getFeatureSettings();

        if (empty($featureSettings['parameter_primary'] ?? null)) {
            $this->logger->error(sprintf('%s: Required feature-setting "parameter_primary" is not configured.', self::LOG_PREFIX));

            return $this->anonymousTrackingResponse();
        }

        $query = array_merge($request->query->all(), $request->request->all());

        if (empty($query)) {
            return $this->anonymousTrackingResponse();
        }

        $leadRepository                     = $leadModel->getRepository();
        $result                             = $leadModel->checkForDuplicateContact($query, true, true);
        /** @var Lead $leadFromQuery */
        $leadFromQuery                      = $result[0];
        $this->publiclyUpdatableFieldValues = $result[1];
        $uniqueLeadIdentifiers              = $this->dataProviderUtility->getUniqueIdentifierFieldNames();

        if (!$this->hasPubliclyUpdatableUniqueIdentifier($uniqueLeadIdentifiers)) {
            return $this->anonymousTrackingResponse();
        }

        $cookieId       = $request->cookies->get('mtc_id');
        $leadFromCookie = null !== $cookieId ? $leadModel->getEntity($cookieId) : null;

        if (empty($leadFromCookie)) {
            return $this->processWithoutCookie($leadFromQuery, $query, $featureSettings, $leadRepository);
        }

        return $this->processWithCookie($leadFromCookie, $leadFromQuery, $query, $featureSettings, $leadRepository, $uniqueLeadIdentifiers);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $featureSettings
     */
    private function processWithoutCookie(Lead $leadFromQuery, array $query, array $featureSettings, LeadRepository $leadRepository): Response
    {
        if (!array_key_exists($featureSettings['parameter_primary'], $query)) {
            return $this->anonymousTrackingResponse();
        }

        if ($leadFromQuery->getId() > 0) {
            try {
                if (!$this->leadMatchesQueryByIdentifierParameters($leadFromQuery, $leadFromQuery, $query, $featureSettings)) {
                    return $this->anonymousTrackingResponse();
                }
            } catch (EnforceMatchingException $e) {
                $this->logger->error(sprintf('%s: %s (%d)', self::LOG_PREFIX, $e->getMessage(), $e->getCode()));

                return $this->anonymousTrackingResponse();
            }

            $this->contactTracker->setTrackedContact($leadFromQuery);
        }

        $lead = $this->contactTracker->getContact();

        if (null === $lead) {
            $this->logger->error(sprintf('%s: No contact was created, usually this means that an active user-session (Mautic login) was found! Try it again in another browser or use a tab in privacy-mode.', self::LOG_PREFIX));

            return $this->createPixelResponse();
        }

        $this->updateLeadWithQueryParams($lead, $query, $leadRepository);

        return $this->createPixelResponse();
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $featureSettings
     * @param string[]             $uniqueLeadIdentifiers
     */
    private function processWithCookie(Lead $leadFromCookie, Lead $leadFromQuery, array $query, array $featureSettings, LeadRepository $leadRepository, array $uniqueLeadIdentifiers): Response
    {
        if (!array_key_exists($featureSettings['parameter_primary'], $query)) {
            return $this->anonymousTrackingResponse();
        }

        try {
            if ($this->leadMatchesQueryByIdentifierParameters($leadFromCookie, $leadFromQuery, $query, $featureSettings)) {
                // setTrackedContact() + getContact() updates the last-activity timestamp
                $this->contactTracker->setTrackedContact($leadFromCookie);
                $this->contactTracker->getContact();

                $this->updateLeadWithQueryParams($leadFromCookie, $query, $leadRepository);

                return $this->createPixelResponse();
            }
        } catch (EnforceMatchingException $e) {
            $this->logger->error(sprintf('%s: %s (%d)', self::LOG_PREFIX, $e->getMessage(), $e->getCode()));

            return $this->createPixelResponse();
        }

        if ($leadFromQuery->getId() > 0) {
            return $this->processExistingQueryLead($leadFromQuery);
        }

        if ($this->hasCookieLeadEmptyUniqueIdentifiers($leadFromCookie, $uniqueLeadIdentifiers)) {
            $this->updateLeadWithQueryParams($leadFromCookie, $query, $leadRepository);

            return $this->createPixelResponse();
        }

        return $this->processNewLead($leadFromQuery, $leadRepository);
    }

    /**
     * A known lead was found via the query params — swap the cookie to that lead.
     */
    private function processExistingQueryLead(Lead $leadFromQuery): Response
    {
        $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
        $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $this->request->server->get('HTTP_USER_AGENT'));
        $this->addAuditLogForLead($leadFromQuery, 'identified', ['message' => sprintf('Exchange lead by respond with Mautic cookie "mtc_id=%d"', $leadFromQuery->getId())]);

        return $this->createPixelResponse();
    }

    private function processNewLead(Lead $leadFromQuery, LeadRepository $leadRepository): Response
    {
        $leadRepository->saveEntity($leadFromQuery);
        $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
        $this->addAuditLogForLead($leadFromQuery, 'create', ['message' => sprintf('Created new lead and respond Mautic cookie "mtc_id=%d"', $leadFromQuery->getId())]);

        // MAUTIC_LEAD_LASTACTIVE_LOGGED prevents a duplicate last-active update if another part of the request already logged it
        if (!defined('MAUTIC_LEAD_LASTACTIVE_LOGGED')) {
            $leadRepository->updateLastActive($leadFromQuery->getId());
            define('MAUTIC_LEAD_LASTACTIVE_LOGGED', 1);
        }

        $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $this->request->server->get('HTTP_USER_AGENT'));

        return $this->createPixelResponse();
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function updateLeadWithQueryParams(Lead $lead, array $query, LeadRepository $leadRepository): void
    {
        $leadUpdated = false;

        foreach ($this->publiclyUpdatableFieldValues as $leadField => $value) {
            $fieldSetterName = 'set'.$leadField; // CustomFieldEntityTrait resolves the correct setter even for underscored field aliases
            $lead->$fieldSetterName($query[$leadField]);
            $leadUpdated = true;
        }

        if ($leadUpdated) {
            try {
                $leadRepository->saveEntity($lead);
            } catch (\Exception $e) {
                $this->logger->error(sprintf('%s: Failed to save lead #%d', self::LOG_PREFIX, $lead->getId()), ['exception' => $e]);
            }
        }
    }

    /**
     * @param string               $action  allowed values: 'identified', 'create', 'update'
     * @param array<string, mixed> $details serialized and shown in the audit-log detail toggle
     */
    protected function addAuditLogForLead(Lead $lead, string $action, array $details = []): void
    {
        $this->auditLogModel->writeToLog([
            'bundle'    => 'lead', // must be 'lead' to appear in the contact's audit-log tab
            'object'    => 'lead',
            'objectId'  => $lead->getId(),
            'action'    => $action,
            'details'   => $details,
            'ipAddress' => $this->ipLookupHelper->getIpAddressFromRequest(),
        ]);
    }

    protected function createPixelResponse(): Response
    {
        return TrackingPixelHelper::getResponse($this->request);
    }

    private function anonymousTrackingResponse(): Response
    {
        $this->contactTracker->getContact();

        return $this->createPixelResponse();
    }

    /**
     * @param string[] $uniqueLeadIdentifiers
     */
    private function hasPubliclyUpdatableUniqueIdentifier(array $uniqueLeadIdentifiers): bool
    {
        $publiclyUpdatableFieldNames = array_keys($this->publiclyUpdatableFieldValues);

        return count(array_intersect($publiclyUpdatableFieldNames, $uniqueLeadIdentifiers)) > 0;
    }

    /**
     * Checks whether the cookie lead matches the query lead using the configured primary parameter.
     * If a secondary parameter is configured, it must also match — otherwise an exception is thrown.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $featureSettings
     *
     * @throws EnforceMatchingException
     */
    private function leadMatchesQueryByIdentifierParameters(Lead $lead, Lead $leadFromQuery, array $query, array $featureSettings): bool
    {
        if (!array_key_exists($featureSettings['parameter_primary'], $query)) {
            throw new \LogicException(sprintf('Primary parameter "%s" must be present in query before calling this method.', $featureSettings['parameter_primary']));
        }

        $primaryGetter  = 'get'.$featureSettings['parameter_primary']; // CustomFieldEntityTrait resolves the correct getter even for underscored field aliases
        $primaryMatches = $lead->$primaryGetter() === $leadFromQuery->$primaryGetter();

        $secondary = $featureSettings['parameter_secondary'] ?? null;

        if (empty($secondary)) {
            return $primaryMatches;
        }

        $secondaryGetter  = 'get'.$secondary;
        $secondaryMatches = array_key_exists($secondary, $query) && $leadFromQuery->$secondaryGetter() === $query[$secondary];

        if (!$secondaryMatches) {
            throw new EnforceMatchingException(sprintf('The given lead #%d matches the query-lead #%d using configured primary-parameter "%s" for identification, but the secondary-parameter "%s" did not match!', $lead->getId(), $leadFromQuery->getId(), $featureSettings['parameter_primary'], $secondary), 1695899935);
        }

        return $primaryMatches;
    }

    /**
     * @param string[] $uniqueLeadIdentifiers
     */
    private function hasCookieLeadEmptyUniqueIdentifiers(Lead $lead, array $uniqueLeadIdentifiers): bool
    {
        foreach ($uniqueLeadIdentifiers as $uniqueLeadIdentifier) {
            $fieldGetterName = 'get'.$uniqueLeadIdentifier; // CustomFieldEntityTrait resolves the correct getter even for underscored field aliases
            if (empty($lead->$fieldGetterName())) {
                return true;
            }
        }

        return false;
    }
}
