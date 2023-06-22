<?php

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Controller;

use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Helper\TrackingPixelHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Model\PageModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicController extends CommonFormController
{
    /**
     * @return Response
     * @throws \Exception
     */
    public function identityControlImageAction(): Response
    {
        /** @var PageModel $model */
        $model = $this->getModel('page');
        $this->customPageHit($model, null, $this->request);

        return TrackingPixelHelper::getResponse($this->request);
    }

    private function customPageHit(PageModel $model, $page, Request $request, $code = '200', Lead $lead = null, $query = []): void
    {
        // @todo: implement custom logic
    }
}
