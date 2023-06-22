<?php

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMException;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\ClickthroughHelper;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use Mautic\LeadBundle\DataObject\LeadManipulator;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Exception\ImportFailedException;
use Mautic\LeadBundle\Helper\IdentifyCompanyHelper;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\LeadBundle\Tracker\DeviceTracker;
use Mautic\PageBundle\Entity\Hit;
use Mautic\PageBundle\Entity\HitRepository;
use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Entity\Redirect;
use Mautic\PageBundle\Event\PageHitEvent;
use Mautic\PageBundle\Model\RedirectModel;
use Mautic\PageBundle\Model\TrackableModel;
use Mautic\PageBundle\PageEvents;
use Mautic\QueueBundle\Queue\QueueName;
use Mautic\QueueBundle\Queue\QueueService;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Helper\ContactRequestHelper;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Model\PageModel;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    protected EntityManager $entityManager;
    protected PageModel $pageModel;
    protected RedirectModel $pageRedirectModel;
    protected TrackableModel $pageTrackableModel;
    protected QueueService $queueService;
    protected ContactTracker $contactTracker;
    protected DeviceTracker $deviceTracker;
    protected IpLookupHelper $ipLookupHelper;
    protected CookieHelper $cookieHelper;
    protected DateTimeHelper $dateTimeHelper;
    protected Logger $logger;
    protected LeadModel $leadModel;

    public function __construct(
        EntityManager $entityManager,
        PageModel $pageModel,
        LeadModel $leadModel,
        RedirectModel $pageRedirectModel,
        TrackableModel $pageTrackableModel,
        QueueService $queueService,
        ContactTracker $contactTracker,
        DeviceTracker $deviceTracker,
        IpLookupHelper $ipLookupHelper,
        CookieHelper $cookieHelper,
        Logger $logger
    ) {
        $this->entityManager = $entityManager;
        $this->pageModel = $pageModel;
        $this->leadModel = $leadModel;
        $this->pageRedirectModel = $pageRedirectModel;
        $this->pageTrackableModel = $pageTrackableModel;
        $this->queueService = $queueService;
        $this->contactTracker = $contactTracker;
        $this->deviceTracker = $deviceTracker;
        $this->ipLookupHelper = $ipLookupHelper;
        $this->cookieHelper = $cookieHelper;
        $this->logger = $logger;

        $this->dateTimeHelper = new DateTimeHelper();
    }

    /**
     * @return Response
     * @throws \Exception
     */
    public function identityControlImageAction(): Response
    {
        /** @var PageModel $pageModel */
        //$pageModel = $this->getModel('page');
        //$pageModel->hitPage(null, $this->request);
        $this->pageModel->customPageHit(null, $this->request);

        return TrackingPixelHelper::getResponse($this->request);
    }

    /**
     * Info: this method is taken from \Mautic\PageBundle\Model\PageModel and modified
     * @throws ORMException
     * @throws ImportFailedException
     * @throws \Exception
     */
    /*private function customPageHit(PageModel $pageModel, $page, Request $request, $code = '200', Lead $lead = null, $query = []): void
    {
        // @todo: implement custom logic
        // Don't skew results with user hits
        //if (!$this->security->isAnonymous()) {
        //    return;
        //}

        // Process the query
        if (empty($query) || !is_array($query)) {
            $query = $pageModel->getHitQuery($request, $page);
        }

        // Get lead if required
        if (null == $lead) {
            $lead = $this->getContactFromRequest($this->leadModel, $query); // this creates a new lead + cookie if nothing is given

            // @var CompanyModel $companyModel
            $companyModel = $this->getModel('lead.company');

            // company
            [$company, $leadAdded, $companyEntity] = IdentifyCompanyHelper::identifyLeadsCompany($query, $lead, $companyModel);
            if ($leadAdded) {
                $lead->addCompanyChangeLogEntry('form', 'Identify Company', 'Lead added to the company, '.$company['companyname'], $company['id']);
            } elseif ($companyEntity instanceof Company) {
                $companyModel->setFieldValues($companyEntity, $query);
                $companyModel->saveEntity($companyEntity);
            }

            if (!empty($company) and $companyEntity instanceof Company) {
                // Save after the lead in for new leads created through the API and maybe other places
                $companyModel->addLeadToCompany($companyEntity, $lead);
                $this->leadModel->setPrimaryCompany($companyEntity->getId(), $lead->getId());
            }
        }

        if (!$lead || !$lead->getId()) {
            // Lead came from a non-trackable IP so ignore
            return;
        }

        $hit = new Hit();
        $hit->setDateHit(new \Datetime());
        $hit->setIpAddress($this->ipLookupHelper->getIpAddress());

        // Set info from request
        $hit->setQuery($query);
        $hit->setCode($code);

        $trackedDevice = $this->deviceTracker->createDeviceFromUserAgent($lead, $request->server->get('HTTP_USER_AGENT'));

        $hit->setTrackingId($trackedDevice->getTrackingId());
        $hit->setDeviceStat($trackedDevice);

        // Wrap in a try/catch to prevent deadlock errors on busy servers
        try {
            $this->entityManager->persist($hit);
            $this->entityManager->flush();
        } catch (\Exception $exception) {
            if (MAUTIC_ENV === 'dev') {
                throw $exception;
            } else {
                $this->logger->addError(
                    $exception->getMessage(),
                    ['exception' => $exception]
                );
            }
        }

        //save hit to the cookie to use to update the exit time
        if ($hit) {
            $this->cookieHelper->setCookie('mautic_referer_id', $hit->getId() ?: null);
        }

        if ($this->queueService->isQueueEnabled()) {
            $msg = [
                'hitId'         => $hit->getId(),
                'pageId'        => $page ? $page->getId() : null,
                'request'       => $request,
                'leadId'        => $lead ? $lead->getId() : null,
                'isNew'         => $this->deviceTracker->wasDeviceChanged(),
                'isRedirect'    => ($page instanceof Redirect),
            ];
            $this->queueService->publishToQueue(QueueName::PAGE_HIT, $msg);
        } else {
            $this->customProcessPageHit($hit, $page, $request, $lead, $this->deviceTracker->wasDeviceChanged());
        }
    }*/


    /**
     * Info: this method is taken from \Mautic\PageBundle\Model\PageModel and modified
     * @param Page|Redirect $page
     * @param bool          $trackingNewlyGenerated
     * @param bool          $activeRequest
     * @throws \Exception
     */
    /*public function customProcessPageHit(Hit $hit, $page, Request $request, Lead $lead, $trackingNewlyGenerated, $activeRequest = true)
    {
        // Store Page/Redirect association
        if ($page) {
            if ($page instanceof Page) {
                $hit->setPage($page);
            } else {
                $hit->setRedirect($page);
            }
        }

        // Check for any clickthrough info
        $clickthrough = $this->generateClickThrough($hit);
        if (!empty($clickthrough)) {
            if (!empty($clickthrough['channel'])) {
                if (1 === count($clickthrough['channel'])) {
                    $channelId = reset($clickthrough['channel']);
                    $channel   = key($clickthrough['channel']);
                } else {
                    $channel   = $clickthrough['channel'][0];
                    $channelId = (int) $clickthrough['channel'][1];
                }
                $hit->setSource($channel);
                $hit->setSourceId($channelId);
            } elseif (!empty($clickthrough['source'])) {
                $hit->setSource($clickthrough['source'][0]);
                $hit->setSourceId($clickthrough['source'][1]);
            }

            if (!empty($clickthrough['email'])) {
                $emailRepo = $this->entityManager->getRepository('MauticEmailBundle:Email');
                if ($emailEntity = $emailRepo->getEntity($clickthrough['email'])) {
                    $hit->setEmail($emailEntity);
                }
            }
        }

        $query = $hit->getQuery() ? $hit->getQuery() : [];

        if (isset($query['timezone_offset']) && !$lead->getTimezone()) {
            // timezone_offset holds timezone offset in minutes. Multiply by 60 to get seconds.
            // Multiply by -1 because Firgerprint2 seems to have it the other way around.
            $timezone = (-1 * $query['timezone_offset'] * 60);
            $lead->setTimezone($this->dateTimeHelper->guessTimezoneFromOffset($timezone));
        }

        $query = $this->cleanQuery($query);

        if (isset($query['page_referrer'])) {
            $hit->setReferer($query['page_referrer']);
        }
        if (isset($query['page_language'])) {
            $hit->setPageLanguage($query['page_language']);
        }
        if (isset($query['page_title'])) {
            // Transliterate page titles.
            if ($this->coreParametersHelper->get('transliterate_page_title')) {
                $safeTitle = InputHelper::transliterate($query['page_title']);
                $hit->setUrlTitle($safeTitle);
                $query['page_title'] = $safeTitle;
            } else {
                $hit->setUrlTitle($query['page_title']);
            }
        }

        $hit->setQuery($query);
        $hit->setUrl((isset($query['page_url'])) ? $query['page_url'] : $request->getRequestUri());

        // Add entry to contact log table
        $this->setLeadManipulator($page, $hit, $lead);

        // Store tracking ID
        $hit->setLead($lead);

        if (!$activeRequest) {
            // Queue is consuming this hit outside of the lead's active request so this must be set in order for listeners to know who the request belongs to
            $this->contactTracker->setSystemContact($lead);
        }
        $trackingId = $hit->getTrackingId();
        if (!$trackingNewlyGenerated) {
            $lastHit = $request->cookies->get('mautic_referer_id');
            if (!empty($lastHit)) {
                //this is not a new session so update the last hit if applicable with the date/time the user left
                $this->getHitRepository()->updateHitDateLeft($lastHit);
            }
        }

        // Check if this is a unique page hit
        $isUnique = $this->getHitRepository()->isUniquePageHit($page, $trackingId, $lead);

        if ($page instanceof Page) {
            $hit->setPageLanguage($page->getLanguage());

            $isVariant = ($isUnique) ? $page->getVariantStartDate() : false;

            try {
                $this->getRepository()->upHitCount($page->getId(), 1, $isUnique, !empty($isVariant));
            } catch (\Exception $exception) {
                $this->logger->addError(
                    $exception->getMessage(),
                    ['exception' => $exception]
                );
            }
        } elseif ($page instanceof Redirect) {
            try {
                $this->pageRedirectModel->getRepository()->upHitCount($page->getId(), 1, $isUnique);

                // If this is a trackable, up the trackable counts as well
                if ($hit->getSource() && $hit->getSourceId()) {
                    $this->pageTrackableModel->getRepository()->upHitCount(
                        $page->getId(),
                        $hit->getSource(),
                        $hit->getSourceId(),
                        1,
                        $isUnique
                    );
                }
            } catch (\Exception $exception) {
                if (MAUTIC_ENV === 'dev') {
                    throw $exception;
                } else {
                    $this->logger->addError(
                        $exception->getMessage(),
                        ['exception' => $exception]
                    );
                }
            }
        }

        //glean info from the IP address
        $ipAddress = $hit->getIpAddress();
        if ($details = $ipAddress->getIpDetails()) {
            $hit->setCountry($details['country']);
            $hit->setRegion($details['region']);
            $hit->setCity($details['city']);
            $hit->setIsp($details['isp']);
            $hit->setOrganization($details['organization']);
        }

        if (!$hit->getReferer()) {
            $hit->setReferer($request->server->get('HTTP_REFERER'));
        }

        $hit->setUserAgent($request->server->get('HTTP_USER_AGENT'));
        $hit->setRemoteHost($request->server->get('REMOTE_HOST'));

        $this->setUtmTags($hit, $lead);

        //get a list of the languages the user prefers
        $browserLanguages = $request->server->get('HTTP_ACCEPT_LANGUAGE');
        if (!empty($browserLanguages)) {
            $languages = explode(',', $browserLanguages);
            foreach ($languages as $k => $l) {
                if (($pos = strpos(';q=', $l)) !== false) {
                    //remove weights
                    $languages[$k] = substr($l, 0, $pos);
                }
            }
            $hit->setBrowserLanguages($languages);
        }

        // Wrap in a try/catch to prevent deadlock errors on busy servers
        try {
            $this->entityManager->persist($hit);
            $this->entityManager->flush();
        } catch (\Exception $exception) {
            if (MAUTIC_ENV === 'dev') {
                throw $exception;
            } else {
                $this->logger->addError(
                    $exception->getMessage(),
                    ['exception' => $exception]
                );
            }
        }

        if ($this->dispatcher->hasListeners(PageEvents::PAGE_ON_HIT)) {
            $event = new PageHitEvent($hit, $request, $hit->getCode(), $clickthrough, $isUnique);
            $this->dispatcher->dispatch(PageEvents::PAGE_ON_HIT, $event);
        }
    }*/

    /**
     * Info: this method is taken from \Mautic\PageBundle\Model\PageModel and modified using ClickthroughHelper directly
     * @return array|mixed
     */
    /*protected function generateClickThrough(Hit $hit)
    {
        $query = $hit->getQuery();

        // Check for any clickthrough info
        $clickthrough = [];
        if (!empty($query['ct'])) {
            $clickthrough = $query['ct'];
            if (!is_array($clickthrough)) {
                $clickthrough = ClickthroughHelper::decodeArrayFromUrl($clickthrough);
            }
        }

        return $clickthrough;
    }*/

    /**
     * Info: this method is taken from \Mautic\PageBundle\Model\PageModel
     * @param array $query
     * @return array
     */
    /*protected function cleanQuery(array $query): array
    {
        foreach ($query as $key => $value) {
            if (filter_var($value, FILTER_VALIDATE_URL)) {
                $query[$key] = InputHelper::url($value);
            } else {
                $query[$key] = InputHelper::clean($value);
            }
        }

        return $query;
    }*/

    /**
     * Info: this method is taken from \Mautic\PageBundle\Model\PageModel
     * @param $page
     * @param Hit $hit
     * @param Lead $lead
     */
    /*private function setLeadManipulator($page, Hit $hit, Lead $lead)
    {
        // Only save the lead and dispatch events if needed
        $source   = 'hit';
        $sourceId = $hit->getId();
        if ($page) {
            $source   = $page instanceof Page ? 'page' : 'redirect';
            $sourceId = $page->getId();
        }

        $lead->setManipulator(
            new LeadManipulator(
                'page',
                $source,
                $sourceId,
                $hit->getUrl()
            )
        );

        $this->leadModel->saveEntity($lead);
    }*/

    /**
     * Info: this method is taken from \Mautic\LeadBundle\Model\LeadModel and modified using custom ContactRequestHelper
     * @param LeadModel $leadModel
     * @param array $queryFields
     * @return mixed
     * @throws ImportFailedException
     */
    /*protected function getContactFromRequest(LeadModel $leadModel, array $queryFields = [])
    {
        // @todo Instantiate here until we can remove circular dependency on LeadModel in order to make it a service
        $requestStack = new RequestStack();
        $requestStack->push($this->request);
        $contactRequestHelper = new ContactRequestHelper(
            $leadModel,
            $this->contactTracker,
            $this->coreParametersHelper,
            $this->ipLookupHelper,
            $requestStack,
            $this->logger,
            $this->dispatcher
        );

        return $contactRequestHelper->getContactFromQuery($queryFields);
    }*/

    /**
     * @return HitRepository
     */
    /*protected function getHitRepository(): HitRepository
    {
        return $this->entityManager->getRepository('MauticPageBundle:Hit');
    }*/
}
