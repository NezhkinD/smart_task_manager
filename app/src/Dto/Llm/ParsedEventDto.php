<?php

namespace App\Dto\Llm;

class ParsedEventDto
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $startAt,
        public readonly string $finishAt,
        public readonly string $date,
    ) {}
}
