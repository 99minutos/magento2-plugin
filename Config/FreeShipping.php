<?php

namespace NoventaYNueveMinutos\Config;

class FreeShipping implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => __('Desactivado')],
            ['value' => 1, 'label' => __('Activado')]
        ];
    }
}
