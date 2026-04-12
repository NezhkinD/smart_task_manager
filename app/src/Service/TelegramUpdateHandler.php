<?php

namespace App\Service;

use App\Dto\Event\CreateEventRequestDto;
use App\Entity\EventEntity;
use App\Entity\InputMessageEntity;
use App\Entity\UserEntity;
use App\Enum\InputMessage\StatusEnum;
use App\Enum\SaluteSpeech\AudioFormatEnum;
use App\Enum\User\RoleEnum;
use App\Exception\LlmException;
use App\Exception\SaluteSpeechException;
use App\Exception\TelegramBotException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class TelegramUpdateHandler
{
    private const DIR_TO_FILES = __DIR__ . '/../../var/files/';

    public function __construct(
        private readonly TelegramBotService $telegramBotService,
        private readonly SaluteSpeechRecognitionService $recognitionService,
        private readonly LlmService $llmService,
        private readonly EventService $eventService,
        private readonly GoogleCalendarLinkGenerator $calendarLinkGenerator,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $update
     */
    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        if ($message === null) {
            return;
        }

        $chatId = $message['chat']['id'];
        $from = $message['from'] ?? [];

        try {
            $user = $this->findOrCreateUser((string) $from['id'], $from);

            if (isset($message['text'])) {
                $text = $message['text'];

                if (str_starts_with($text, '/start')) {
                    $this->handleStart($chatId);
                    return;
                }

                $this->handleText($chatId, $user, $text);
                return;
            }

            if (isset($message['voice'])) {
                $this->handleVoice($chatId, $user, $message['voice']);
                return;
            }

            $this->telegramBotService->sendMessage($chatId, 'Я принимаю только текстовые и голосовые сообщения.');
        } catch (\Throwable $e) {
            $this->telegramBotService->sendMessage($chatId, 'Произошла непредвиденная ошибка. Попробуйте позже.');
        }
    }

    private function handleStart(int|string $chatId): void
    {
        $text = <<<TEXT
Привет! Я Smart Task Manager бот.

Отправь мне текстовое или голосовое сообщение с описанием события, и я создам его для тебя.

Например: "Встреча с командой завтра в 15:00"
TEXT;

        $this->telegramBotService->sendMessage($chatId, $text);
    }

    private function handleText(int|string $chatId, UserEntity $user, string $text): void
    {
        $inputMessage = $this->createInputMessage($user, $text, null);

        try {
            $currentDateTime = (new \DateTime())->format('Y-m-d H:i:s');
            $parsedEvent = $this->llmService->parseTextToEvent($text, $currentDateTime);

            $this->createAndSendEvent($chatId, $user, $inputMessage, $parsedEvent);
        } catch (LlmException $e) {
            $inputMessage->status = StatusEnum::FAIL;
            $this->entityManager->flush();
            $this->telegramBotService->sendMessage($chatId, $e->getMessage());
        } catch (\Throwable $e) {
            $inputMessage->status = StatusEnum::FAIL;
            $this->entityManager->flush();
            $this->telegramBotService->sendMessage($chatId, 'Ошибка обработки сообщения. Попробуйте позже.');
        }
    }

    /**
     * @param array<string, mixed> $voice
     */
    private function handleVoice(int|string $chatId, UserEntity $user, array $voice): void
    {
        $fileId = $voice['file_id'];

        try {
            $telegramFilePath = $this->telegramBotService->getFile($fileId);
            $localFileName = uniqid('tg_voice_') . '.ogg';
            $localPath = self::DIR_TO_FILES . $localFileName;

            $this->telegramBotService->downloadFile($telegramFilePath, $localPath);

            $inputMessage = $this->createInputMessage($user, null, $localPath);

            $speechResult = $this->recognitionService->recognize($localPath, AudioFormatEnum::OPUS);
            $text = $speechResult->getText();

            if (empty($text)) {
                $inputMessage->status = StatusEnum::FAIL;
                $this->entityManager->flush();
                $this->telegramBotService->sendMessage($chatId, 'Не удалось распознать речь. Попробуйте ещё раз.');
                return;
            }

            $inputMessage->text = $text;
            $this->entityManager->flush();

            $currentDateTime = (new \DateTime())->format('Y-m-d H:i:s');
            $parsedEvent = $this->llmService->parseTextToEvent($text, $currentDateTime);

            $this->createAndSendEvent($chatId, $user, $inputMessage, $parsedEvent);
        } catch (SaluteSpeechException $e) {
            if (isset($inputMessage)) {
                $inputMessage->status = StatusEnum::FAIL;
                $this->entityManager->flush();
            }
            $this->telegramBotService->sendMessage($chatId, 'Ошибка распознавания речи. Попробуйте ещё раз.');
        } catch (LlmException $e) {
            if (isset($inputMessage)) {
                $inputMessage->status = StatusEnum::FAIL;
                $this->entityManager->flush();
            }
            $this->telegramBotService->sendMessage($chatId, $e->getMessage());
        } catch (\Throwable $e) {
            if (isset($inputMessage)) {
                $inputMessage->status = StatusEnum::FAIL;
                $this->entityManager->flush();
            }
            $this->telegramBotService->sendMessage($chatId, 'Ошибка обработки сообщения. Попробуйте позже.');
        }
    }

    private function createAndSendEvent(
        int|string $chatId,
        UserEntity $user,
        InputMessageEntity $inputMessage,
        \App\Dto\Llm\ParsedEventDto $parsedEvent,
    ): void {
        $createDto = new CreateEventRequestDto();
        $createDto->title = $parsedEvent->title;
        $createDto->body = $parsedEvent->body;
        $createDto->startAt = $parsedEvent->startAt;
        $createDto->finishAt = $parsedEvent->finishAt;
        $createDto->date = $parsedEvent->date;
        $createDto->type = 'created';
        $createDto->userId = $user->id;

        $result = $this->eventService->createEvent($createDto);

        if ($result instanceof EventEntity) {
            $inputMessage->status = StatusEnum::DONE;
            $this->entityManager->flush();

            $calendarLink = $this->calendarLinkGenerator->generateLink($result);

            $startFormatted = $result->startAt->format('d.m.Y H:i');
            $finishFormatted = $result->finishAt->format('d.m.Y H:i');

            $responseText = <<<HTML
Событие создано!

<b>{$result->title}</b>
{$result->body}

Начало: {$startFormatted}
Окончание: {$finishFormatted}

📅 <a href="{$calendarLink}">Добавить в Google Calendar</a>
HTML;

            $this->telegramBotService->sendMessage($chatId, $responseText);
            return;
        }

        $inputMessage->status = StatusEnum::FAIL;
        $this->entityManager->flush();

        $errors = implode("\n", $result);
        $this->telegramBotService->sendMessage($chatId, "Ошибка создания события:\n{$errors}");
    }

    /**
     * @param array<string, mixed> $fromData
     */
    public function findOrCreateUser(string $tgUserId, array $fromData): UserEntity
    {
        $user = $this->userRepository->findOneBy(['tgId' => $tgUserId]);

        if ($user !== null) {
            return $user;
        }

        $username = $fromData['username']
            ?? trim(($fromData['first_name'] ?? '') . ' ' . ($fromData['last_name'] ?? ''))
            ?: 'tg_' . $tgUserId;

        $user = new UserEntity();
        $user->tgId = $tgUserId;
        $user->username = $username;
        $user->email = $tgUserId . '@telegram.local';
        $user->password = bin2hex(random_bytes(16));
        $user->roles = [RoleEnum::ROLE_USER->value];
        $user->createdAt = new \DateTime();

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function createInputMessage(UserEntity $user, ?string $text, ?string $audioPath): InputMessageEntity
    {
        $inputMessage = new InputMessageEntity();
        $inputMessage->user = $user;
        $inputMessage->text = $text;
        $inputMessage->audioPath = $audioPath;
        $inputMessage->status = StatusEnum::NEW;
        $inputMessage->createdAt = new \DateTime();

        $this->entityManager->persist($inputMessage);
        $this->entityManager->flush();

        return $inputMessage;
    }
}
