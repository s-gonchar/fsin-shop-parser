<?php


namespace Services\FsinShopParser;


class Filter
{
    public $shopExternalId;

    /**
     * @param mixed $shopExternalId
     */
    public function setShopExternalId($shopExternalId): self
    {
        $this->shopExternalId = $shopExternalId;
        return $this;
    }
}