<?php
namespace Combodo\StripeV3\Action;

use Combodo\StripeV3\Keys;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;
use Payum\Core\Security\SensitiveValue;
use Stripe\Checkout\Session;
use Stripe\SetupIntent;
use Stripe\Stripe;

class ConvertPaymentAction implements ActionInterface
{
    use GatewayAwareTrait;
    /**
     * {@inheritDoc}
     *
     * @param Convert $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $details["amount"] = $payment->getTotalAmount();
        $details["currency"] = $payment->getCurrencyCode();
        $details["description"] = $payment->getDescription();

        $request->setResult((array) $details);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() == 'array'
        ;
    }
}
