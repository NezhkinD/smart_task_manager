<?php

namespace App\Command;

use App\Enum\SaluteSpeech\AudioFormatEnum;
use App\Exception\SaluteSpeechException;
use App\Service\SaluteSpeechRecognitionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:salute-speech:recognize',
    description: 'Распознавание речи из аудиофайла через SaluteSpeech API',
)]
class SaluteSpeechRecognizeCommand extends Command
{
    public function __construct(
        private readonly SaluteSpeechRecognitionService $recognitionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $formats = implode(', ', array_map(fn (AudioFormatEnum $f) => $f->name, AudioFormatEnum::cases()));

        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Путь к аудиофайлу')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, "Формат аудио: $formats", 'OPUS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $input->getArgument('file');
        $formatName = $input->getOption('format');

        $format = AudioFormatEnum::tryFromNameOrValue($formatName);

        if ($format === null) {
            $io->error("Неизвестный формат: $formatName");
            return Command::FAILURE;
        }

        $io->info("Файл: $filePath");
        $io->info("Формат: {$format->name} ({$format->value})");

        $fullPath = __DIR__ . "/../../var/files/{$filePath}";
        try {
            $result = $this->recognitionService->recognize($fullPath, $format);

            $io->success('Распознавание завершено');
            $io->writeln("<info>Текст:</info> {$result->getText()}");
            $io->writeln("<info>Статус:</info> {$result->status}");

            if (!empty($result->result)) {
                $io->section('Все результаты');
                foreach ($result->result as $i => $text) {
                    $io->writeln("  [$i] $text");
                }
            }

            if (!empty($result->emotions)) {
                $io->section('Эмоции');
                $io->writeln(json_encode($result->emotions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
        } catch (SaluteSpeechException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
