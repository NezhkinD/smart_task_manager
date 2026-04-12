<?php

namespace App\Service;

use App\Dto\SaluteSpeech\SpeechRecognitionResultDto;
use App\Enum\SaluteSpeech\AudioFormatEnum;
use App\Exception\SaluteSpeechException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SaluteSpeechRecognitionService
{
    private const RECOGNIZE_URL = 'https://smartspeech.sber.ru/rest/v1/speech:recognize';
    private const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2MB

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SaluteSpeechTokenProvider $tokenProvider,
    ) {}

    public function recognize(string $audioFilePath, AudioFormatEnum $format): SpeechRecognitionResultDto
    {
        $this->validateFile($audioFilePath);

        $token = $this->tokenProvider->getToken();
        $body = fopen($audioFilePath, 'r');

        if ($body === false) {
            throw new SaluteSpeechException(sprintf('Failed to open audio file: %s', $audioFilePath));
        }

        try {
            $response = $this->httpClient->request('POST', self::RECOGNIZE_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => $format->value,
                ],
                'body' => $body,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new SaluteSpeechException(
                    sprintf('SaluteSpeech recognition failed. HTTP %d: %s', $statusCode, $response->getContent(false))
                );
            }

            $data = $response->toArray();

            return new SpeechRecognitionResultDto(
                result: $data['result'] ?? [],
                emotions: $data['emotions'] ?? [],
                status: $data['status'] ?? $statusCode,
            );
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    private function validateFile(string $audioFilePath): void
    {
        if (!file_exists($audioFilePath)) {
            throw new SaluteSpeechException(sprintf('Audio file not found: %s', $audioFilePath));
        }

        if (!is_readable($audioFilePath)) {
            throw new SaluteSpeechException(sprintf('Audio file is not readable: %s', $audioFilePath));
        }

        $fileSize = filesize($audioFilePath);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            throw new SaluteSpeechException(
                sprintf('Audio file exceeds maximum size of %d bytes: %s', self::MAX_FILE_SIZE, $audioFilePath)
            );
        }
    }
}
