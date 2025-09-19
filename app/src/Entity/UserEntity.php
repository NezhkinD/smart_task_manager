<?php

namespace App\Entity;

use App\Enum\User\RoleEnum;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: self::TABLE_NAME)]
class UserEntity
{
    public const TABLE_NAME = 'user_tbl';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(type: 'string', length: 255)]
    public string $username;

    #[ORM\Column(type: 'string', length: 255)]
    public string $password;

    #[ORM\Column(type: 'string', length: 255)]
    public string $email;

    #[ORM\Column(type: 'datetime')]
    public \DateTime $createdAt;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $tgId = null;

    /**
     * @var array<int, string>
     * @see RoleEnum
     */
    #[ORM\Column()]
    public array $roles;

    /**
     * @var Collection<int, AuthTokenEntity>
     */
    #[ORM\OneToMany(targetEntity: AuthTokenEntity::class, mappedBy: 'userId')]
    private Collection $authTokenEntities;

    public function __construct()
    {
        $this->authTokenEntities = new ArrayCollection();
    }

    /**
     * @return Collection<int, AuthTokenEntity>
     */
    public function getAuthTokenEntities(): Collection
    {
        return $this->authTokenEntities;
    }

    public function addAuthTokenEntity(AuthTokenEntity $authTokenEntity): static
    {
        if (!$this->authTokenEntities->contains($authTokenEntity)) {
            $this->authTokenEntities->add($authTokenEntity);
            $authTokenEntity->setUserId($this);
        }

        return $this;
    }

    public function removeAuthTokenEntity(AuthTokenEntity $authTokenEntity): static
    {
        if ($this->authTokenEntities->removeElement($authTokenEntity)) {
            // set the owning side to null (unless already changed)
            if ($authTokenEntity->getUserId() === $this) {
                $authTokenEntity->setUserId(null);
            }
        }

        return $this;
    }
}
