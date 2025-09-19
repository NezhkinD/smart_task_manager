<?php

namespace App\Entity;

use App\Enum\AppSetting\TypeEnum;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: self::TABLE_NAME)]
class AppSettingEntity
{
    public const TABLE_NAME = 'app_setting';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: 'string', length: 255)]
    public string $key;

    #[ORM\Column(type: 'text')]
    public string $value;

    #[ORM\Column(type: 'text')]
    public string $default;

    #[ORM\Column(type: 'text')]
    public string $description;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $createdAt;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $updatedAt;

    #[ORM\Column(type: 'string',  enumType: TypeEnum::class)]
    public TypeEnum $type;
}
