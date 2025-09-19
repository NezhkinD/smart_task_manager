<?php

namespace App\Entity;

use App\Repository\AuthTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthTokenRepository::class)]
#[ORM\Table(name: self::TABLE_NAME)]
class AuthTokenEntity
{
    public const TABLE_NAME = 'auth_token';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: 'text')]
    public string $accessToken;

    #[ORM\Column(type: 'text')]
    public string $refreshToken;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $expiresAt;

    #[ORM\ManyToOne(inversedBy: 'authTokenEntities')]
    public UserEntity $user;
}
