<?php

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\LeadBundle\Tracker\DeviceTracker;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Exception\EnforceMatchingException;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Utility\DataProviderUtility;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    protected Config $config;
    protected DataProviderUtility $dataProviderUtility;
    protected ContactTracker $contactTracker;
    protected DeviceTracker $deviceTracker;
    protected CookieHelper $cookieHelper;
    protected IpLookupHelper $ipLookupHelper;
    protected AuditLogModel $auditLogModel;
    protected Logger $logger;
    protected LeadRepository $leadRepository;
    protected array $publiclyUpdatableFieldValues = [];
    protected const LOG_PREFIX = 'MCONTROL';

    public function __construct(
        Config $config,
        DataProviderUtility $dataProviderUtility,
        ContactTracker $contactTracker,
        DeviceTracker $deviceTracker,
        CookieHelper $cookieHelper,
        IpLookupHelper $ipLookupHelper,
        AuditLogModel $auditLogModel,
        Logger $logger
    ) {
        $this->config = $config;
        $this->dataProviderUtility = $dataProviderUtility;
        $this->contactTracker = $contactTracker;
        $this->deviceTracker = $deviceTracker;
        $this->cookieHelper = $cookieHelper;
        $this->ipLookupHelper = $ipLookupHelper;
        $this->auditLogModel = $auditLogModel;
        $this->logger = $logger;
    }

    /**
     * @return Response
     * @throws \Exception
     */
    public function identityControlImageAction(): Response
    {
        // do nothing if plugin is disabled
        if (!$this->config->isPublished()) {
            return $this->createPixelResponse($this->request);
        }

        $get  = $this->request->query->all();
        $post = $this->request->request->all();
        $query = \array_merge($get, $post);

        // end response if no query params are given
        if (empty($query)) {
            return $this->createPixelResponse($this->request);
        }

        // check if at least one query param-field is a unique-identifier and publicly-updatable
        /** @var LeadModel $model */
        $leadModel = $this->getModel('lead');
        $this->leadRepository = $leadModel->getRepository();

        /** @var Lead $leadFromQuery */
        [$leadFromQuery, $this->publiclyUpdatableFieldValues] = $leadModel->checkForDuplicateContact($query, null, true, true);
        $uniqueLeadIdentifiers = $this->dataProviderUtility->getUniqueIdentifierFieldNames();

        $isAtLeastOneUniqueIdentifierPubliclyUpdatable = function () use ($uniqueLeadIdentifiers) {
            $publiclyUpdatableFieldNames = array_keys($this->publiclyUpdatableFieldValues);
            return count(array_intersect($publiclyUpdatableFieldNames, $uniqueLeadIdentifiers)) > 0;
        };

        // end response if not at least one unique publicly-updatable field exists
        if (!$isAtLeastOneUniqueIdentifierPubliclyUpdatable()) {
            return $this->createPixelResponse($this->request);
        }

        // check if cookie-lead exists
        $leadFromCookie = $this->request->cookies->get('mtc_id', null);

        if ($leadFromCookie !== null) {
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

            if ($lead === null) {
                $this->logger->error(sprintf('%s: No contact was created, usually this means that an active user-session (Mautic login) was found! Try it again in another browser or use a tab in privacy-mode.', self::LOG_PREFIX));
                return $this->createPixelResponse($this->request);
            }

            $this->updateLeadWithQueryParams($lead, $query);
            return $this->createPixelResponse($this->request);
        }

        // get feature-settings from plugin configuration
        $featureSettings = $this->config->getFeatureSettings();

        // check if unique field-values matching the cookie-lead
        $uniqueIdentifierFromQueryLeadMatchingLead = function (Lead $lead) use ($leadFromQuery, $featureSettings, $query) {
            if (empty($featureSettings['parameter_primary'] ?? null)) {
                throw new \Exception('The required plugin feature-setting "parameter_primary" is not set!');
            }

            // first checking the configured primary-parameter
            if (array_key_exists($featureSettings['parameter_primary'], $query)) {
                $fieldGetterNamePrimary = 'get' . $featureSettings['parameter_primary']; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)

                $result = true;
                if ($lead->$fieldGetterNamePrimary() !== $leadFromQuery->$fieldGetterNamePrimary()) {
                    $result = false;
                }

                // check if the secondary-parameter is set to enforce matching
                if (!empty($featureSettings['parameter_secondary'] ?? null)) {
                    $fieldGetterNameSecondary = 'get' . $featureSettings['parameter_secondary'];

                    // check if the secondary-parameter exist in the query and that it matches the query-lead, if not stop processing here
                    if (!array_key_exists($featureSettings['parameter_secondary'], $query) || $leadFromQuery->$fieldGetterNameSecondary() !== ($query[$featureSettings['parameter_secondary']] ?? '')) {
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
                    $fieldGetterName = 'get' . $uniqueLeadIdentifier; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)
                    if ($lead->$fieldGetterName() !== $leadFromQuery->$fieldGetterName()) {
                        $result = false;
                        break;
                    }
                }
            }
            return $result;
        };*/

        try {
            if ($uniqueIdentifierFromQueryLeadMatchingLead($leadFromCookie)) {
                // we call ContactTracker->getContact() here to update the last-activity
                $this->contactTracker->setTrackedContact($leadFromCookie);
                $this->contactTracker->getContact();

                // update publicly-updatable fields of cookie-lead with query param values and end response
                $this->updateLeadWithQueryParams($leadFromCookie, $query);
                return $this->createPixelResponse($this->request);
            }
        } catch (EnforceMatchingException $e) {
            $this->logger->error(sprintf('%s: %s (%d)', self::LOG_PREFIX, $e->getMessage(), $e->getCode()));
            return $this->createPixelResponse($this->request);
        }

        // exchange cookie with ID from query-lead and end response
        if ($leadFromQuery->getId() > 0) {
            $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
            // create a device for the lead here which sets the device-tracking cookies
            $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $this->request->server->get('HTTP_USER_AGENT'));
            // write audit-log for query-lead
            $message = sprintf('Exchange lead by respond with Mautic cookie "mtc_id=%d"', $leadFromQuery->getId());
            $this->addAuditLogForLead($leadFromQuery, 'identified', ['message' => $message]);
            return $this->createPixelResponse($this->request);
        }

        // check if the unique-identifiers of the cookie-lead are empty
        $uniqueIdentifiersFromCookieLeadAreEmpty = function (Lead $lead) use ($uniqueLeadIdentifiers) {
            $result = false;
            foreach ($uniqueLeadIdentifiers as $uniqueLeadIdentifier) {
                $fieldGetterName = 'get' . $uniqueLeadIdentifier; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)
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
            return $this->createPixelResponse($this->request);
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
        $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $this->request->server->get('HTTP_USER_AGENT'));

        return $this->createPixelResponse($this->request);
    }

    /**
     * @throws OptimisticLockException
     * @throws ORMException
     */
    protected function updateLeadWithQueryParams(Lead $lead, array $query): void
    {
        $leadUpdated = false;

        foreach ($this->publiclyUpdatableFieldValues as $leadField => $value) {
            // update lead with values from query
            $fieldSetterName = 'set' . $leadField; // the CustomFieldEntityTrait handles the correct method-name to get/set the field (also when using underscores)
            $lead->$fieldSetterName($query[$leadField]);
            $leadUpdated = true;
        }

        if ($leadUpdated) {
            $this->leadRepository->saveEntity($lead);
        }
    }

    /**
     * Create audit-log for lead
     * @param Lead $lead
     * @param string $action should be 'identified', 'create' or 'update'
     * @param array $details will be serialized and shown in audit-log toggle e.g. if action is 'update'
     * @return void
     */
    protected function addAuditLogForLead(Lead $lead, string $action, array $details = [])
    {
        $log = [
            'bundle' => 'lead', // must be set to "lead" otherwise it's not shown in lead view (tab "Audit log")
            'object' => 'lead',
            'objectId' => $lead->getId(),
            'action' => $action,
            'details' => $details,
            'ipAddress' => $this->ipLookupHelper->getIpAddressFromRequest(),
        ];
        $this->auditLogModel->writeToLog($log);
    }

    /**
     * This method creates the return value for the action response
     * @param Request $request
     * @return Response
     */
    protected function createPixelResponse(Request $request): Response {
        return TrackingPixelHelper::getResponse($this->request);
    }
}
