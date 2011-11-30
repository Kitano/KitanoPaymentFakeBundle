<?php

namespace Kitano\Bundle\PaymentFakeBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function paymentRequestAction()
    {
        $postData = $this->getRequest()->request;
        $request = new Request(array(), array(
            'reference' => $postData->get('reference', null),
        ));

        $this->getPaymentSystem()->handlePaymentNotification($request);

        return $this->redirect($postData->get('url_retour_ok'));
    }

    public function captureRequestAction()
    {
        $postData = $this->getRequest()->request;

        return new Response('code=1');
    }

    /**
     * @return \Kitano\Bundle\PaymentBundle\PaymentSystem\CreditCardInterface
     */
    public function getPaymentSystem()
    {
        return $this->get('kitano_payment_fake.payment_system.fake');
    }
}

