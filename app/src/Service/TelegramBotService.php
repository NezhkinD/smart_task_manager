<?php

namespace App\Service;

use App\Exception\TelegramBotException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramBotService
{
    private const API_BASE_URL = 'https://api.telegram.org/bot';
    private const FILE_BASE_URL = 'https://api.telegram.org/file/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $tgBotToken,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset, int $timeout = 30): array
    {
        $data = $this->request('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message'],
        ]);

        return $data['result'] ?? [];
    }

    public function sendMessage(int|string $chatId, string $text, string $parseMode = 'HTML'): void
    {
        $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ]);
    }

    public function getFile(string $fileId): string
    {
        $data = $this->request('getFile', [
            'file_id' => $fileId,
        ]);

        $filePath = $data['result']['file_path'] ?? null;
        if ($filePath === null) {
            throw new TelegramBotException('Telegram API did not return file_path');
        }

        return $filePath;
    }

    public function downloadFile(string $telegramFilePath, string $localPath): void
    {
        $url = self::FILE_BASE_URL . $this->tgBotToken . '/' . $telegramFilePath;

        $response = $this->httpClient->request('GET', $url);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new TelegramBotException(
                sprintf('Failed to download file. HTTP %d', $statusCode)
            );
        }

        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $written = file_put_contents($localPath, $response->getContent());
        if ($written === false) {
            throw new TelegramBotException(sprintf('Failed to write file: %s', $localPath));
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = []): array
    {
        $url = self::API_BASE_URL . $this->tgBotToken . '/' . $method;

        $response = $this->httpClient->request('POST', $url, [
            'json' => $params,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new TelegramBotException(
                sprintf('Telegram API request "%s" failed. HTTP %d: %s', $method, $statusCode, $response->getContent(false))
            );
        }

        return $response->toArray();
    }
}
