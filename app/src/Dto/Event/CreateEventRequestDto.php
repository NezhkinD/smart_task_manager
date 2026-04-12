<?php

namespace App\Dto\Event;

use Symfony\Component\Validator\Constraints as Assert;

class CreateEventRequestDto
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $title;

    #[Assert\NotBlank]
    public string $body;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    public string $startAt;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    public string $finishAt;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'Y-m-d H:i:s')]
    public string $date;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['created', 'failed'])]
    public string $type;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public int $userId;
}
