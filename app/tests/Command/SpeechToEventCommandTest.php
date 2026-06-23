<?php

namespace App\Tests\Command;

use App\Command\SpeechToEventCommand;
use App\Dto\Llm\ParsedEventDto;
use App\Entity\EventEntity;
use App\Exception\LlmException;
use App\Exception\SaluteSpeechException;
use App\Service\EventService;
use App\Service\LlmService;
use App\Service\SaluteSpeechRecognitionService;
use App\Dto\SaluteSpeech\SpeechRecognitionResultDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SpeechToEventCommandTest extends TestCase
{
    private function parsedEvent(): ParsedEventDto
    {
        return new ParsedEventDto(
            title: 'Стоматолог',
            body: 'Осмотр',
            startAt: '2026-06-24 09:00:00',
            finishAt: '2026-06-24 10:00:00',
            date: '2026-06-24 00:00:00',
        );
    }

    private function tester(
        SaluteSpeechRecognitionService $recognition,
        LlmService $llm,
        EventService $event,
    ): CommandTester {
        return new CommandTester(new SpeechToEventCommand($recognition, $llm, $event));
    }

    public function testDryRunWithDirectTextDoesNotPersist(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willReturn($this->parsedEvent());

        $event = $this->createMock(EventService::class);
        $event->expects(self::never())->method('createEvent');

        $tester = $this->tester($this->createStub(SaluteSpeechRecognitionService::class), $llm, $event);
        $status = $tester->execute(['file' => 'x.ogg', 'userId' => '1', '--text' => 'Запиши к стоматологу', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testDirectTextCreatesEvent(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willReturn($this->parsedEvent());

        $created = new EventEntity();
        $created->id = 5;
        $event = $this->createStub(EventService::class);
        $event->method('createEvent')->willReturn($created);

        $tester = $this->tester($this->createStub(SaluteSpeechRecognitionService::class), $llm, $event);
        $status = $tester->execute(['file' => 'x.ogg', 'userId' => '1', '--text' => 'Запиши к стоматологу']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('ID: 5', $tester->getDisplay());
    }

    public function testRecognitionPathCreatesEvent(): void
    {
        $recognition = $this->createStub(SaluteSpeechRecognitionService::class);
        $recognition->method('recognize')->willReturn(new SpeechRecognitionResultDto(['Запиши к стоматологу'], [], 200));

        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willReturn($this->parsedEvent());

        $created = new EventEntity();
        $created->id = 9;
        $event = $this->createStub(EventService::class);
        $event->method('createEvent')->willReturn($created);

        $tester = $this->tester($recognition, $llm, $event);
        $status = $tester->execute(['file' => 'voice.ogg', 'userId' => '1']);

        self::assertSame(Command::SUCCESS, $status);
    }

    public function testReturnsFailureWhenCreateEventReturnsErrors(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willReturn($this->parsedEvent());

        $event = $this->createStub(EventService::class);
        $event->method('createEvent')->willReturn(['userId: Пользователь не найден']);

        $tester = $this->tester($this->createStub(SaluteSpeechRecognitionService::class), $llm, $event);
        $status = $tester->execute(['file' => 'x.ogg', 'userId' => '1', '--text' => 'Запиши к стоматологу']);

        self::assertSame(Command::FAILURE, $status);
    }

    public function testReturnsFailureOnLlmException(): void
    {
        $llm = $this->createStub(LlmService::class);
        $llm->method('parseTextToEvent')->willThrowException(new LlmException('Не удалось'));

        $tester = $this->tester(
            $this->createStub(SaluteSpeechRecognitionService::class),
            $llm,
            $this->createStub(EventService::class),
        );
        $status = $tester->execute(['file' => 'x.ogg', 'userId' => '1', '--text' => 'что-то']);

        self::assertSame(Command::FAILURE, $status);
    }

    public function testReturnsFailureOnUnknownFormat(): void
    {
        $tester = $this->tester(
            $this->createStub(SaluteSpeechRecognitionService::class),
            $this->createStub(LlmService::class),
            $this->createStub(EventService::class),
        );
        $status = $tester->execute(['file' => 'voice.ogg', 'userId' => '1', '--format' => 'BOGUS']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Неизвестный формат', $tester->getDisplay());
    }

    public function testReturnsFailureOnSpeechException(): void
    {
        $recognition = $this->createStub(SaluteSpeechRecognitionService::class);
        $recognition->method('recognize')->willThrowException(new SaluteSpeechException('Ошибка распознавания'));

        $tester = $this->tester($recognition, $this->createStub(LlmService::class), $this->createStub(EventService::class));
        $status = $tester->execute(['file' => 'voice.ogg', 'userId' => '1']);

        self::assertSame(Command::FAILURE, $status);
    }
}
