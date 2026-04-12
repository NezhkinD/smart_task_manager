<?php

namespace App\Controller;

use App\Dto\Event\CreateEventRequestDto;
use App\Repository\EventRepository;
use App\Service\EventService;
use App\Service\GoogleCalendarLinkGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EventController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventRepository $eventRepository,
        private readonly GoogleCalendarLinkGenerator $googleCalendarLinkGenerator,
    ) {}

    #[Route('/api/event', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['errors' => ['Невалидный JSON']], Response::HTTP_BAD_REQUEST);
        }

        $dto = new CreateEventRequestDto();
        $dto->title = $data['title'] ?? '';
        $dto->body = $data['body'] ?? '';
        $dto->startAt = $data['startAt'] ?? '';
        $dto->finishAt = $data['finishAt'] ?? '';
        $dto->date = $data['date'] ?? '';
        $dto->type = $data['type'] ?? '';
        $dto->userId = (int) ($data['userId'] ?? 0);

        $result = $this->eventService->createEvent($dto);

        if (is_array($result)) {
            return new JsonResponse(['errors' => $result], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'id' => $result->id,
            'title' => $result->title,
            'body' => $result->body,
            'startAt' => $result->startAt->format('Y-m-d H:i:s'),
            'finishAt' => $result->finishAt->format('Y-m-d H:i:s'),
            'date' => $result->date->format('Y-m-d H:i:s'),
            'type' => $result->type->value,
            'userId' => $result->user->id,
            'createdAt' => $result->createdAt->format('Y-m-d H:i:s'),
            'updatedAt' => $result->updatedAt->format('Y-m-d H:i:s'),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/event/{id}/google-calendar-link', methods: ['GET'])]
    public function googleCalendarLink(int $id): JsonResponse
    {
        $event = $this->eventRepository->find($id);

        if ($event === null) {
            return new JsonResponse(['errors' => ['Событие не найдено']], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['link' => $this->googleCalendarLinkGenerator->generateLink($event)]);
    }
}
