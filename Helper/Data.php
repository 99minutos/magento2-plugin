<?php

namespace NoventaYNueveMinutos\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;


class Data extends AbstractHelper
{
    public function getConfigValue($field, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getEnvironment($field)
    {
        $environment =  $this->getConfigValue($field);
        $result = '';
        switch ($environment) {
            case 0:
                $result = 'sandbox';
                break;
            case 1:
                $result = 'produccion';
                break;
            default:
                $result = 'produccion';
                break;
        }
        return $result;
    }
}
