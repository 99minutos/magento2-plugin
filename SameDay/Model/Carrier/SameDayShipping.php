<?php

namespace NoventaYNueveMinutos\SameDay\Model\Carrier;

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
class SameDayShipping extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = 'NoventaYNueveMinutos_SameDay';

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
        LoggerInterface $logger,
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
        $this->_logger->debug('SameDayShipping');

        $this->_logger->debug('SameDayShipping - getConfigFlag');
        $this->_logger->debug($this->getConfigFlag('active'));

        if (!$this->getConfigFlag('active')) {
            $this->_logger->debug('SameDayShipping - no pasó el config flag');
            return false;
        }

        try{
            $apikey     = $this->getConfigData('apikey');
            $env        = $this->getConfigData('environment');
            $data       = $request->getData();
            $cartWeight = $request->getPackageWeight();

            $this->_logger->debug('SameDayShipping - apikey');
            $this->_logger->debug($apikey);

            $this->_logger->debug('SameDayShipping - data');
            $this->_logger->debug(json_encode($data));

            $payload = [
                "apikey" => $apikey,
                "env" => $this->_99Helper->getEnvironment('carriers/NoventaYNueveMinutos_SameDay/environment'),
                "data" => $data,
            ];

            $this->_logger->debug('SameDayShipping - request');
            $this->_logger->debug(json_encode($payload));

            /* FIX CON PLUGIN DHL RATES (https://support.dhlexpresscommerce.com/hc/en-gb/articles/360036285591-Rates-at-checkout-for-Magento-2) */
            $options = [ ];
            $this->curl->setOptions($options);
            /* ***************** */

            $this->_logger->debug('SameDayShipping - enviroment');
            $this->_logger->debug($env);

            if( $env ){
                $this->_logger->debug('SameDayShipping - url');
                $this->_logger->debug("https://magento.99minutos.app/api/rates");

                $this->curl->post("https://magento.99minutos.app/api/rates", $payload);
            }else{
                $this->_logger->debug('SameDayShipping - url');
                $this->_logger->debug("https://bubbling-mist-kkispsa6xfhx.vapor-farm-a1.com/api/rates");

                $this->curl->post("https://bubbling-mist-kkispsa6xfhx.vapor-farm-a1.com/api/rates", $payload);
            }

            $result = $this->curl->getBody();

            $this->_logger->debug('SameDayShipping - response');
            $this->_logger->debug(json_encode($result));

            $codes = json_decode($result, true)['codes'];

            if (!in_array(1, $codes)) {
                return false;
            }
        }catch(\Exception $e){
            $this->_logger->debug('SameDayShipping - EXCEPTION');
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

        $shippingCost = $this->getPackageSizeCost($cartWeight,
            $this->getConfigData('shipping_cost_xs'),
            $this->getConfigData('shipping_cost_s'),
            $this->getConfigData('shipping_cost_m'),
            $this->getConfigData('shipping_cost_l'),
            $this->getConfigData('shipping_cost_xl')
        );

        if($shippingCost == 'Package size not allowed'){
            return false;
        }

        $method->setPrice($shippingCost);
        $method->setCost($shippingCost);

        $result->append($method);

        return $result;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

    private function getPackageSizeCost($weight, $xs, $s, $m, $l, $xl){
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
        }elseif (5<$weight && $weight<=25){
            return $xl;
        }else{
            return "Package size not allowed";
        }

    }
}
