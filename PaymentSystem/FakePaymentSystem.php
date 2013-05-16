<?php

namespace Kitano\PaymentFakeBundle\PaymentSystem;

use Kitano\PaymentBundle\PaymentSystem\CreditCardInterface;
use Kitano\PaymentBundle\Entity\Transaction;
use Kitano\PaymentBundle\Entity\AuthorizationTransaction;
use Kitano\PaymentBundle\Entity\CaptureTransaction;
use Kitano\PaymentBundle\KitanoPaymentEvents;
use Kitano\PaymentBundle\Event\PaymentEvent;
use Kitano\PaymentBundle\Event\PaymentCaptureEvent;
use Kitano\PaymentBundle\Repository\TransactionRepositoryInterface;
use Kitano\PaymentBundle\PaymentSystem\HandlePaymentResponse;

use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Templating\EngineInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class FakePaymentSystem implements CreditCardInterface
{
    /* @var EventDispatcherInterface */
    protected $dispatcher;

    /* @var EngineInterface */
    protected $templating;

    /* @var RouterInterface */
    protected $router;

    /* @var TransactionRepositoryInterface */
    protected $transactionRepository;

    /* @var string */
    protected $notificationUrl = null;
    /* @var string */
    protected $externalBackToShopUrl = null;
    /* @var string */
    protected $internalBackToShopUrl = null;


    public function __construct(
        TransactionRepositoryInterface $transactionRepository,
        EventDispatcherInterface $dispatcher,
        EngineInterface $templating,
        RouterInterface $router,
        LoggerInterface $logger,
        $notificationUrl,
        $internalBackToShopUrl,
        $externalBackToShopUrl
    )
    {
        $this->transactionRepository = $transactionRepository;
        $this->dispatcher = $dispatcher;
        $this->templating = $templating;
        $this->router = $router;
        $this->logger = $logger;
        if (!$notificationUrl) {
            $notificationUrl = $router->generate("kitano_payment_payment_notification");
        }
        $this->notificationUrl = $notificationUrl;
        $this->internalBackToShopUrl = $internalBackToShopUrl;
        $this->externalBackToShopUrl = $externalBackToShopUrl;
    }

    public function authorizeAndCapture(Transaction $transaction)
    {
        // Nothing to do
    }

    /**
     * {@inheritDoc}
     */
    public function renderLinkToPayment(Transaction $transaction)
    {
        return $this->templating->render('KitanoPaymentFakeBundle:PaymentSystem:link-to-payment.html.twig', array(
            'date'    => $this->formatDate($transaction->getStateDate()),
            'orderId' => $transaction->getOrderId(),
            'transactionId' => $transaction->getId(),
            'amount'  => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'notificationUrl' => $this->notificationUrl,
            'internalBackToShop' => $this->internalBackToShopUrl,
            'externalBackToShop' => $this->externalBackToShopUrl
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function handlePaymentNotification(Request $request)
    {
        $requestData = $request->request;
        $transaction = $this->transactionRepository->find($requestData->get('transactionId', null));

        switch((int) $requestData->get('code', 999)) {
            case 1:
                $transaction->setState(AuthorizationTransaction::STATE_APPROVED);
                break;

            case 0:
                $transaction->setState(AuthorizationTransaction::STATE_REFUSED);
                break;

            case -1:
                $transaction->setState(AuthorizationTransaction::STATE_SERVER_ERROR);
                break;

            default:
                $transaction->setState(AuthorizationTransaction::STATE_SERVER_ERROR);
        }

        $transaction->setSuccess(true);
        $transaction->setExtraData($requestData->all());
        $this->transactionRepository->save($transaction);

        $event = new PaymentEvent($transaction);
        $this->logger->debug("event KitanoPaymentEvents::AFTER_PAYMENT_NOTIFICATION");
        $this->dispatcher->dispatch(KitanoPaymentEvents::AFTER_PAYMENT_NOTIFICATION, $event);

        $response = new Response("OK");
        return new HandlePaymentResponse($transaction, $response);
    }

    /**
     * {@inheritDoc}
     */
    public function handleBackToShop(Request $request)
    {
        $response = new RedirectResponse($this->externalBackToShopUrl.'?transactionId='.$request->get('transactionId', null), "302");
        return new HandlePaymentResponse(null, $response);
    }

    /**
     * {@inheritDoc}
     */
    public function capture(CaptureTransaction $transaction)
    {
        // Initialize session and set URL.
        $ch = curl_init();
        $url = $this->getBaseUrl() . $this->router->generate('kitano_payment_fake_capture', array(), false);
        curl_setopt($ch, CURLOPT_URL, $url);

        // Set so curl_exec returns the result instead of outputting it.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Accept any server(peer) certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $transaction->setBaseTransaction($this->transactionRepository->findAuthorizationByOrderId($transaction->getOrderId()));
        $captureList = $this->transactionRepository->findCapturesBy(array(
            'orderId' => $transaction->getOrderId(),
            'state' => CaptureTransaction::STATE_APPROVED,
        ));

        $captureAmountCumul = 0;
        foreach($captureList as $capture) {
            $captureAmountCumul += $capture->getAmount();
        }

        $remainingAmount = (float) ($transaction->getBaseTransaction()->getAmount() - $captureAmountCumul - $transaction->getAmount());

        // Data
        $data = array(
            'amount'            => $this->formatAmount($transaction->getBaseTransaction()->getAmount(), $transaction->getCurrency()),
            'capture_amount'    => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'captured_amount'   => $this->formatAmount($captureAmountCumul, $transaction->getCurrency()), // TODO
            'remaining_amount'  => $this->formatAmount($remainingAmount, $transaction->getCurrency()),
            'orderId'           => $this->formatAmount($transaction->getTransactionId(), $transaction->getCurrency()),
        );

        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->urlify($data));

        // Get the response and close the channel.
        $response = curl_exec($ch);
        curl_close($ch);

        $paymentResponse = explode('=', $response);
        if ($paymentResponse[1] == 1) {
            $transaction->setState(CaptureTransaction::STATE_APPROVED);
        }
        else {
            $transaction->setState(CaptureTransaction::STATE_REFUSED);
        }

        $transaction->setExtraData(array('response' => $response, 'sentData' => $data));
        $this->transactionRepository->save($transaction);

        $event = new PaymentCaptureEvent($transaction);
        $this->logger->debug("event KitanoPaymentEvents::AFTER_CAPTURE");
        $this->dispatcher->dispatch(KitanoPaymentEvents::AFTER_CAPTURE, $event);
    }

    /**
     * @param array $data
     * @return string
     */
    private function urlify(array $data)
    {
        return http_build_query($data);
    }

    /**
     * @param float  $amount
     * @param string $currency
     *
     * @return string
     */
    private function formatAmount($amount, $currency)
    {
        return ((string) $amount) . $currency;
    }

    /**
     * @param \DateTime $date
     * @return string
     */
    private function formatDate(\DateTime $date)
    {
        return $date->format('d/m/Y:H:i:s');
    }

}