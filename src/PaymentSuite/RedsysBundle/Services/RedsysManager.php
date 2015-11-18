<?php

/*
 * This file is part of the PaymentSuite package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

namespace PaymentSuite\RedsysBundle\Services;

use PaymentSuite\PaymentCoreBundle\Exception\PaymentException;
use PaymentSuite\PaymentCoreBundle\Exception\PaymentOrderNotFoundException;
use PaymentSuite\PaymentCoreBundle\Services\Interfaces\PaymentBridgeInterface;
use PaymentSuite\PaymentCoreBundle\Services\PaymentEventDispatcher;
use PaymentSuite\RedsysBundle\Exception\InvalidSignatureException;
use PaymentSuite\RedsysBundle\Exception\ParameterNotReceivedException;
use PaymentSuite\RedsysBundle\RedsysMethod;
use PaymentSuite\RedsysBundle\Services\Wrapper\RedsysFormTypeWrapper;

/**
 * Redsys manager
 */
class RedsysManager
{
    /**
     * @var PaymentEventDispatcher
     *
     * Payment event dispatcher
     */
    protected $paymentEventDispatcher;

    /**
     * @var Wrapper\RedsysFormTypeWrapper
     *
     * Form Type Wrapper
     */
    protected $redsysFormTypeWrapper;

    /**
     * @var PaymentBridgeInterface
     *
     * Payment bridge interface
     */
    protected $paymentBridge;

    /**
     * @var string
     *
     * Secret key
     */
    protected $secretKey;

    /**
     * @var array
     *
     * Payment vars
     */
    private $varsPay;

    /**
     * Construct method for redsys manager
     *
     * @param PaymentEventDispatcher $paymentEventDispatcher Event dispatcher
     * @param RedsysFormTypeWrapper  $redsysFormTypeWrapper  Redsys form typ wrapper
     * @param PaymentBridgeInterface $paymentBridge          Payment Bridge
     * @param string                 $secretKey              Secret Key
     */
    public function __construct(
        PaymentEventDispatcher $paymentEventDispatcher,
        RedsysFormTypeWrapper $redsysFormTypeWrapper,
        PaymentBridgeInterface $paymentBridge,
        $secretKey
    )
    {
        $this->paymentEventDispatcher = $paymentEventDispatcher;
        $this->redsysFormTypeWrapper = $redsysFormTypeWrapper;
        $this->paymentBridge = $paymentBridge;
        $this->secretKey = $secretKey;

    }

    /**
     * Creates form view for Redsys payment
     *
     * @return \Symfony\Component\Form\FormView
     *
     * @throws PaymentOrderNotFoundException
     */
    public function processPayment()
    {
        $redsysMethod = new RedsysMethod();
        /**
         * At this point, order must be created given a cart, and placed in PaymentBridge
         *
         * So, $this->paymentBridge->getOrder() must return an object
         */
        $this
            ->paymentEventDispatcher
            ->notifyPaymentOrderLoad(
                $this->paymentBridge,
                $redsysMethod
            );

        /**
         * Order Not found Exception must be thrown just here
         */
        if (!$this->paymentBridge->getOrder()) {

            throw new PaymentOrderNotFoundException();
            throw new PaymentOrderNotFoundException();
        }

        /**
         * Order exists right here
         */
        $this
            ->paymentEventDispatcher
            ->notifyPaymentOrderCreated(
                $this->paymentBridge,
                $redsysMethod
            );

        $formView = $this
            ->redsysFormTypeWrapper
            ->buildForm();

        return $formView;
    }

