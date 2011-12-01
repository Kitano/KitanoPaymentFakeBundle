<?php

namespace Kitano\Bundle\PaymentFakeBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function paymentRequestAction()
    {
        $request = clone $this->getRequest();
        $mode = strtolower($request->request->get('mode', ''));
        switch ($mode) {
            case 'accept':
                $request->request->set('code', 1);
                $redirectUrl = $request->request->get('return_url_ok');
                break;

            case 'refuse':
                $request->request->set('code', 0);
                $redirectUrl = $request->request->get('return_url_err');
                break;

            default:
                $request->request->set('code', -1);
                $redirectUrl = $request->request->get('return_url');
        }
        $this->getPaymentSystem()->handlePaymentNotification($request);

        return $this->redirect($redirectUrl);
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

