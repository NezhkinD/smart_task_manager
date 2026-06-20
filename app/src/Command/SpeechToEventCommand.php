<?php

namespace App\Command;

use App\Dto\Event\CreateEventRequestDto;
use App\Entity\EventEntity;
use App\Enum\SaluteSpeech\AudioFormatEnum;
use App\Exception\LlmException;
use App\Exception\SaluteSpeechException;
use App\Service\EventService;
use App\Service\LlmService;
use App\Service\SaluteSpeechRecognitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:speech-to-event',
    description: 'Распознавание речи и создание события через LLM',
)]
class SpeechToEventCommand extends Command
{
    protected const DIR_TO_FILES = __DIR__ . "/../../var/files/";

    public function __construct(
        private readonly SaluteSpeechRecognitionService $recognitionService,
        private readonly LlmService                     $llmService,
        private readonly EventService                   $eventService,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $formats = implode(', ', array_map(fn(AudioFormatEnum $f) => $f->name, AudioFormatEnum::cases()));

        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Путь к аудиофайлу')
            ->addArgument('userId', InputArgument::REQUIRED, 'ID пользователя')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, "Формат аудио: $formats", 'OPUS')
            ->addOption('text', 't', InputOption::VALUE_REQUIRED, 'Прямой ввод текста (минуя распознавание речи)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Не сохранять событие в БД');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $input->getArgument('file');
        $userId = (int)$input->getArgument('userId');
        $directText = $input->getOption('text');
        $dryRun = $input->getOption('dry-run');

        try {
            // Шаг 1: Получение текста
            if ($directText !== null) {
                $text = $directText;
                $io->info("Текст (прямой ввод): $text");
            } else {
                $formatName = $input->getOption('format');
                $format = AudioFormatEnum::tryFrom($formatName)
                    ?? (constant(AudioFormatEnum::class . '::' . $formatName) ?? null);

                if (!$format instanceof AudioFormatEnum) {
                    $io->error("Неизвестный формат: $formatName");
                    return Command::FAILURE;
                }

                $fullPath = self::DIR_TO_FILES . $filePath;
                $io->info("Файл: $fullPath");
                $io->info("Формат: {$format->name} ({$format->value})");

                $speechResult = $this->recognitionService->recognize($fullPath, $format);
                $text = $speechResult->getText();

                if (empty($text)) {
                    $io->error('Речь не распознана — пустой результат');
                    return Command::FAILURE;
                }

                $io->success("Распознанный текст: $text");
            }

            // Шаг 2: Парсинг текста через LLM
            $now = new \DateTime('now', new \DateTimeZone(LlmService::TIMEZONE));
            $io->info('Отправка в LLM (текущая дата: ' . $now->format('Y-m-d H:i:s') . ')...');

            $parsedEvent = $this->llmService->parseTextToEvent($text, $now);

            $io->success('LLM успешно распарсил событие');
            $io->table(
                ['Поле', 'Значение'],
                [
                    ['title', $parsedEvent->title],
                    ['body', $parsedEvent->body],
                    ['startAt', $parsedEvent->startAt],
                    ['finishAt', $parsedEvent->finishAt],
                    ['date', $parsedEvent->date],
                ]
            );

            // Шаг 3: Сохранение события
            if ($dryRun) {
                $io->warning('Режим dry-run — событие НЕ сохранено');
                return Command::SUCCESS;
            }

            $createDto = new CreateEventRequestDto();
            $createDto->title = $parsedEvent->title;
            $createDto->body = $parsedEvent->body;
            $createDto->startAt = $parsedEvent->startAt;
            $createDto->finishAt = $parsedEvent->finishAt;
            $createDto->date = $parsedEvent->date;
            $createDto->type = 'created';
            $createDto->userId = $userId;

            $result = $this->eventService->createEvent($createDto);

            if ($result instanceof EventEntity) {
                $io->success("Событие создано, ID: {$result->id}");
                return Command::SUCCESS;
            }

            $io->error('Ошибки при создании события:');
            foreach ($result as $error) {
                $io->writeln("  - $error");
            }

            return Command::FAILURE;
        } catch (SaluteSpeechException $e) {
            $io->error("Ошибка распознавания речи: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (LlmException $e) {
            $io->error("Ошибка LLM: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
