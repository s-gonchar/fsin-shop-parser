<?php

namespace Repositories;

use Doctrine\ORM\EntityManagerInterface;
use Entities\Agency;
use Entities\Product;
use Entities\Shop;

class ProductRepository extends AbstractRepository
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
        $this->repo = $entityManager->getRepository(Product::class);
    }

    public function findOneByExternalIdAndShop($id, Shop $shop): ?Product
    {
        /** @var Product|null $product */
        $product = $this->repo->findOneBy([
            'externalId' => $id,
            'shop' => $shop,
        ]);
        return $product;
    }
}