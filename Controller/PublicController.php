<?php

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Factory\MauticFactory;
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
use Mautic\FormBundle\Helper\FormFieldHelper;
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
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    protected LeadRepository $leadRepository;
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
        FormFactoryInterface $formFactory,
        FormFieldHelper $fieldHelper,
        ManagerRegistry $doctrine,
        MauticFactory $factory,
        ModelFactory $modelFactory,
        UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        CorePermissions $security
    ) {
        parent::__construct(
            $formFactory,
            $fieldHelper,
            $doctrine,
            $factory,
            $modelFactory,
            $userHelper,
            $coreParametersHelper,
            $dispatcher,
            $translator,
            $flashBag,
            $requestStack,
            $security
        );
        $this->request        = $requestStack->getCurrentRequest();
    }

    /**
     * @throws \Exception
     */
    public function identityControlImageAction(Request $request): Response
    {
        // do nothing if plugin is disabled
        if (!$this->config->isPublished()) {
            return $this->createPixelResponse($request);
        }

        $get   = $request->query->all();
        $post  = $request->request->all();
        $query = \array_merge($get, $post);

        // end response if no query params are given
        if (empty($query)) {
            return $this->createPixelResponse($request);
        }

        // check if at least one query param-field is a unique-identifier and publicly-updatable
        /** @var LeadModel $leadModel */
        $leadModel            = $this->getModel('lead');
        $this->leadRepository = $leadModel->getRepository();
        $result               = $leadModel->checkForDuplicateContact($query, true, true);

        $result = $leadModel->checkForDuplicateContact($query, true, true);
        /** @var Lead $leadFromQuery */
        $leadFromQuery                      = $result[0];
        $this->publiclyUpdatableFieldValues = $result[1];
        $uniqueLeadIdentifiers              = $this->dataProviderUtility->getUniqueIdentifierFieldNames();

        $isAtLeastOneUniqueIdentifierPubliclyUpdatable = function () use ($uniqueLeadIdentifiers): bool {
            $publiclyUpdatableFieldNames = array_keys($this->publiclyUpdatableFieldValues);

            return count(array_intersect($publiclyUpdatableFieldNames, $uniqueLeadIdentifiers)) > 0;
        };

        // end response if not at least one unique publicly-updatable field exists
        if (!$isAtLeastOneUniqueIdentifierPubliclyUpdatable()) {
            return $this->createPixelResponse($request);
        }

        // check if cookie-lead exists
        $leadFromCookie = $request->cookies->get('mtc_id', null);

        if (null !== $leadFromCookie) {
            /** @var Lead $leadFromCookie */
            $leadFromCookie = $leadModel->getEntity($leadFromCookie);
        }

        // no cookie-lead is available
        if (empty($leadFromCookie)) {
            // check if a query-lead exists as contact already, if not creating a new one
            if ($leadFromQuery->getId() > 0) {
                $this->contactTracker->setTrackedContact($leadFromQuery);
            }

            // create lead with values from query param, set cookie and end response
            $lead = $this->contactTracker->getContact(); // this call does not set the given query-params, we've to manually add them via updateLeadWithQueryParams()

            if (null === $lead) {
                $this->logger->error(sprintf('%s: No contact was created, usually this means that an active user-session (Mautic login) was found! Try it again in another browser or use a tab in privacy-mode.', self::LOG_PREFIX));

                return $this->createPixelResponse($request);
            }

            $this->updateLeadWithQueryParams($lead, $query);

            return $this->createPixelResponse($request);
        }

        // get feature-settings from plugin configuration
        $featureSettings = $this->config->getFeatureSettings();

        // check if unique field-values matching the cookie-lead
        $uniqueIdentifiersFromQueryLeadMatchingLead = function (Lead $lead) use ($leadFromQuery, $query, $featureSettings): bool {
            if (empty($featureSettings['parameter_primary'] ?? null)) {
                throw new \Exception('The required plugin feature-setting "parameter_primary" is not set!');
            }

            // first checking the configured primary-parameter
            if (array_key_exists($featureSettings['parameter_primary'], $query)) {
                $fieldGetterNamePrimary = 'get'.$featureSettings['parameter_primary']; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)

                $result = true;
                if ($lead->$fieldGetterNamePrimary() !== $leadFromQuery->$fieldGetterNamePrimary()) {
                    $result = false;
                }

                // check if the secondary-parameter is set to enforce matching
                if (!empty($featureSettings['parameter_secondary'] ?? null)) {
                    $fieldGetterNameSecondary = 'get'.$featureSettings['parameter_secondary'];

                    // check if the secondary-parameter exist in the query and that it matches the query-lead, if not stop processing here
                    if (!array_key_exists($featureSettings['parameter_secondary'], $query) || $leadFromQuery->$fieldGetterNameSecondary() !== $query[$featureSettings['parameter_secondary']]) {
                        // the secondary-parameter didn't match. we stop processing here by throwing an exception to force the caller to implement a handling like writing a log or audit entry (seen as $result = false, but to stop processing we use an exception)
                        throw new EnforceMatchingException(sprintf('The given lead #%d matches the query-lead #%d using configured primary-parameter "%s" for identification, but the secondary-parameter "%s" did not match!', $lead->getId(), $leadFromQuery->getId(), $featureSettings['parameter_primary'], $featureSettings['parameter_secondary']), 1695899935);
                    }
                }

                return $result;
            }

            return true;
        };

        // @deprecated: the following code used a generic approach, checking all unique lead fields dynamically. with refactoring of MTC-4357 the fields to work with are configured in plugin feature-settings.
        // check if unique field-values matching the cookie-lead (generic approach checking all unique-fields)
        /*$uniqueIdentifierFromQueryLeadMatchingLead = function (Lead $lead) use ($leadFromQuery, $uniqueLeadIdentifiers, $query) {
            $result = true;
            foreach ($uniqueLeadIdentifiers as $uniqueLeadIdentifier) {
                if (array_key_exists($uniqueLeadIdentifier, $query)) {
                    $fieldGetterName = 'get'.$uniqueLeadIdentifier; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)
                    if ($lead->$fieldGetterName() !== $leadFromQuery->$fieldGetterName()) {
                        $result = false;
                        break;
                    }
                }
            }

            return $result;
        };*/

        try {
            if ($uniqueIdentifiersFromQueryLeadMatchingLead($leadFromCookie)) {
                // we call ContactTracker->getContact() here to update the last-activity
                $this->contactTracker->setTrackedContact($leadFromCookie);
                $this->contactTracker->getContact();

                // update publicly-updatable fields of cookie-lead with query param values and end response
                $this->updateLeadWithQueryParams($leadFromCookie, $query);

                return $this->createPixelResponse($request);
            }
        } catch (EnforceMatchingException $e) {
            $this->logger->error(sprintf('%s: %s (%d)', self::LOG_PREFIX, $e->getMessage(), $e->getCode()));

            return $this->createPixelResponse($request);
        }

        // exchange cookie with ID from query-lead and end response
        if ($leadFromQuery->getId() > 0) {
            $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
            // create a device for the lead here which sets the device-tracking cookies
            $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $this->request->server->get('HTTP_USER_AGENT'));
            // write audit-log for query-lead
            $message = sprintf('Exchange lead by respond with Mautic cookie "mtc_id=%d"', $leadFromQuery->getId());
            $this->addAuditLogForLead($leadFromQuery, 'identified', ['message' => $message]);

            return $this->createPixelResponse($request);
        }

        // check if the unique-identifiers of the cookie-lead are empty
        $uniqueIdentifiersFromCookieLeadAreEmpty = function (Lead $lead) use ($uniqueLeadIdentifiers): bool {
            $result = false;
            foreach ($uniqueLeadIdentifiers as $uniqueLeadIdentifier) {
                $fieldGetterName = 'get'.$uniqueLeadIdentifier; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)
                if (empty($lead->$fieldGetterName())) {
                    $result = true;
                    break;
                }
            }

            return $result;
        };
        if ($uniqueIdentifiersFromCookieLeadAreEmpty($leadFromCookie)) {
            // update publicly-updatable fields of cookie-lead with query param values and end response
            $this->updateLeadWithQueryParams($leadFromCookie, $query);

            return $this->createPixelResponse($request);
        }

        // create new lead with values from query, set cookie and end response
        $this->leadRepository->saveEntity($leadFromQuery);
        $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
        // write audit-log for query-lead
        $message = sprintf('Created new lead and respond Mautic cookie "mtc_id=%d"', $leadFromQuery->getId());
        $this->addAuditLogForLead($leadFromQuery, 'create', ['message' => $message]);

        // manually log last active for new created lead
        if (!defined('MAUTIC_LEAD_LASTACTIVE_LOGGED')) {
            $this->leadRepository->updateLastActive($leadFromQuery->getId());
            define('MAUTIC_LEAD_LASTACTIVE_LOGGED', 1);
        }

        // create a device for the lead here which sets the device-tracking cookies
        $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $request->server->get('HTTP_USER_AGENT'));

        return $this->createPixelResponse($request);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @throws OptimisticLockException
     * @throws ORMException
     */
    protected function updateLeadWithQueryParams(Lead $lead, array $query): void
    {
        $leadUpdated = false;

        foreach ($this->publiclyUpdatableFieldValues as $leadField => $value) {
            // update lead with values from query
            $fieldSetterName = 'set'.$leadField; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)
            $lead->$fieldSetterName($query[$leadField]);
            $leadUpdated = true;
        }

        if ($leadUpdated) {
            $this->leadRepository->saveEntity($lead);
        }
    }

    /**
     * Create audit-log for lead.
     *
     * @param string               $action  should be 'identified', 'create' or 'update'
     * @param array<string, mixed> $details will be serialized and shown in audit-log toggle e.g. if action is 'update'
     */
    protected function addAuditLogForLead(Lead $lead, string $action, array $details = []): void
    {
        $log = [
            'bundle'    => 'lead', // must be set to "lead" otherwise it's not shown in lead view (tab "Audit log")
            'object'    => 'lead',
            'objectId'  => $lead->getId(),
            'action'    => $action,
            'details'   => $details,
            'ipAddress' => $this->ipLookupHelper->getIpAddressFromRequest(),
        ];
        $this->auditLogModel->writeToLog($log);
    }

    /**
     * This method creates the return value for the action response.
     */
    protected function createPixelResponse(Request $request): Response
    {
        return TrackingPixelHelper::getResponse($this->request);
    }
}
