<?php

namespace App\Dto\SaluteSpeech;

class SpeechRecognitionResultDto
{
    /**
     * @param string[] $result
     * @param array<mixed> $emotions
     */
    public function __construct(
        public readonly array $result,
        public readonly array $emotions,
        public readonly int $status,
    ) {}

    public function getText(): string
    {
        return $this->result[0] ?? '';
    }
}
