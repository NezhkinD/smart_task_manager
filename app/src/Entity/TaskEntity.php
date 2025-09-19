<?php

namespace App\Entity;

use App\Enum\Task\TypeEnum;
use App\Repository\TaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: self::TABLE_NAME)]
class TaskEntity
{
    public const TABLE_NAME = 'task';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    public string $title;

    #[ORM\Column(type: 'text')]
    public string $body;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $updatedAt;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $date;

    #[ORM\Column(type: 'string',  enumType: TypeEnum::class)]
    public TypeEnum $type;

    #[ORM\ManyToOne(inversedBy: 'taskEntities')]
    public UserEntity $user;
}
