<?php

namespace App\Service;

use App\Dto\Event\CreateEventRequestDto;
use App\Entity\EventEntity;
use App\Enum\Event\TypeEnum;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EventService
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * @return EventEntity|array<int, string>
     */
    public function createEvent(CreateEventRequestDto $dto): EventEntity|array
    {
        $violations = $this->validator->validate($dto);
        if ($violations->count() > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
            }
            return $errors;
        }

        $timezone = new \DateTimeZone(LlmService::TIMEZONE);
        $startAt = new \DateTime($dto->startAt, $timezone);
        $finishAt = new \DateTime($dto->finishAt, $timezone);

        if ($finishAt <= $startAt) {
            return ['finishAt: Дата окончания должна быть позже даты начала'];
        }

        $user = $this->userRepository->find($dto->userId);
        if ($user === null) {
            return ['userId: Пользователь не найден'];
        }

        $event = new EventEntity();
        $event->title = $dto->title;
        $event->body = $dto->body;
        $event->startAt = $startAt;
        $event->finishAt = $finishAt;
        $event->date = new \DateTime($dto->date, $timezone);
        $event->type = TypeEnum::from($dto->type);
        $event->user = $user;
        $event->createdAt = new \DateTime();
        $event->updatedAt = new \DateTime();

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }
}
