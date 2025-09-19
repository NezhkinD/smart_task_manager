<?php

namespace App\Entity;

use App\Repository\IntegrationsAccessRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IntegrationsAccessRepository::class)]
#[ORM\Table(name: self::TABLE_NAME)]
class IntegrationsAccessEntity
{
    public const TABLE_NAME = 'integrations_access';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: 'string', length: 255)]
    public string $name;

    #[ORM\Column(type: 'text')]
    public string $value;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $updatedAt;

    #[ORM\ManyToOne(inversedBy: 'integrationsAccessEntities')]
    public UserEntity $user;
}
