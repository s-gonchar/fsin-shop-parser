<?php


namespace Repositories;


use Doctrine\ORM\EntityManagerInterface;
use Entities\Shop;

class ShopRepository extends AbstractRepository
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
        $this->repo = $entityManager->getRepository(Shop::class);
    }

    public function findOneByExternalId($id): ?Shop
    {
        /** @var Shop|null $shop */
        $shop = $this->repo->findOneBy(['externalId' => $id]);
        return $shop;
    }

    /**
     * @param $id
     * @return Shop
     * @throws \Exception
     */
    public function getByExternalId($id): Shop
    {
        $shop = $this->findOneByExternalId($id);
        if (!$shop) {
            throw new \Exception("Shop with externalId {$id} not found");
        }

        return $shop;
    }

    /**
     * @return Shop[]
     */
    public function getAll(): array
    {
        return $this->repo->findAll();
    }
}