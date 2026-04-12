<?php

namespace App\Service;

use App\Exception\SaluteSpeechException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SaluteSpeechTokenProvider
{
    private const OAUTH_URL = 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly string $saluteSpeechAuthorizationKey,
        private readonly string $saluteSpeechScope,
    ) {}

    public function getToken(): string
    {
        return $this->cache->get('salute_speech_access_token', function (ItemInterface $item): string {
            $response = $this->httpClient->request('POST', self::OAUTH_URL, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . $this->saluteSpeechAuthorizationKey,
                    'RqUID' => $this->generateUuid(),
                ],
                'body' => 'scope=' . $this->saluteSpeechScope,
                'verify_peer' => false,
                'verify_host' => false,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new SaluteSpeechException(
                    sprintf('Failed to obtain SaluteSpeech token. HTTP %d: %s', $statusCode, $response->getContent(false))
                );
            }

            $data = $response->toArray();

            if (!isset($data['access_token'], $data['expires_at'])) {
                throw new SaluteSpeechException('Invalid SaluteSpeech OAuth response: missing access_token or expires_at');
            }

            $expiresAt = (int) ($data['expires_at'] / 1000);
            $ttl = $expiresAt - time() - 60;

            if ($ttl > 0) {
                $item->expiresAfter($ttl);
            }

            return $data['access_token'];
        });
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }
}
