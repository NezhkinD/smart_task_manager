<?php

namespace App\Service;

use App\Dto\Llm\ParsedEventDto;
use App\Exception\LlmException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LlmService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $llmApiKey,
        private readonly string $llmBaseUrl,
        private readonly string $llmModel,
    ) {}

    public function parseTextToEvent(string $text, string $currentDateTime): ?ParsedEventDto
    {
        $systemPrompt = $this->buildSystemPrompt($currentDateTime);
        $responseData = $this->callApi($systemPrompt, $text);
        $json = $this->extractJsonFromResponse($responseData);

        return $this->mapToDto($json);
    }

    private function buildSystemPrompt(string $currentDateTime): string
    {
        return <<<PROMPT
Ты — ассистент для извлечения структурированных данных о событиях из текста на русском языке.

Текущая дата и время: {$currentDateTime}

Твоя задача — проанализировать текст и извлечь информацию о событии, вернув результат строго в формате JSON с полями:
- "result" (boolean) - результат раcпознования, если false, остальные поля можешь не возвращать
- "comment" (string) - если result=true, то пустой. Если result=false, то укажи что пользователь сделал не так. Например в аудиозаписи не содержится информация об мероприятии
- "title" (string) — краткое название события (3-7 слов)
- "body" (string) — полное описание события из текста
- "startAt" (string) — дата и время начала в формате "Y-m-d H:i:s"
- "finishAt" (string) — дата и время окончания в формате "Y-m-d H:i:s"
- "date" (string) — дата события в формате "Y-m-d H:i:s" (только дата, время 00:00:00)

Правила:
1. Если время начала не указано — используй 09:00:00
2. Если длительность не указана — событие длится 1 час
3. Разрешай относительные даты ("завтра", "послезавтра", "в понедельник") относительно текущей даты
4. Поле "date" содержит только дату начала события с временем 00:00:00
5. Верни ТОЛЬКО валидный JSON без дополнительного текста
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function callApi(string $systemPrompt, string $userText): array
    {
        $response = $this->httpClient->request('POST', rtrim($this->llmBaseUrl, '/') . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->llmApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->llmModel,
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.1,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userText],
                ],
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new LlmException(
                sprintf('LLM API request failed. HTTP %d: %s', $statusCode, $response->getContent(false))
            );
        }

        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $responseData
     * @return array<string, string>
     */
    private function extractJsonFromResponse(array $responseData): array
    {
        $content = $responseData['choices'][0]['message']['content'] ?? null;

        if ($content === null) {
            throw new LlmException('LLM response does not contain expected content');
        }

        $json = json_decode($content, true);

        if (!is_array($json)) {
            throw new LlmException(sprintf('Failed to parse LLM response as JSON: %s', $content));
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function mapToDto(array $json): ?ParsedEventDto
    {
        if (isset($json['result']) && $json['result'] === false) {
            $comment = $json['comment'] ?? 'Не удалось извлечь событие из текста';
            throw new LlmException($comment);
        }

        $requiredFields = ['title', 'body', 'startAt', 'finishAt', 'date'];
        foreach ($requiredFields as $field) {
            if (empty($json[$field])) {
                throw new LlmException(sprintf('LLM response missing required field: %s', $field));
            }
        }

        $dateFormat = 'Y-m-d H:i:s';
        foreach (['startAt', 'finishAt', 'date'] as $dateField) {
            $parsed = \DateTime::createFromFormat($dateFormat, $json[$dateField]);
            if ($parsed === false) {
                throw new LlmException(
                    sprintf('Invalid date format for field "%s": %s (expected %s)', $dateField, $json[$dateField], $dateFormat)
                );
            }
        }

        return new ParsedEventDto(
            title: $json['title'],
            body: $json['body'],
            startAt: $json['startAt'],
            finishAt: $json['finishAt'],
            date: $json['date'],
        );
    }
}
