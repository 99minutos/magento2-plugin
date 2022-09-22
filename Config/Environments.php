<?php

namespace NoventaYNueveMinutos\Config;

class Environments implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => __('Sandbox')],
            ['value' => 1, 'label' => __('Producción')]
        ];
    }
}
