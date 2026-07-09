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
            return $this->createPixelResponse();
        }

        $query = array_merge($request->query->all(), $request->request->all());

        if (empty($query)) {
            return $this->createPixelResponse();
        }

        $leadRepository = $leadModel->getRepository();

        $result = $leadModel->checkForDuplicateContact($query, true, true);
        /** @var Lead $leadFromQuery */
        $leadFromQuery                      = $result[0];
        $this->publiclyUpdatableFieldValues = $result[1];
        $uniqueLeadIdentifiers              = $this->dataProviderUtility->getUniqueIdentifierFieldNames();

        if (!$this->hasPubliclyUpdatableUniqueIdentifier($uniqueLeadIdentifiers)) {
            return $this->createPixelResponse();
        }

        $leadFromCookie = $request->cookies->get('mtc_id');

        if (null !== $leadFromCookie) {
            /** @var Lead $leadFromCookie */
            $leadFromCookie = $leadModel->getEntity($leadFromCookie);
        }

        if (empty($leadFromCookie)) {
            if ($leadFromQuery->getId() > 0) {
                $this->contactTracker->setTrackedContact($leadFromQuery);
            }

            // getContact() does not apply the query params — updateLeadWithQueryParams() handles that
            $lead = $this->contactTracker->getContact();

            if (null === $lead) {
                $this->logger->error(sprintf('%s: No contact was created, usually this means that an active user-session (Mautic login) was found! Try it again in another browser or use a tab in privacy-mode.', self::LOG_PREFIX));

                return $this->createPixelResponse();
            }

            $this->updateLeadWithQueryParams($lead, $query, $leadRepository);

            return $this->createPixelResponse();
        }

        $featureSettings = $this->config->getFeatureSettings();

        if (empty($featureSettings['parameter_primary'] ?? null)) {
            $this->logger->error(sprintf('%s: Required feature-setting "parameter_primary" is not configured.', self::LOG_PREFIX));

            return $this->createPixelResponse();
        }

        try {
            if ($this->leadMatchesQueryByPrimaryParameter($leadFromCookie, $leadFromQuery, $query, $featureSettings)) {
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
            $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
            $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $this->request->server->get('HTTP_USER_AGENT'));
            $this->addAuditLogForLead($leadFromQuery, 'identified', ['message' => sprintf('Exchange lead by respond with Mautic cookie "mtc_id=%d"', $leadFromQuery->getId())]);

            return $this->createPixelResponse();
        }

        if ($this->hasCookieLeadEmptyUniqueIdentifiers($leadFromCookie, $uniqueLeadIdentifiers)) {
            $this->updateLeadWithQueryParams($leadFromCookie, $query, $leadRepository);

            return $this->createPixelResponse();
        }

        $leadRepository->saveEntity($leadFromQuery);
        $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
        $this->addAuditLogForLead($leadFromQuery, 'create', ['message' => sprintf('Created new lead and respond Mautic cookie "mtc_id=%d"', $leadFromQuery->getId())]);

        // MAUTIC_LEAD_LASTACTIVE_LOGGED prevents a duplicate last-active update if another part of the request already logged it
        if (!defined('MAUTIC_LEAD_LASTACTIVE_LOGGED')) {
            $leadRepository->updateLastActive($leadFromQuery->getId());
            define('MAUTIC_LEAD_LASTACTIVE_LOGGED', 1);
        }

        $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $request->server->get('HTTP_USER_AGENT'));

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

    /**
     * @param string[] $uniqueLeadIdentifiers
     */
    private function hasPubliclyUpdatableUniqueIdentifier(array $uniqueLeadIdentifiers): bool
    {
        $publiclyUpdatableFieldNames = array_keys($this->publiclyUpdatableFieldValues);

        return count(array_intersect($publiclyUpdatableFieldNames, $uniqueLeadIdentifiers)) > 0;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $featureSettings
     *
     * @throws EnforceMatchingException
     */
    private function leadMatchesQueryByPrimaryParameter(Lead $lead, Lead $leadFromQuery, array $query, array $featureSettings): bool
    {
        if (array_key_exists($featureSettings['parameter_primary'], $query)) {
            $fieldGetterNamePrimary = 'get'.$featureSettings['parameter_primary']; // CustomFieldEntityTrait resolves the correct getter even for underscored field aliases

            $result = $lead->$fieldGetterNamePrimary() === $leadFromQuery->$fieldGetterNamePrimary();

            if (!empty($featureSettings['parameter_secondary'] ?? null)) {
                $fieldGetterNameSecondary = 'get'.$featureSettings['parameter_secondary'];

                if (!array_key_exists($featureSettings['parameter_secondary'], $query) || $leadFromQuery->$fieldGetterNameSecondary() !== $query[$featureSettings['parameter_secondary']]) {
                    throw new EnforceMatchingException(sprintf('The given lead #%d matches the query-lead #%d using configured primary-parameter "%s" for identification, but the secondary-parameter "%s" did not match!', $lead->getId(), $leadFromQuery->getId(), $featureSettings['parameter_primary'], $featureSettings['parameter_secondary']), 1695899935);
                }
            }

            return $result;
        }

        return true;
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
