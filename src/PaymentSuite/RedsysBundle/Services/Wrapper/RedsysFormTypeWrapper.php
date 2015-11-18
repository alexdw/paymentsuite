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

namespace PaymentSuite\RedsysBundle\Services\Wrapper;

use Symfony\Component\Form\FormFactory;

use PaymentSuite\RedsysBundle\Services\Interfaces\PaymentBridgeRedsysInterface;
use PaymentSuite\RedsysBundle\Exception\CurrencyNotSupportedException;
use PaymentSuite\RedsysBundle\Services\UrlFactory;

/**
 * RedsysMethodWrapper
 */
class RedsysFormTypeWrapper
{
    /**
     * @var FormFactory
     *
     * Form factory
     */
    protected $formFactory;

    /**
     * @var PaymentBridgeRedsysInterface
     *
     * Payment bridge
     */
    private $paymentBridge;

    /**
     * @var UrlFactory
     *
     * URL Factory service
     */
    private $urlFactory;

    /**
     * @var string
     *
     * Merchant code
     */
    private $merchantCode;

    /**
     * @var string
     *
     * Secret key
     */
    private $secretKey;

    /**
     * @var string
     *
     * Url
     */
    private $url;

    /**
     * @var array
     *
     * Payment vars
     */
    private $varsPay;

    /**
     * Formtype construct method
     *
     * @param FormFactory                  $formFactory   Form factory
     * @param PaymentBridgeRedsysInterface $paymentBridge Payment bridge
     * @param UrlFactory                   $urlFactory    URL Factory service
     * @param string                       $merchantCode  merchant code
     * @param string                       $secretKey     secret key
     * @param string                       $url           gateway url
     *
     */
    public function __construct(FormFactory $formFactory,
                                PaymentBridgeRedsysInterface $paymentBridge,
                                UrlFactory $urlFactory,
                                $merchantCode,
                                $merchantTerminal,
                                $secretKey,
                                $url)
    {
        $this->formFactory              = $formFactory;
        $this->paymentBridge            = $paymentBridge;
        $this->urlFactory               = $urlFactory;
        $this->merchantCode             = $merchantCode;
        $this->merchantTerminal         = $merchantTerminal;
        $this->secretKey                = $secretKey;
        $this->url                      = $url;
    }

    /**
     * Builds form given return, success and fail urls
     *
     * @return \Symfony\Component\Form\FormView
     */
    public function buildForm()
    {
        $orderId = $this
            ->paymentBridge
            ->getOrderId();

        $extraData = $this->paymentBridge->getExtraData();

        $formBuilder = $this
            ->formFactory
            ->createNamedBuilder(null);

        if (array_key_exists('transaction_type', $extraData)) {
            $Ds_Merchant_TransactionType    = $extraData['transaction_type'];
        } else {
            $Ds_Merchant_TransactionType = '0';
        }

        /*
         * Creates the return route for Redsys
         */
        $Ds_Merchant_MerchantURL = $this
            ->urlFactory
            ->getReturnRedsysUrl();

        /*
         * Creates the return route, when coming back
         * from Redsys web checkout and proccess is Ok
         */
        $Ds_Merchant_UrlOK = $this
            ->urlFactory
            ->getReturnUrlOkForOrderId($orderId);

        /*
         * Creates the cancel payment route, when coming back
         * from Redsys web checkout and proccess is error
         */
        $Ds_Merchant_UrlKO = $this
            ->urlFactory
            ->getReturnUrlKoForOrderId($orderId);

        $Ds_Merchant_Amount             = $this->paymentBridge->getAmount();
        $Ds_Merchant_Order              = $this->formatOrderNumber($this->paymentBridge->getOrderNumber());
        $Ds_Merchant_MerchantCode       = $this->merchantCode;
        $Ds_Merchant_Terminal           = $this->merchantTerminal;
        $Ds_Merchant_Currency           = $this->currencyTranslation($this->paymentBridge->getCurrency());

        $Ds_Signature_version           = "HMAC_SHA256_V1";



        $this->setParameter("DS_MERCHANT_AMOUNT", $Ds_Merchant_Amount);
        $this->setParameter("DS_MERCHANT_ORDER", $Ds_Merchant_Order);
        $this->setParameter("DS_MERCHANT_MERCHANTCODE", $Ds_Merchant_MerchantCode);
        $this->setParameter("DS_MERCHANT_CURRENCY", $Ds_Merchant_Currency);
        $this->setParameter("DS_MERCHANT_TERMINAL", $Ds_Merchant_Terminal);
        $this->setParameter("DS_MERCHANT_TRANSACTIONTYPE", $Ds_Merchant_TransactionType);


        if (array_key_exists('product_description', $extraData)) {
            $this->setParameter("Ds_Merchant_ProductDescription",  $extraData['product_description']);
        }

        if (array_key_exists('merchant_titular', $extraData)) {
            $this->setParameter("Ds_Merchant_Titular",  $extraData['merchant_titular']);
        }

        if (array_key_exists('merchant_name', $extraData)) {
            $this->setParameter("Ds_Merchant_MerchantName",  $extraData['merchant_name']);
        }

        $this->setParameter("DS_MERCHANT_MERCHANTURL", $Ds_Merchant_MerchantURL);
        $this->setParameter("DS_MERCHANT_URLOK", $Ds_Merchant_UrlOK);
        $this->setParameter("DS_MERCHANT_URLKO", $Ds_Merchant_UrlKO);

        $Ds_Merchant_MerchantSignature  = $this->shopSignature($Ds_Merchant_Order,$this->secretKey);

        $formBuilder
            ->setAction($this->url)
            ->setMethod('POST')
            ->add('Ds_SignatureVersion', 'hidden', array("data"=>$Ds_Signature_version))
            ->add('Ds_MerchantParameters', 'hidden', array("data"=>$this->merchantParams()))
            ->add('Ds_Signature', 'hidden', array("data"=>$Ds_Merchant_MerchantSignature))
        ;

        return $formBuilder->getForm()->createView();
    }

