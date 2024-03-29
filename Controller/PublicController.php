<?php

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\OptimisticLockException;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\LeadBundle\Tracker\DeviceTracker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    protected EntityManager $entityManager;
    protected ContactTracker $contactTracker;
    protected DeviceTracker $deviceTracker;
    protected CookieHelper $cookieHelper;
    protected LeadRepository $leadRepository;
    protected array $publiclyUpdatableFieldValues = [];

    public function __construct(
        EntityManager $entityManager,
        ContactTracker $contactTracker,
        DeviceTracker $deviceTracker,
        CookieHelper $cookieHelper
    ) {
        $this->entityManager = $entityManager;
        $this->contactTracker = $contactTracker;
        $this->deviceTracker = $deviceTracker;
        $this->cookieHelper = $cookieHelper;
    }

    /**
     * @return Response
     * @throws \Exception
     */
    public function identityControlImageAction(): Response
    {
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
        $uniqueLeadIdentifiers = $this->getUniqueIdentifierFieldNames();

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
            $lead = $this->contactTracker->getContact(); // this call does not set the given query-params, we've to manually add them
            $this->updateLeadWithQueryParams($lead, $query);
            return $this->createPixelResponse($this->request);
        }

        // check if unique field-values matching the cookie-lead
        $uniqueIdentifiersFromQueryLeadMatchingLead = function (Lead $lead) use ($leadFromQuery, $uniqueLeadIdentifiers, $query) {
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
        };
        if ($uniqueIdentifiersFromQueryLeadMatchingLead($leadFromCookie)) {
            // we call ContactTracker->getContact() here to update the last-activity
            $this->contactTracker->setTrackedContact($leadFromCookie);
            $this->contactTracker->getContact();

            // update publicly-updatable fields of cookie-lead with query param values and end response
            $this->updateLeadWithQueryParams($leadFromCookie, $query);
            return $this->createPixelResponse($this->request);
        }

        // exchange cookie with ID from query-lead and end response
        if ($leadFromQuery->getId() > 0) {
            $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
            // create a device for the lead here which sets the device-tracking cookies
            $this->deviceTracker->createDeviceFromUserAgent($leadFromQuery, $this->request->server->get('HTTP_USER_AGENT'));
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

    protected function createPixelResponse(Request $request): Response {
        return TrackingPixelHelper::getResponse($this->request);
    }

    /**
     * it's not easy to extend the LeadFieldRepository, so we use this controller method instead
     *
     * @param string $object
     * @return mixed[]
     * @throws \Doctrine\DBAL\Exception
     */
    protected function getUniqueIdentifierFieldNames(string $object = 'lead'): ?array
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();

        $result = $qb->select('f.alias, f.is_unique_identifer as is_unique, f.type, f.object')
            ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'f')
            ->where($qb->expr()->and(
                $qb->expr()->eq('object', ':object'),
                $qb->expr()->eq('is_unique_identifer', 1),
            ))
            ->setParameter('object', $object)
            ->orderBy('f.field_order', 'ASC')
            ->execute()->fetchAll();

        if (empty($result)) {
            return null;
        }

        $fieldNames = [];
        foreach ($result as $item) {
            $fieldNames[] = $item['alias'];
        }

        return $fieldNames;
    }
}
