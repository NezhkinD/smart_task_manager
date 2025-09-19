<?php

namespace App\Entity;

use App\Enum\InputMessage\StatusEnum;
use App\Repository\InputMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InputMessageRepository::class)]
#[ORM\Table(name: self::TABLE_NAME)]
class InputMessageEntity
{
    public const TABLE_NAME = 'input_message';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $createdAt;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $text = null;

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $audioPath = null;

    #[ORM\ManyToOne(inversedBy: 'inputMessageEntities')]
    public UserEntity $user;

    #[ORM\Column(type: 'string',  enumType: StatusEnum::class)]
    public StatusEnum $status;
}
