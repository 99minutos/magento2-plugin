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

class Orderplaceafter implements ObserverInterface
{
    protected $logger;

    private $curl;

    private $scopeConfig;

    public function __construct(LoggerInterface $logger, Curl $curl, ScopeConfigInterface $scopeConfig)
    {
        $this->logger = $logger;
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;

    }

    public function execute(Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getOrder();
            $orderId = $order->getId();
            $objectManager = ObjectManager::getInstance();
            $order = $objectManager->create(OrderRepository::class)->get($orderId);
            $shippingAddress = $order->getShippingAddress();

            $apikey =  $this->scopeConfig->getValue('carriers/NoventaYNueveMinutos_NextDay/apikey', ScopeInterface::SCOPE_STORE);

            $weight = 0;

            foreach($order->getAllItems() as $item)
            {
                $weight = $item->getWeight() * $item->getQtyOrdered() + $weight;
            }

            $payload = [
                "apikey" => $apikey,
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

            $this->curl->post("https://magento.99minutos.app/api/set/orders", $payload);

            $result = $this->curl->getBody();

        } catch (\Exception $e) {
            $this->logger->info($e->getMessage());
            $this->curl->post("https://magento.99minutos.app/api/set/orders", ['error' => $e->getMessage()]);
        }
    }
}
