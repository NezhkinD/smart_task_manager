<?php

namespace App\Tests\Service;

use App\Exception\TelegramBotException;
use App\Service\TelegramBotService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class TelegramBotServiceTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    is_dir($f) ? @rmdir($f) : @unlink($f);
                }
                @rmdir($dir);
            }
        }
    }

    private function service(MockHttpClient $client): TelegramBotService
    {
        return new TelegramBotService($client, 'TOKEN');
    }

    public function testGetUpdatesReturnsResult(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['result' => [['update_id' => 1]]])));

        self::assertSame([['update_id' => 1]], $this->service($client)->getUpdates(0));
    }

    public function testGetUpdatesReturnsEmptyArrayWhenNoResult(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['ok' => true])));

        self::assertSame([], $this->service($client)->getUpdates(0));
    }

    public function testSendMessagePostsExpectedPayload(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'] ?? '{}', true)];

            return new MockResponse(json_encode(['ok' => true]));
        });

        $this->service($client)->sendMessage(123, 'Привет');

        self::assertSame('POST', $captured['method']);
        self::assertStringContainsString('/botTOKEN/sendMessage', $captured['url']);
        self::assertSame(123, $captured['body']['chat_id']);
        self::assertSame('Привет', $captured['body']['text']);
        self::assertSame('HTML', $captured['body']['parse_mode']);
    }

    public function testGetFileReturnsFilePath(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['result' => ['file_path' => 'voice/file_1.ogg']])));

        self::assertSame('voice/file_1.ogg', $this->service($client)->getFile('file-id'));
    }

    public function testGetFileThrowsWhenNoFilePath(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['result' => []])));

        $this->expectException(TelegramBotException::class);

        $this->service($client)->getFile('file-id');
    }

    public function testRequestThrowsOnNon200(): void
    {
        $client = new MockHttpClient(new MockResponse('boom', ['http_code' => 500]));

        $this->expectException(TelegramBotException::class);

        $this->service($client)->getUpdates(0);
    }

    public function testDownloadFileWritesContentAndCreatesDir(): void
    {
        $client = new MockHttpClient(new MockResponse('binary-bytes'));
        $dir = sys_get_temp_dir() . '/tgbot_' . uniqid();
        $this->tempDirs[] = $dir;
        $localPath = $dir . '/voice.ogg';

        $this->service($client)->downloadFile('voice/file_1.ogg', $localPath);

        self::assertFileExists($localPath);
        self::assertSame('binary-bytes', file_get_contents($localPath));
    }

    public function testDownloadFileThrowsOnNon200(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->expectException(TelegramBotException::class);

        $this->service($client)->downloadFile('voice/file_1.ogg', sys_get_temp_dir() . '/never_written.ogg');
    }
}
