<?php

namespace App\Command;

use App\Service\TelegramUpdateHandler;
use App\Service\TelegramBotService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telegram-bot:run',
    description: 'Запуск Telegram бота в режиме long polling',
)]
class TelegramBotRunCommand extends Command
{
    public function __construct(
        private readonly TelegramBotService $telegramBotService,
        private readonly TelegramUpdateHandler $updateHandler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Telegram бот запущен. Ожидание сообщений...');

        $offset = 0;

        while (true) {
            try {
                $updates = $this->telegramBotService->getUpdates($offset);

                foreach ($updates as $update) {
                    $updateId = $update['update_id'] ?? 0;
                    $offset = $updateId + 1;

                    try {
                        $this->updateHandler->handleUpdate($update);
                        $io->info(sprintf('Обработан update #%d', $updateId));
                    } catch (\Throwable $e) {
                        $io->error(sprintf('Ошибка обработки update #%d: %s', $updateId, $e->getMessage()));
                    }
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Ошибка получения updates: %s', $e->getMessage()));
                sleep(5);
            }
        }
    }
}
