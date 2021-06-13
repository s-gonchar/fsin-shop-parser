<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="products")
 */
class Product
{
    /**
     * @var int
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @ORM\Column(type="string")
     */
    private $name;

    /**
     * @var int
     * @ORM\Column(name="external_id", type="integer")
     */
    private $externalId;

    /**
     * @var string
     * @ORM\Column(type="string", nullable=true)
     */
    private $link;

    /**
     * @var bool
     * @ORM\Column(type="boolean")
     */
    private $inStock;

    /**
     * @var Shop
     * @ORM\ManyToOne(targetEntity=Shop::class)
     */
    private $shop;

    /**
     * Product constructor.
     * @param Shop $shop
     * @param string $name
     * @param int $externalId
     * @param int $inStock
     * @param string|null $link
     * @return Product
     */
    public static function create(
        Shop $shop,
        string $name,
        int $externalId,
        int $inStock,
        string $link = null
    ): self {
        $product = new self();
        $product->name = $name;
        $product->externalId = $externalId;
        $product->link = $link;
        $product->inStock = $inStock;
        $product->shop = $shop;
        return $product;
    }

    public function setInStock(bool $inStock): self
    {
        $this->inStock = $inStock;
        return $this;
    }

    /**
     * @return int
     */
    public function getExternalId(): int
    {
        return $this->externalId;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setLink(string $link): self
    {
        $this->link = $link;
        return $this;
    }


}