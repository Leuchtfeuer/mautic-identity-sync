<?php

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\CookieHelper;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\LeuchtfeuerIdentitySyncBundle\Model\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    public const PARAM_IDENTIFIER_FIELDS = ['id', 'email'];

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
            return $this->createPixelResponse($this->request);
        }

        // check if at least one query param-field unique and public updatable
        /** @var LeadModel $model */
        $leadModel = $this->getModel('lead');

        /** @var Lead $leadFromQuery */
        [$leadFromQuery, $publiclyUpdatableFieldValues] = $leadModel->checkForDuplicateContact($query, null, true, true);

        // check if cookie-lead exists
        $leadFromCookie = $this->request->cookies->get('mtc_id', null);

        if ($leadFromCookie !== null) {
            /** @var Lead $leadFromCookie */
            $leadFromCookie = $leadModel->getEntity($leadFromCookie);
        }

        if (empty($leadFromCookie)) {
            // create lead with values from query param and set cookie
            $this->entityManager->persist($leadFromQuery);
            $this->entityManager->flush();
            $this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
            return $this->createPixelResponse($this->request);
        }

        // check if the param-lead and cookie-lead are identical
        $leadUpdated = false;
        foreach ($publiclyUpdatableFieldValues as $leadField => $value) {
            $fieldGetterName = 'get' . ucfirst($leadField); // @todo: probably improvement for fields with underscore needed

            // @todo: problem because e.g. for title both are null, so update on cookie-lead does not happen
            if ($leadFromCookie->$fieldGetterName() !== $leadFromQuery->$fieldGetterName()) {
                // param-lead is different from cookie-lead! check if the field from cookie-lead is empty
                if (empty($leadFromCookie->$fieldGetterName())) {
                    // update cookie-lead with values from param-lead
                    $fieldSetterName = 'set' . ucfirst($leadField); // @todo: probably improvement for fields with underscore needed
                    $leadFromCookie->$fieldSetterName($leadFromQuery->$fieldGetterName());
                    $leadUpdated = true;
                }
            }
        }

        if ($leadUpdated) {
            // update cookie-lead with values from param-lead if public-updatable
            $this->entityManager->persist($leadFromCookie);
            $this->entityManager->flush();

            // exchange cookie with ID from param-lead
            //$this->cookieHelper->setCookie('mtc_id', $leadFromQuery->getId(), null);
        }

        return $this->createPixelResponse($this->request);
    }

    protected function createPixelResponse(Request $request): Response {
        return TrackingPixelHelper::getResponse($this->request);
    }
}
