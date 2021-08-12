<?php
namespace Combodo\StripeV3\Action\Api;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\RenderTemplate;
use Combodo\StripeV3\Keys;
use Combodo\StripeV3\Request\Api\ObtainToken;
use Stripe\Checkout\Session;
use Stripe\Error\ApiConnection;
use Stripe\Error\Authentication;
use Stripe\Error\InvalidRequest;
use Stripe\Error\RateLimit;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * @property Keys $keys alias of $api
 * @property Keys $api
 */
class ObtainTokenAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use ApiAwareTrait {
        setApi as _setApi;
    }
    use GatewayAwareTrait;

    /**
     * @var string
     */
    protected $templateName;

    /**
     * @deprecated BC will be removed in 2.x. Use $this->api
     *
     * @var Keys
     */
    protected $keys;

    /**
     * @param string $templateName
     */
    public function __construct($templateName)
    {
        $this->templateName = $templateName;

        $this->apiClass = Keys::class;
    }

    /**
     * {@inheritDoc}
     */
    public function setApi($api)
    {
        $this->_setApi($api);

        // Has more meaning than api since it is just the api keys!
        $this->keys = $this->api;
    }

    /**
     * {@inheritDoc}
     */
    public function execute($request)
    {
        /** @var $request ObtainToken */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
            $session = $this->obtainSession($request, $model);
            $paymentIntent = PaymentIntent::retrieve($session['payment_intent']);
            $model['session_id'] = $session->id;
            $model['session_date'] = time();
            $model['client_secret'] = $paymentIntent->client_secret;
            $model['setup_intent'] = $session->setup_intent;
        $this->gateway->execute($renderTemplate = new RenderTemplate($this->templateName, array(
            'publishable_key'   => $this->keys->getPublishableKey(),
            "session_id"        => $model['session_id'],
            "setup_intent"        => $model['setup_intent'],
            "client_secret"        => $model['client_secret'],
            'model'             => $model,
        )));


        throw new HttpResponse($renderTemplate->getResult());
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof ObtainToken &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }

    /**
     * @param             $request
     * @param ArrayObject $model
     *
     * @return Session
     */
    private function obtainSession(ObtainToken $request, ArrayObject $model): Session
    {
        Stripe::setApiKey($this->keys->getSecretKey());

        $rawUrl = $request->getToken()->getTargetUrl();
        $separator = ObtainTokenAction::computeSeparator($rawUrl);
        $successUrl = "{$rawUrl}{$separator}checkout_status=completed";

        $rawUrl = $request->getToken()->getAfterUrl();
        $separator = ObtainTokenAction::computeSeparator($rawUrl);
        $cancelUrl  = "{$rawUrl}{$separator}checkout_status=canceled";
        // IF ANY NOT FREE ITEMS
        if($model['amount'] > 0){
            // SESSION PARAMS FOR PAYMENT + FUTUR PAYMENTS
            $params = [
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'payment_method_types' => ['card'],
                'submit_type' => Session::SUBMIT_TYPE_PAY,
                'line_items' => $model['line_items'],
                'payment_intent_data' => [
                    'setup_future_usage' => 'off_session',
                    'metadata' => $model['metadata'] ?? ['payment_id' => $model['id']],
                ],
                'client_reference_id' => $request->getToken()->getHash(),
            ];

            if (isset($model['payment_intent_data'])) {
                $params['payment_intent_data'] = array_merge($params['payment_intent_data'], $model['payment_intent_data']);
            }

        } else {
            // SESSION PARAMS FOR FUTUR PAYMENTS
            $params = [
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'mode' => 'setup',
                'line_items' => $model['line_items'],
                'payment_method_types' => ['card'],
                'setup_intent_data' => [
                    'description' => 'Votre carte de crédit sera débité à la prochaine échéance si vous n\'annuler pas votre essai',
                    'metadata' => $model['metadata'] ?? ['payment_id' => $model['id']],
                ],
                'client_reference_id' => $request->getToken()->getHash(),
            ];
        }

        if (isset($model['customer_email'])) {
            $params['customer_email'] = $model['customer_email'];
        }

        try {
            $session = Session::create($params);
        }
        catch (RateLimit $e) {
                // Too many requests made to the API too quickly
            } catch (InvalidRequest $e) {
                // Invalid parameters were supplied to Stripe's API
            } catch (Authentication $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
            } catch (ApiConnection $e) {
                // Network communication with Stripe failed
            } catch (\Exception $e) {
                // Something else happened, completely unrelated to Stripe
            }
        return $session;
    }

    /**
     * @param string $rawUrl
     *
     * @return string
     */
    private static function computeSeparator(string $rawUrl): string
    {
        $query = parse_url($rawUrl, PHP_URL_QUERY);
        if ('' != $query) {
            $separator = '&';
        } else {
            $separator = '?';
        }

        return $separator;
    }
}
