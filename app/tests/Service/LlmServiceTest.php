<?php

namespace App\Tests\Service;

use App\Dto\Llm\ParsedEventDto;
use App\Exception\LlmException;
use App\Service\LlmService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LlmServiceTest extends TestCase
{
    private string $capturedSystemPrompt = '';

    /**
     * Собирает сервис с MockHttpClient: перехватывает системный промпт и возвращает заданный ответ.
     */
    private function makeService(MockResponse $response): LlmService
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $payload = json_decode($options['body'] ?? '{}', true);
            $this->capturedSystemPrompt = $payload['messages'][0]['content'] ?? '';

            return $response;
        });

        return new LlmService($client, 'test-key', 'https://llm.test/v1', 'test-model');
    }

    /**
     * Ответ LLM: $content кодируется в JSON и кладётся в choices[0].message.content.
     *
     * @param array<string, mixed> $content
     */
    private function llmResponse(array $content, int $status = 200): MockResponse
    {
        return new MockResponse(
            json_encode(['choices' => [['message' => ['content' => json_encode($content)]]]]),
            ['http_code' => $status],
        );
    }

    private function validEventContent(): array
    {
        return [
            'result' => true,
            'comment' => '',
            'title' => 'Стоматолог',
            'body' => 'Профилактический осмотр у стоматолога',
            'startAt' => '2026-06-24 09:00:00',
            'finishAt' => '2026-06-24 10:00:00',
            'date' => '2026-06-24 00:00:00',
        ];
    }

    public function testBuildsDateReferenceWithCorrectWeekdays(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE)); // вторник
        $service = $this->makeService($this->llmResponse($this->validEventContent()));

        $service->parseTextToEvent('Запиши меня к стоматологу завтра', $now);

        self::assertStringContainsString('Текущая дата и время: 2026-06-23 12:00:00', $this->capturedSystemPrompt);
        self::assertStringContainsString('Сегодня: вторник, 2026-06-23', $this->capturedSystemPrompt);
        self::assertStringContainsString('Завтра: среда, 2026-06-24', $this->capturedSystemPrompt);
        self::assertStringContainsString('Послезавтра: четверг, 2026-06-25', $this->capturedSystemPrompt);
        // Среда — это завтра.
        self::assertStringContainsString('ближайший(ее) среда: 2026-06-24', $this->capturedSystemPrompt);
        // Сегодня вторник → ближайший вторник берётся через неделю (правило +7).
        self::assertStringContainsString('ближайший(ее) вторник: 2026-06-30', $this->capturedSystemPrompt);
    }

    public function testParsesValidResponseIntoDto(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $service = $this->makeService($this->llmResponse($this->validEventContent()));

        $dto = $service->parseTextToEvent('Запиши меня к стоматологу завтра', $now);

        self::assertInstanceOf(ParsedEventDto::class, $dto);
        self::assertSame('Стоматолог', $dto->title);
        self::assertSame('Профилактический осмотр у стоматолога', $dto->body);
        self::assertSame('2026-06-24 09:00:00', $dto->startAt);
        self::assertSame('2026-06-24 10:00:00', $dto->finishAt);
        self::assertSame('2026-06-24 00:00:00', $dto->date);
    }

    public function testPoliteRefusalThrowsWithComment(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $comment = 'Я пока не умею заказывать пиццу, но могу поставить вам встречу в пиццерии 🍕';
        $service = $this->makeService($this->llmResponse(['result' => false, 'comment' => $comment]));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage($comment);

        $service->parseTextToEvent('Закажи пиццу домой', $now);
    }

    public function testRefusalWithoutCommentUsesDefaultMessage(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $service = $this->makeService($this->llmResponse(['result' => false]));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Не удалось извлечь событие из текста');

        $service->parseTextToEvent('что-то непонятное', $now);
    }

    public function testMissingRequiredFieldThrows(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $content = $this->validEventContent();
        unset($content['title']);
        $service = $this->makeService($this->llmResponse($content));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('title');

        $service->parseTextToEvent('текст', $now);
    }

    public function testInvalidDateFormatThrows(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $content = $this->validEventContent();
        $content['startAt'] = 'not-a-date';
        $service = $this->makeService($this->llmResponse($content));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('startAt');

        $service->parseTextToEvent('текст', $now);
    }

    public function testNon200ResponseThrows(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $service = $this->makeService(new MockResponse('server error', ['http_code' => 500]));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 500');

        $service->parseTextToEvent('текст', $now);
    }

    public function testNonJsonContentThrows(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $response = new MockResponse(
            json_encode(['choices' => [['message' => ['content' => 'это не json']]]]),
        );
        $service = $this->makeService($response);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Failed to parse LLM response as JSON');

        $service->parseTextToEvent('текст', $now);
    }

    public function testMissingContentThrows(): void
    {
        $now = new \DateTime('2026-06-23 12:00:00', new \DateTimeZone(LlmService::TIMEZONE));
        $service = $this->makeService(new MockResponse(json_encode(['choices' => [[]]])));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('does not contain expected content');

        $service->parseTextToEvent('текст', $now);
    }
}