    /**
     * Creates signature to be sent to Redsys
     *
     * @param  string $amount          Amount
     * @param  string $order           Order number
     * @param  string $merchantCode    Merchant code
     * @param  string $currency        Currency
     * @param  string $transactionType Transaction type
     * @param  string $terminal        Terminal
     * @param  string $merchantURL     Merchant url
     * @param  string $secret          Secret key
     * @return string Signature
     */
    protected function shopSignature($Ds_Merchant_Order,$secret)
    {
        $key = base64_decode($secret);
        $params = $this->merchantParams();
        $key = $this->encrypt3DES($Ds_Merchant_Order, $key);
        $signature = base64_encode(hash_hmac('sha256', $params, $key, true));

        return $signature;

    }

    /**
     * Translates standard currency to Redsys currency code
     *
     * @param  string                                                             $currency Currency
     * @return string
     * @throws \PaymentSuite\RedsysBundle\Exception\CurrencyNotSupportedException
     */
    protected function currencyTranslation($currency)
    {
        switch ($currency) {
            case 'EUR':
                return '978';
            case 'USD':
                return '840';
            case 'GBP':
                return '826';
            case 'JPY':
                return '392';
            case 'ARS':
                return '032';
            case 'CAD':
                return '124';
            case 'CLF':
                return '152';
            case 'COP':
                return '170';
            case 'INR':
                return '356';
            case 'MXN':
                return '484';
            case 'PEN':
                return '604';
            case 'CHF':
                return '756';
            case 'BRL':
                return '986';
            case 'VEF':
                return '937';
            case 'TRY':
                return '949';
            default:
                throw new CurrencyNotSupportedException();
        }
    }

    /**
     * Formats order number to be Redsys compliant
     *
     * @param  string $orderNumber Order number
     * @return string $orderNumber
     */
    protected function formatOrderNumber($orderNumber)
    {
        //Falta comprobar que empieza por 4 numericos y que como mucho tiene 12 de longitud
        $length = strlen($orderNumber);
        $minLength = 4;

        if ($length < $minLength) {
            $orderNumber = str_pad($orderNumber, $minLength, '0', STR_PAD_LEFT);
        }

        return $orderNumber;
    }

    protected function merchantParams()
    {
        $params = base64_encode(json_encode($this->varsPay));

        return $params;
    }

    protected function encrypt3DES($message, $key){
        $bytes = array(0,0,0,0,0,0,0,0);
        $iv = implode(array_map("chr", $bytes));
        $ciphertext = mcrypt_encrypt(MCRYPT_3DES, $key, $message, MCRYPT_MODE_CBC, $iv);

        return $ciphertext;
    }
    protected function setParameter($key,$value){
        $this->varsPay[$key]=$value;
    }
}
