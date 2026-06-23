<?php

namespace App\Tests\Service;

use App\Entity\UserEntity;
use App\Enum\User\RoleEnum;
use App\Repository\UserRepository;
use App\Service\EventService;
use App\Service\GoogleCalendarLinkGenerator;
use App\Service\LlmService;
use App\Service\SaluteSpeechRecognitionService;
use App\Service\TelegramBotService;
use App\Service\TelegramUpdateHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TelegramUpdateHandlerTest extends TestCase
{
    /**
     * Остальные зависимости конструктора не участвуют в findOrCreateUser — отдаём заглушки.
     */
    private function makeHandler(UserRepository $userRepository, EntityManagerInterface $entityManager): TelegramUpdateHandler
    {
        return new TelegramUpdateHandler(
            $this->createStub(TelegramBotService::class),
            $this->createStub(SaluteSpeechRecognitionService::class),
            $this->createStub(LlmService::class),
            $this->createStub(EventService::class),
            $this->createStub(GoogleCalendarLinkGenerator::class),
            $userRepository,
            $entityManager,
        );
    }

    public function testReturnsExistingUserWithoutCreating(): void
    {
        $existing = new UserEntity();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())->method('findOneBy')
            ->with(['tgId' => '12345'])->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $handler = $this->makeHandler($userRepository, $entityManager);

        $user = $handler->findOrCreateUser('12345', ['username' => 'ignored']);

        self::assertSame($existing, $user);
    }

    public function testCreatesNewUserUsingUsername(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);

        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')
            ->willReturnCallback(function (UserEntity $u) use (&$persisted): void {
                $persisted = $u;
            });
        $entityManager->expects(self::once())->method('flush');

        $handler = $this->makeHandler($userRepository, $entityManager);

        $user = $handler->findOrCreateUser('12345', ['username' => 'john']);

        self::assertSame($persisted, $user);
        self::assertSame('12345', $user->tgId);
        self::assertSame('john', $user->username);
        self::assertSame('12345@telegram.local', $user->email);
        self::assertSame([RoleEnum::ROLE_USER->value], $user->roles);
        self::assertNotSame('', $user->password);
    }

    public function testCreatesNewUserUsingFirstAndLastNameFallback(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $handler = $this->makeHandler($userRepository, $entityManager);

        $user = $handler->findOrCreateUser('777', ['first_name' => 'John', 'last_name' => 'Doe']);

        self::assertSame('John Doe', $user->username);
    }

    public function testCreatesNewUserWithTgIdFallbackWhenNoName(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $handler = $this->makeHandler($userRepository, $entityManager);

        $user = $handler->findOrCreateUser('999', []);

        self::assertSame('tg_999', $user->username);
    }
}
