<?php

namespace App\Tests\Service;

use App\Dto\Llm\ParsedEventDto;
use App\Dto\SaluteSpeech\SpeechRecognitionResultDto;
use App\Entity\EventEntity;
use App\Entity\InputMessageEntity;
use App\Entity\UserEntity;
use App\Enum\Event\TypeEnum;
use App\Enum\InputMessage\StatusEnum;
use App\Enum\User\RoleEnum;
use App\Exception\LlmException;
use App\Exception\SaluteSpeechException;
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
    /** @var string[] Перехваченные тексты исходящих сообщений. */
    private array $sent = [];

    /** @var object[] Перехваченные сущности, переданные в persist(). */
    private array $persisted = [];

    private function existingUser(): UserEntity
    {
        $user = new UserEntity();
        $user->id = 55;
        $user->tgId = '55';

        return $user;
    }

    private function parsedEvent(): ParsedEventDto
    {
        return new ParsedEventDto('Стоматолог', 'Осмотр', '2026-06-24 09:00:00', '2026-06-24 10:00:00', '2026-06-24 00:00:00');
    }

    private function createdEvent(): EventEntity
    {
        $event = new EventEntity();
        $event->title = 'Стоматолог';
        $event->body = 'Осмотр';
        $event->startAt = new \DateTime('2026-06-24 09:00:00');
        $event->finishAt = new \DateTime('2026-06-24 10:00:00');
        $event->date = new \DateTime('2026-06-24 00:00:00');
        $event->type = TypeEnum::CREATED;

        return $event;
    }

    private function capturingBot(): TelegramBotService
    {
        $bot = $this->createStub(TelegramBotService::class);
        $bot->method('sendMessage')->willReturnCallback(function ($chatId, $text): void {
            $this->sent[] = $text;
        });
        $bot->method('getFile')->willReturn('voice/file_1.ogg');

        return $bot;
    }

    private function capturingEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return $em;
    }

    private function userRepoReturning(?UserEntity $user): UserRepository
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->willReturn($user);

        return $repo;
    }

    private function inputMessage(): ?InputMessageEntity
    {
        foreach ($this->persisted as $entity) {
            if ($entity instanceof InputMessageEntity) {
                return $entity;
            }
        }

        return null;
    }

    private function handler(
        TelegramBotService $bot,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        ?LlmService $llm = null,
        ?EventService $event = null,
        ?SaluteSpeechRecognitionService $recognition = null,
    ): TelegramUpdateHandler {
        return new TelegramUpdateHandler(
            $bot,
            $recognition ?? $this->createStub(SaluteSpeechRecognitionService::class),
            $llm ?? $this->createStub(LlmService::class),
            $event ?? $this->createStub(EventService::class),
            $this->createStub(GoogleCalendarLinkGenerator::class),
            $userRepository,
            $em,
        );
    }

    // --- handleUpdate pipeline ---

    public function testHandleStartSendsWelcome(): void
    {
        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager());

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'text' => '/start']]);

        self::assertNotEmpty($this->sent);
        self::assertStringContainsString('Smart Task Manager', $this->sent[0]);
    }

    public function testHandleTextCreatesEventAndMarksDone(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willReturn($this->parsedEvent());
        $event = $this->createStub(EventService::class);
        $event->method('createEvent')->willReturn($this->createdEvent());

        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager(), $llm, $event);

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'text' => 'Запиши к стоматологу']]);

        self::assertStringContainsString('Событие создано', implode("\n", $this->sent));
        self::assertSame(StatusEnum::DONE, $this->inputMessage()->status);
    }

    public function testHandleTextWithEventErrorsMarksFail(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willReturn($this->parsedEvent());
        $event = $this->createStub(EventService::class);
        $event->method('createEvent')->willReturn(['userId: Пользователь не найден']);

        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager(), $llm, $event);

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'text' => 'Запиши к стоматологу']]);

        self::assertStringContainsString('Ошибка создания события', implode("\n", $this->sent));
        self::assertSame(StatusEnum::FAIL, $this->inputMessage()->status);
    }

    public function testHandleTextLlmExceptionSendsCommentAndMarksFail(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willThrowException(new LlmException('Я умею только ставить события 🍕'));

        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager(), $llm);

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'text' => 'Закажи пиццу']]);

        self::assertContains('Я умею только ставить события 🍕', $this->sent);
        self::assertSame(StatusEnum::FAIL, $this->inputMessage()->status);
    }

    public function testHandleVoiceCreatesEvent(): void
    {
        $recognition = $this->createStub(SaluteSpeechRecognitionService::class);
        $recognition->method('recognize')->willReturn(new SpeechRecognitionResultDto(['Запиши к стоматологу'], [], 200));
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willReturn($this->parsedEvent());
        $event = $this->createStub(EventService::class);
        $event->method('createEvent')->willReturn($this->createdEvent());

        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager(), $llm, $event, $recognition);

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'voice' => ['file_id' => 'abc']]]);

        self::assertStringContainsString('Событие создано', implode("\n", $this->sent));
    }

    public function testHandleVoiceEmptyRecognitionMarksFail(): void
    {
        $recognition = $this->createStub(SaluteSpeechRecognitionService::class);
        $recognition->method('recognize')->willReturn(new SpeechRecognitionResultDto([], [], 200));

        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager(), null, null, $recognition);

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'voice' => ['file_id' => 'abc']]]);

        self::assertStringContainsString('Не удалось распознать речь', implode("\n", $this->sent));
        self::assertSame(StatusEnum::FAIL, $this->inputMessage()->status);
    }

    public function testHandleVoiceSpeechExceptionSendsError(): void
    {
        $recognition = $this->createStub(SaluteSpeechRecognitionService::class);
        $recognition->method('recognize')->willThrowException(new SaluteSpeechException('fail'));

        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager(), null, null, $recognition);

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'voice' => ['file_id' => 'abc']]]);

        self::assertStringContainsString('Ошибка распознавания речи', implode("\n", $this->sent));
    }

    public function testUnknownMessageTypeIsRejected(): void
    {
        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager());

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55']]]);

        self::assertStringContainsString('только текстовые и голосовые', implode("\n", $this->sent));
    }

    public function testUpdateWithoutMessageDoesNothing(): void
    {
        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager());

        $handler->handleUpdate(['edited_message' => []]);

        self::assertSame([], $this->sent);
    }

    public function testUnexpectedErrorIsCaught(): void
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willThrowException(new \RuntimeException('db down'));

        $handler = $this->handler($this->capturingBot(), $userRepository, $this->capturingEntityManager());

        $handler->handleUpdate(['message' => ['chat' => ['id' => 1], 'from' => ['id' => '55'], 'text' => 'привет']]);

        self::assertStringContainsString('непредвиденная ошибка', implode("\n", $this->sent));
    }

    public function testCreateInputMessagePersistsWithNewStatus(): void
    {
        $handler = $this->handler($this->capturingBot(), $this->userRepoReturning($this->existingUser()), $this->capturingEntityManager());

        $message = $handler->createInputMessage($this->existingUser(), 'привет', null);

        self::assertSame(StatusEnum::NEW, $message->status);
        self::assertSame('привет', $message->text);
        self::assertSame($message, $this->inputMessage());
    }

    // --- findOrCreateUser ---

    public function testReturnsExistingUserWithoutCreating(): void
    {
        $existing = new UserEntity();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())->method('findOneBy')
            ->with(['tgId' => '12345'])->willReturn($existing);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $handler = $this->handler($this->createStub(TelegramBotService::class), $userRepository, $entityManager);

        $user = $handler->findOrCreateUser('12345', ['username' => 'ignored']);

        self::assertSame($existing, $user);
    }

    public function testCreatesNewUserUsingUsername(): void
    {
        $persisted = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')
            ->willReturnCallback(function (UserEntity $u) use (&$persisted): void {
                $persisted = $u;
            });
        $entityManager->expects(self::once())->method('flush');

        $handler = $this->handler($this->createStub(TelegramBotService::class), $this->userRepoReturning(null), $entityManager);

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
        $handler = $this->handler($this->createStub(TelegramBotService::class), $this->userRepoReturning(null), $this->createStub(EntityManagerInterface::class));

        $user = $handler->findOrCreateUser('777', ['first_name' => 'John', 'last_name' => 'Doe']);

        self::assertSame('John Doe', $user->username);
    }

    public function testCreatesNewUserWithTgIdFallbackWhenNoName(): void
    {
        $handler = $this->handler($this->createStub(TelegramBotService::class), $this->userRepoReturning(null), $this->createStub(EntityManagerInterface::class));

        $user = $handler->findOrCreateUser('999', []);

        self::assertSame('tg_999', $user->username);
    }
}
