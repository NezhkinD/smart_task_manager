<?php

namespace App\Tests\Service;

use App\Exception\SaluteSpeechException;
use App\Service\SaluteSpeechTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SaluteSpeechTokenProviderTest extends TestCase
{
    public function testReturnsTokenAndCachesIt(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'access_token' => 'abc123',
            'expires_at' => (time() + 3600) * 1000,
        ])));
        $provider = new SaluteSpeechTokenProvider($client, new ArrayAdapter(), 'auth-key', 'SCOPE');

        self::assertSame('abc123', $provider->getToken());
        // Второй вызов берётся из кэша — новый HTTP-запрос не уходит.
        self::assertSame('abc123', $provider->getToken());
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testThrowsOnNon200(): void
    {
        $client = new MockHttpClient(new MockResponse('denied', ['http_code' => 401]));
        $provider = new SaluteSpeechTokenProvider($client, new ArrayAdapter(), 'auth-key', 'SCOPE');

        $this->expectException(SaluteSpeechException::class);

        $provider->getToken();
    }

    public function testThrowsWhenResponseMissingFields(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['foo' => 'bar'])));
        $provider = new SaluteSpeechTokenProvider($client, new ArrayAdapter(), 'auth-key', 'SCOPE');

        $this->expectException(SaluteSpeechException::class);

        $provider->getToken();
    }
}
