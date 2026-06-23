<?php

namespace App\Tests\Service;

use App\Entity\EventEntity;
use App\Service\GoogleCalendarLinkGenerator;
use PHPUnit\Framework\TestCase;

class GoogleCalendarLinkGeneratorTest extends TestCase
{
    public function testGenerateLinkBuildsExpectedUrl(): void
    {
        $event = new EventEntity();
        $event->title = 'Встреча с командой';
        $event->body = 'Обсуждение планов на квартал';
        $event->startAt = new \DateTime('2026-06-23 15:30:00');
        $event->finishAt = new \DateTime('2026-06-23 16:30:00');

        $link = (new GoogleCalendarLinkGenerator())->generateLink($event);

        self::assertStringStartsWith('https://calendar.google.com/calendar/render?', $link);
        self::assertStringContainsString('action=TEMPLATE', $link);
        self::assertStringContainsString('dates=20260623T153000/20260623T163000', $link);
        self::assertStringContainsString('text=' . rawurlencode('Встреча с командой'), $link);
        self::assertStringContainsString('details=' . rawurlencode('Обсуждение планов на квартал'), $link);
    }

    public function testGenerateLinkEncodesSpecialCharacters(): void
    {
        $event = new EventEntity();
        $event->title = 'A & B';
        $event->body = 'x=1?y=2';
        $event->startAt = new \DateTime('2026-01-01 09:00:00');
        $event->finishAt = new \DateTime('2026-01-01 10:00:00');

        $link = (new GoogleCalendarLinkGenerator())->generateLink($event);

        // Спецсимволы должны быть закодированы, а не попасть в query как разделители.
        self::assertStringContainsString('text=A%20%26%20B', $link);
        self::assertStringContainsString('details=x%3D1%3Fy%3D2', $link);
    }
}
