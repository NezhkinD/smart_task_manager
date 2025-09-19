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
    public Collection $authTokenEntities;

    /**
     * @var Collection<int, IntegrationsAccessEntity>
     */
    #[ORM\OneToMany(targetEntity: IntegrationsAccessEntity::class, mappedBy: 'userId')]
    public Collection $integrationsAccessEntities;

    /**
     * @var Collection<int, EventEntity>
     */
    #[ORM\OneToMany(targetEntity: EventEntity::class, mappedBy: 'userId')]
    public Collection $eventEntities;

    /**
     * @var Collection<int, TaskEntity>
     */
    #[ORM\OneToMany(targetEntity: TaskEntity::class, mappedBy: 'userId')]
    public Collection $taskEntities;

    /**
     * @var Collection<int, InputMessageEntity>
     */
    #[ORM\OneToMany(targetEntity: InputMessageEntity::class, mappedBy: 'userId')]
    public Collection $inputMessageEntities;


    public function __construct()
    {
        $this->authTokenEntities = new ArrayCollection();
        $this->integrationsAccessEntities = new ArrayCollection();
        $this->eventEntities = new ArrayCollection();
        $this->taskEntities = new ArrayCollection();
        $this->inputMessageEntities = new ArrayCollection();
    }

    public function addAuthTokenEntity(AuthTokenEntity $authTokenEntity): static
    {
        if (!$this->authTokenEntities->contains($authTokenEntity)) {
            $this->authTokenEntities->add($authTokenEntity);
            $authTokenEntity->user = $this;
        }

        return $this;
    }
}
