<?php

namespace App\Tests\Controller;

use App\Controller\EventController;
use App\Entity\EventEntity;
use App\Entity\UserEntity;
use App\Enum\Event\TypeEnum;
use App\Repository\EventRepository;
use App\Service\EventService;
use App\Service\GoogleCalendarLinkGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EventControllerTest extends TestCase
{
    private function controller(
        ?EventService $eventService = null,
        ?EventRepository $eventRepository = null,
        ?GoogleCalendarLinkGenerator $linkGenerator = null,
    ): EventController {
        return new EventController(
            $eventService ?? $this->createStub(EventService::class),
            $eventRepository ?? $this->createStub(EventRepository::class),
            $linkGenerator ?? $this->createStub(GoogleCalendarLinkGenerator::class),
        );
    }

    private function jsonRequest(string $content): Request
    {
        return Request::create('/api/event', 'POST', [], [], [], [], $content);
    }

    private function makeEvent(): EventEntity
    {
        $user = new UserEntity();
        $user->id = 7;

        $event = new EventEntity();
        $event->id = 1;
        $event->title = 'Стоматолог';
        $event->body = 'Осмотр';
        $event->startAt = new \DateTime('2026-06-24 09:00:00');
        $event->finishAt = new \DateTime('2026-06-24 10:00:00');
        $event->date = new \DateTime('2026-06-24 00:00:00');
        $event->type = TypeEnum::CREATED;
        $event->user = $user;
        $event->createdAt = new \DateTime('2026-06-23 12:00:00');
        $event->updatedAt = new \DateTime('2026-06-23 12:00:00');

        return $event;
    }

    public function testCreateReturnsBadRequestOnInvalidJson(): void
    {
        $response = $this->controller()->create($this->jsonRequest('not-json'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCreateReturnsValidationErrors(): void
    {
        $eventService = $this->createStub(EventService::class);
        $eventService->method('createEvent')->willReturn(['title: Это поле обязательно']);

        $response = $this->controller($eventService)->create($this->jsonRequest('{"title":""}'));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(['errors' => ['title: Это поле обязательно']], json_decode($response->getContent(), true));
    }

    public function testCreateReturnsCreatedEvent(): void
    {
        $eventService = $this->createStub(EventService::class);
        $eventService->method('createEvent')->willReturn($this->makeEvent());

        $response = $this->controller($eventService)->create($this->jsonRequest('{"title":"Стоматолог"}'));

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame(1, $body['id']);
        self::assertSame('Стоматолог', $body['title']);
        self::assertSame('created', $body['type']);
        self::assertSame(7, $body['userId']);
        self::assertSame('2026-06-24 09:00:00', $body['startAt']);
    }

    public function testGoogleCalendarLinkReturnsNotFound(): void
    {
        $eventRepository = $this->createStub(EventRepository::class);
        $eventRepository->method('find')->willReturn(null);

        $response = $this->controller(null, $eventRepository)->googleCalendarLink(999);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGoogleCalendarLinkReturnsLink(): void
    {
        $eventRepository = $this->createStub(EventRepository::class);
        $eventRepository->method('find')->willReturn($this->makeEvent());

        $linkGenerator = $this->createStub(GoogleCalendarLinkGenerator::class);
        $linkGenerator->method('generateLink')->willReturn('https://calendar.google.com/render?x=1');

        $response = $this->controller(null, $eventRepository, $linkGenerator)->googleCalendarLink(1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            ['link' => 'https://calendar.google.com/render?x=1'],
            json_decode($response->getContent(), true),
        );
    }
}
