<?php

namespace App\Tests\Command;

use App\Command\SaluteSpeechRecognizeCommand;
use App\Dto\SaluteSpeech\SpeechRecognitionResultDto;
use App\Exception\SaluteSpeechException;
use App\Service\SaluteSpeechRecognitionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SaluteSpeechRecognizeCommandTest extends TestCase
{
    private function tester(SaluteSpeechRecognitionService $recognition): CommandTester
    {
        return new CommandTester(new SaluteSpeechRecognizeCommand($recognition));
    }

    public function testRecognizesSuccessfully(): void
    {
        $recognition = $this->createStub(SaluteSpeechRecognitionService::class);
        $recognition->method('recognize')->willReturn(
            new SpeechRecognitionResultDto(['Привет мир'], [['positive' => 0.8]], 200),
        );

        $tester = $this->tester($recognition);
        $status = $tester->execute(['file' => 'voice.ogg']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Привет мир', $tester->getDisplay());
    }

    public function testReturnsFailureOnUnknownFormat(): void
    {
        $tester = $this->tester($this->createStub(SaluteSpeechRecognitionService::class));
        $status = $tester->execute(['file' => 'voice.ogg', '--format' => 'BOGUS']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Неизвестный формат', $tester->getDisplay());
    }

    public function testReturnsFailureOnSpeechException(): void
    {
        $recognition = $this->createStub(SaluteSpeechRecognitionService::class);
        $recognition->method('recognize')->willThrowException(new SaluteSpeechException('Не удалось распознать'));

        $tester = $this->tester($recognition);
        $status = $tester->execute(['file' => 'voice.ogg']);

        self::assertSame(Command::FAILURE, $status);
    }
}
