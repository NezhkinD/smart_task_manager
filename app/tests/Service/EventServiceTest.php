<?php

namespace App\Tests\Service;

use App\Dto\Event\CreateEventRequestDto;
use App\Entity\EventEntity;
use App\Entity\UserEntity;
use App\Enum\Event\TypeEnum;
use App\Repository\UserRepository;
use App\Service\EventService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EventServiceTest extends TestCase
{
    private function makeDto(): CreateEventRequestDto
    {
        $dto = new CreateEventRequestDto();
        $dto->title = 'Стоматолог';
        $dto->body = 'Профилактический осмотр';
        $dto->startAt = '2026-06-24 09:00:00';
        $dto->finishAt = '2026-06-24 10:00:00';
        $dto->date = '2026-06-24 00:00:00';
        $dto->type = 'created';
        $dto->userId = 42;

        return $dto;
    }

    private function validatorReturning(ConstraintViolationList $violations): ValidatorInterface
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn($violations);

        return $validator;
    }

    public function testReturnsValidationErrors(): void
    {
        $validator = $this->validatorReturning(new ConstraintViolationList([
            new ConstraintViolation('Это поле обязательно', null, [], null, 'title', null),
        ]));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new EventService($validator, $entityManager, $this->createStub(UserRepository::class));

        $result = $service->createEvent($this->makeDto());

        self::assertSame(['title: Это поле обязательно'], $result);
    }

    public function testReturnsErrorWhenFinishNotAfterStart(): void
    {
        $validator = $this->validatorReturning(new ConstraintViolationList());

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::never())->method('find');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $service = new EventService($validator, $entityManager, $userRepository);

        $dto = $this->makeDto();
        $dto->finishAt = '2026-06-24 09:00:00'; // равно startAt → не позже

        $result = $service->createEvent($dto);

        self::assertSame(['finishAt: Дата окончания должна быть позже даты начала'], $result);
    }

    public function testReturnsErrorWhenUserNotFound(): void
    {
        $validator = $this->validatorReturning(new ConstraintViolationList());

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())->method('find')->with(42)->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new EventService($validator, $entityManager, $userRepository);

        $result = $service->createEvent($this->makeDto());

        self::assertSame(['userId: Пользователь не найден'], $result);
    }

    public function testCreatesAndPersistsEvent(): void
    {
        $user = new UserEntity();

        $validator = $this->validatorReturning(new ConstraintViolationList());

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())->method('find')->with(42)->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(EventEntity::class));
        $entityManager->expects(self::once())->method('flush');

        $service = new EventService($validator, $entityManager, $userRepository);

        $result = $service->createEvent($this->makeDto());

        self::assertInstanceOf(EventEntity::class, $result);
        self::assertSame('Стоматолог', $result->title);
        self::assertSame('Профилактический осмотр', $result->body);
        self::assertSame(TypeEnum::CREATED, $result->type);
        self::assertSame($user, $result->user);
        self::assertSame('2026-06-24 09:00:00', $result->startAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-24 10:00:00', $result->finishAt->format('Y-m-d H:i:s'));
    }
}
