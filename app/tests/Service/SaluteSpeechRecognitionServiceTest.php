<?php

namespace App\Tests\Service;

use App\Dto\SaluteSpeech\SpeechRecognitionResultDto;
use App\Enum\SaluteSpeech\AudioFormatEnum;
use App\Exception\SaluteSpeechException;
use App\Service\SaluteSpeechRecognitionService;
use App\Service\SaluteSpeechTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SaluteSpeechRecognitionServiceTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function tokenProvider(): SaluteSpeechTokenProvider
    {
        $provider = $this->createStub(SaluteSpeechTokenProvider::class);
        $provider->method('getToken')->willReturn('tok');

        return $provider;
    }

    private function tempFile(int $size, string $content = 'audio'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aud');
        $this->tempFiles[] = $path;

        if ($size > 0) {
            $fp = fopen($path, 'w');
            ftruncate($fp, $size);
            fclose($fp);
        } else {
            file_put_contents($path, $content);
        }

        return $path;
    }

    public function testThrowsWhenFileNotFound(): void
    {
        $service = new SaluteSpeechRecognitionService(new MockHttpClient(), $this->tokenProvider());

        $this->expectException(SaluteSpeechException::class);

        $service->recognize('/no/such/file.ogg', AudioFormatEnum::OPUS);
    }

    public function testThrowsWhenFileTooLarge(): void
    {
        $path = $this->tempFile(2 * 1024 * 1024 + 1);
        $service = new SaluteSpeechRecognitionService(new MockHttpClient(), $this->tokenProvider());

        $this->expectException(SaluteSpeechException::class);

        $service->recognize($path, AudioFormatEnum::OPUS);
    }

    public function testRecognizesSuccessfully(): void
    {
        $path = $this->tempFile(0, 'small-audio');
        $client = new MockHttpClient(new MockResponse(json_encode([
            'result' => ['Купи молоко'],
            'emotions' => [['positive' => 0.9]],
            'status' => 200,
        ])));
        $service = new SaluteSpeechRecognitionService($client, $this->tokenProvider());

        $dto = $service->recognize($path, AudioFormatEnum::OPUS);

        self::assertInstanceOf(SpeechRecognitionResultDto::class, $dto);
        self::assertSame('Купи молоко', $dto->getText());
        self::assertSame(200, $dto->status);
        self::assertSame([['positive' => 0.9]], $dto->emotions);
    }

    public function testThrowsOnNon200(): void
    {
        $path = $this->tempFile(0, 'small-audio');
        $client = new MockHttpClient(new MockResponse('error', ['http_code' => 401]));
        $service = new SaluteSpeechRecognitionService($client, $this->tokenProvider());

        $this->expectException(SaluteSpeechException::class);

        $service->recognize($path, AudioFormatEnum::OPUS);
    }
}