    /**
     * Processes the POST request sent by Redsys
     *
     * @param array $parameters Array with response parameters
     *
     * @return RedsysManager Self object
     *
     * @throws InvalidSignatureException
     * @throws ParameterNotReceivedException
     * @throws PaymentException
     */
    public function processResult(array $parameters)
    {
        //Check we receive all needed parameters
        $this->checkResultParameters($parameters);

        $redsysMethod =  new RedsysMethod();

        $dsSignature            = $parameters['Ds_Signature'];
        $dsParams               = $parameters['Ds_MerchantParameters'];
        $dsVersion              = $parameters['Ds_SignatureVersion'];

        $paramsDecoded = base64_decode(strtr($dsParams, '-_', '+/'));
        $this->varsPay = json_decode($paramsDecoded, true);


        $dsResponse            = $this->varsPay['Ds_Response'];
        $dsAmount              = $this->varsPay['Ds_Amount'];
        $dsOrder               = $this->varsPay['Ds_Order'];
        $dsMerchantCode        = $this->varsPay['Ds_MerchantCode'];
        $dsCurrency            = $this->varsPay['Ds_Currency'];
        $dsDate                 = $this->varsPay['Ds_Date'];
        $dsHour                 = $this->varsPay['Ds_Hour'];
        $dsSecurePayment        = $this->varsPay['Ds_SecurePayment'];
        $dsCardCountry          = $this->varsPay['Ds_Card_Country'];
        $dsAuthorisationCode    = $this->varsPay['Ds_AuthorisationCode'];
        $dsConsumerLanguage     = $this->varsPay['Ds_ConsumerLanguage'];
        $dsCardType             = (array_key_exists('Ds_Card_Type', $this->varsPay) ? $this->varsPay['Ds_Card_Type'] : '');
        $dsMerchantData         = (array_key_exists('Ds_MerchantData', $this->varsPay) ? $this->varsPay['Ds_MerchantData'] : '');


        if ($dsSignature != $this->expectedSignature($dsParams))
        {
            throw new InvalidSignatureException();
        }

        /**
         * Adding transaction information to PaymentMethod
         *
         * This information is only available in PaymentOrderSuccess event
         */
        $redsysMethod
            ->setDsResponse($dsResponse)
            ->setDsAuthorisationCode($dsAuthorisationCode)
            ->setDsCardCountry($dsCardCountry)
            ->setDsCardType($dsCardType)
            ->setDsConsumerLanguage($dsConsumerLanguage)
            ->setDsDate($dsDate)
            ->setDsHour($dsHour)
            ->setDsSecurePayment($dsSecurePayment)
            ->setDsOrder($dsOrder);

        /**
         * Payment paid done
         *
         * Paid process has ended ( No matters result )
         */
        $this
            ->paymentEventDispatcher
            ->notifyPaymentOrderDone(
                $this->paymentBridge,
                $redsysMethod
            );

        /**
         * when a transaction is successful, $Ds_Response has a value between 0 and 99
         */
        if (!$this->transactionSuccessful($dsResponse)) {

            /**
             * Payment paid failed
             *
             * Paid process has ended failed
             */
            $this
                ->paymentEventDispatcher
                ->notifyPaymentOrderFail(
                    $this->paymentBridge,
                    $redsysMethod
                );

            throw new PaymentException();
        }

        /**
         * Payment paid successfully
         *
         * Paid process has ended successfully
         */
        $this
            ->paymentEventDispatcher
            ->notifyPaymentOrderSuccess(
                $this->paymentBridge,
                $redsysMethod
            );

        return $this;
    }

    /**
     * Returns true if the transaction was successful
     *
     * @param string $dsResponse Response code
     *
     * @return boolean
     */
    protected function transactionSuccessful($dsResponse)
    {
        /**
         * When a transaction is successful, $Ds_Response has a value between 0 and 99
         */
        if (intval($dsResponse)>=0 && intval($dsResponse)<=99 ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Returns the expected signature
     *
     * @param string $dsParams Params
     *
     * @return Signature string String
     */
    protected function expectedSignature($dsParams)
    {
        $key = base64_decode($this->secretKey);
        $key = $this->encrypt3DES($this->getOrderNotif(), $key);
        $signature = strtr(base64_encode(hash_hmac('sha256', $dsParams, $key, true)), '+/', '-_');

        return $signature;
    }

    /**
     * Checks that all the required parameters are received
     *
     * @param array $parameters Parameters
     *
     * @throws \PaymentSuite\RedsysBundle\Exception\ParameterNotReceivedException
     */
    protected function checkResultParameters(array $parameters)
    {
        $list = array(
            'Ds_SignatureVersion',
            'Ds_MerchantParameters',
            'Ds_Signature'
        );
        foreach ($list as $item) {
            if (!isset($parameters[$item])) {
                throw new ParameterNotReceivedException($item);
            }
        }
    }

    protected function encrypt3DES($message, $key){
        $bytes = array(0,0,0,0,0,0,0,0);
        $iv = implode(array_map("chr", $bytes));
        $ciphertext = mcrypt_encrypt(MCRYPT_3DES, $key, $message, MCRYPT_MODE_CBC, $iv);

        return $ciphertext;
    }

    protected function getOrderNotif(){
        $order = "";
        if(empty($this->varsPay['Ds_Order'])){
            $order = $this->varsPay['DS_ORDER'];
        } else {
            $order = $this->varsPay['Ds_Order'];
        }
        return $order;
    }

}
