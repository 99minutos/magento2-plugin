<?php

namespace NoventaYNueveMinutos\Express\Model\Carrier;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Framework\HTTP\Client\Curl as Curl;
use Psr\Log\LoggerInterface;
use NoventaYNueveMinutos\Helper\Data as Helper;
/**
 * Custom shipping model
 */
class ExpressShipping extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'NoventaYNueveMinutos_Express';

    /**
     * @var bool
     */
    protected $_isFixed = true;

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    private $rateMethodFactory;

    private $curl;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param Curl $curl
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        Curl $curl,
        array $data = [],
        Helper $helperData
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);

        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->curl = $curl;
        $this->_logger = $logger;
        $this->_99Helper = $helperData;
    }

    /**
     * Custom Shipping Rates Collector
     *
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/99MinutosRates.log');
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $this->_logger = $logger;
        $this->_logger->debug('**************************');
        $this->_logger->debug('ExpressShipping');

        $this->_logger->debug('ExpressShipping - getConfigFlag');
        $this->_logger->debug($this->getConfigFlag('active'));

        if (!$this->getConfigFlag('active')) {
            $this->_logger->debug('ExpressShipping - no pasó el config flag');
            return false;
        }

        try{
            $apikey = $this->getConfigData('apikey');
            $data = $request->getData();
            $cartWeight = $request->getPackageWeight();

            $this->_logger->debug('ExpressShipping - apikey');
            $this->_logger->debug($apikey);

            $this->_logger->debug('ExpressShipping - data');
            $this->_logger->debug(json_encode($data));

            $payload = [
                "apikey" => $apikey,
                "env" => $this->_99Helper->getEnvironment('carriers/NoventaYNueveMinutos_Express/environment'),
                "data" => $data,
            ];

            $this->_logger->debug('ExpressShipping - request');
            $this->_logger->debug(json_encode($payload));

            /* FIX CON PLUGIN DHL RATES (https://support.dhlexpresscommerce.com/hc/en-gb/articles/360036285591-Rates-at-checkout-for-Magento-2) */
            $options = [ ];
            $this->curl->setOptions($options);
            /* ***************** */

            $this->curl->post("https://magento.99minutos.app/api/rates", $payload);

            $result = $this->curl->getBody();

            $this->_logger->debug('ExpressShipping - response');
            $this->_logger->debug(json_encode($result));

            $codes = json_decode($result, true)['codes'];

            if (!in_array(3, $codes)) {
                return false;
            }
        }catch (\Exception $e){
            $this->_logger->debug('ExpressShipping - EXCEPTION');
            $this->_logger->debug(json_encode($e));
            $this->_logger->debug(json_encode($e->getMessage()));
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));

        $free_shipping = $this->getConfigData('free_shipping');
        $this->_logger->debug('ExpressShipping - free_shipping');
        $this->_logger->debug($free_shipping);

        $free_from = $this->getConfigData('free_from');
        $this->_logger->debug('ExpressShipping - free_from');
        $this->_logger->debug($free_from);

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $cart = $objectManager->get('\Magento\Checkout\Model\Cart');

        // $subTotal = $cart->getQuote()->getSubtotal();
        // $this->_logger->debug('ExpressShipping - subTotal');
        // $this->_logger->debug($subTotal);

        $grandTotal = $cart->getQuote()->getGrandTotal();
        $this->_logger->debug('ExpressShipping - grandTotal');
        $this->_logger->debug($grandTotal);

        $shippingCostEx = $this->getPackageSizeCost($cartWeight,
            $this->getConfigData('shipping_cost_xs'),
            $this->getConfigData('shipping_cost_s'),
            $this->getConfigData('shipping_cost_m'),
            $this->getConfigData('shipping_cost_l')
        );

        if( $free_shipping ){
            $this->_logger->debug('ExpressShipping - grandTotal >= free_shipping');
            $this->_logger->debug($grandTotal >= $free_from);
            if( $grandTotal >= $free_from ){
                $shippingCostEx = "0";
            }
        }

        $this->_logger->debug('ExpressShipping - shippingCost');
        $this->_logger->debug($shippingCostEx);

        if($shippingCostEx == 'Package size not allowed'){
            return false;
        }

        $method->setPrice($shippingCostEx);
        $method->setCost($shippingCostEx);

        $result->append($method);

        $this->_logger->debug('ExpressShipping - return');
        $this->_logger->debug(json_encode($result));

        return $result;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    private function getPackageSizeCost($weight, $xs, $s, $m, $l){
        if($weight == 0){
            return $m;
        }
        if (0<$weight && $weight<=1){
            return $xs;
        }elseif (1<$weight && $weight<=2){
            return $s;
        }elseif (2<$weight && $weight<=3){
            return $m;
        }elseif (3<$weight && $weight<=5){
            return $l;
        }else{
            return "Package size not allowed";
        }

    }

}
