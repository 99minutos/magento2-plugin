<?php

namespace NoventaYNueveMinutos\NextDay\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl as Curl;
use Magento\Sales\Model\OrderRepository;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use NoventaYNueveMinutos\Helper\Data as Helper;

class Orderplaceafter implements ObserverInterface
{
    private $curl;

    private $scopeConfig;

    public function __construct(
        LoggerInterface $logger,
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        Helper $helperData
    ) {
        $this->_logger = $logger;
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->_99Helper = $helperData;
    }

    public function execute(Observer $observer)
    {
        try {
            $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/99MinutosOrder.log');
            $logger = new \Zend_Log();
            $logger->addWriter($writer);
            $this->_logger = $logger;
            $this->_logger->debug('**************************');
            $this->_logger->debug('Orderplaceafter');

            $order = $observer->getEvent()->getOrder();
            $orderId = $order->getId();
            $objectManager = ObjectManager::getInstance();
            $order = $objectManager->create(OrderRepository::class)->get($orderId);
            $shippingAddress = $order->getShippingAddress();

            $this->_logger->debug('Orderplaceafter - order');
            $this->_logger->debug(json_encode($order));

            $this->_logger->debug('Orderplaceafter - shippingAddress');
            $this->_logger->debug(json_encode($shippingAddress));

            $apikey =  $this->scopeConfig->getValue('carriers/NoventaYNueveMinutos_NextDay/apikey', ScopeInterface::SCOPE_STORE);

            $this->_logger->debug('Orderplaceafter - apikey');
            $this->_logger->debug($apikey);

            $weight = 0;

            foreach($order->getAllItems() as $item)
            {
                $weight = $item->getWeight() * $item->getQtyOrdered() + $weight;
            }

            $payload = [
                "apikey" => $apikey,
                "env" => $this->_99Helper->getEnvironment('carriers/NoventaYNueveMinutos_NextDay/environment'),
                "orderNumber" => $orderId,
                "orderId" => $order->getEntityId(),
                "firstName" => $order->getCustomerFirstname(),
                "lastName" => $order->getCustomerLastname(),
                "city" => $shippingAddress->getCity(),
                "street" => $shippingAddress->getStreet(),
                "province" => $shippingAddress->getRegionCode(),
                "zipCode" => $shippingAddress->getPostcode(),
                "phone" => $shippingAddress->getTelephone(),
                "price" => $order->getGrandTotal(),
                "paymentMethod" => $order->getPayment()->getMethod(),
                "weight" => $weight,
                "email" => $order->getShippingAddress()->getData('email'),
                "shippingMethod" => $order->getShippingMethod(),
                "country" => $shippingAddress->getCountryId(),
            ];

            $this->_logger->debug('Orderplaceafter - request');
            $this->_logger->debug(json_encode($payload));

            $this->curl->post("https://magento.99minutos.app/api/set/orders", $payload);

            $result = $this->curl->getBody();

            $this->_logger->debug('Orderplaceafter - response');
            $this->_logger->debug(json_encode($result));
        } catch (\Exception $e) {
            $this->_logger->debug('Orderplaceafter - EXCEPTION');
            $this->_logger->debug(json_encode($e));
            $this->_logger->debug(json_encode($e->getMessage()));

            $this->_logger->info($e->getMessage());
            $this->curl->post("https://magento.99minutos.app/api/set/orders", ['error' => $e->getMessage()]);
        }
    }
}
