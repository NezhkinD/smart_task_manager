<?php

namespace App\Service;

use App\Entity\EventEntity;

class GoogleCalendarLinkGenerator
{
    private const BASE_URL = 'https://calendar.google.com/calendar/render';

    public function generateLink(EventEntity $event): string
    {
        $params = [
            'action' => 'TEMPLATE',
            'text' => rawurlencode($event->title),
            'dates' => $event->startAt->format('Ymd\THis') . '/' . $event->finishAt->format('Ymd\THis'),
            'details' => rawurlencode($event->body),
        ];

        $query = implode('&', array_map(
            fn(string $key, string $value) => "$key=$value",
            array_keys($params),
            array_values($params),
        ));

        return self::BASE_URL . '?' . $query;
    }
}
