<?php

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Model\PageModel;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    protected EntityManager $entityManager;
    protected PageModel $pageModel;
    protected CookieHelper $cookieHelper;

    public function __construct(
        EntityManager $entityManager,
        PageModel $pageModel,
        CookieHelper $cookieHelper
    ) {
        $this->entityManager = $entityManager;
        $this->pageModel = $pageModel;
        $this->cookieHelper = $cookieHelper;
    }

    /**
     * this works like the original, but using the custom page-model to allow modifications
     * @return Response
     * @throws \Exception
     */
    /*public function identityControlImageAction(): Response
    {
        $this->pageModel->customPageHit(null, $this->request);
        return TrackingPixelHelper::getResponse($this->request);
    }*/

    /**
     * @return Response
     * @throws \Exception
     */
    public function identityControlImageAction(): Response
    {
        $get  = $this->request->query->all();
        $post = $this->request->request->all();

        $query = \array_merge($get, $post);

        if (empty($query)) {
            return new Response();
        }

        // check if at least one query param-field unique and public updatable
        /** @var \Mautic\LeadBundle\Model\LeadModel $model */
        $leadModel = $this->getModel('lead');
        [$leadFromQuery, $publiclyUpdatableFieldValues] = $leadModel->checkForDuplicateContact($query, null, true, true);

        if (empty($publiclyUpdatableFieldValues)) {
            return new Response();
        }

        // check if Mautic cookie with lead-id exists
        $leadIdFromCookie = $this->request->cookies->get('mtc_id', null);
        if (null === $leadIdFromCookie) {
            // create lead with values from query param and set cookie
            $this->entityManager->persist($leadFromQuery);
            $this->entityManager->flush();
            $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
            return TrackingPixelHelper::getResponse($this->request);
        }

        // get lead-id from cookie to compare against query param
        $leadFromCookie = $leadModel->getEntity($leadIdFromCookie);
        if (empty($leadFromCookie)) {
            return new Response();
        }

        $leadFieldsEqual = true;
        foreach ($publiclyUpdatableFieldValues as $leadField => $value) {
            $fieldGetterName = 'get' . ucfirst($leadField); // @todo: probably improvement for fields with underscore needed

            if ($leadFromCookie->$fieldGetterName() !== $leadFromQuery->$fieldGetterName()) {
                $leadFieldsEqual = false;
                // lead identified by param is different from lead identified by cookie!
                // exchange cookie by lead-id from param
                break;
            }
        }

        // @todo: WIP continue

        if ($leadFieldsEqual) {
            // update $leadFromCookie with values from param if public-updatable
        }

        return TrackingPixelHelper::getResponse($this->request);
    }
}
