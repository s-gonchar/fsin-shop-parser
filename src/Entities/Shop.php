<?php

namespace Entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="shops")
 */
class Shop
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
     * @var Agency
     * @ORM\ManyToOne(targetEntity=Agency::class)
     */
    private $agency;

    public static function create(Agency $agency, string $name, int $externalId): self
    {
        $self = new self();
        $self->name = $name;
        $self->externalId = $externalId;
        $self->agency = $agency;

        return $self;
    }

    public function getExternalId(): int
    {
        return $this->externalId;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return Agency
     */
    public function getAgency(): Agency
    {
        return $this->agency;
    }
}