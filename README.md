# Smart Task Manager

Система управления задачами и событиями с интеграцией Telegram-бота. Пользователь отправляет
боту голосовое или текстовое сообщение, система распознаёт речь, разбирает естественный язык
с помощью LLM и создаёт событие/задачу, в том числе со ссылкой на Google Calendar.

**Поток обработки:** голосовое сообщение в Telegram → распознавание речи (Salute Speech) →
разбор естественного языка через LLM (Qwen-Max, Aliyun) → создание события/задачи →
генерация ссылки на Google Calendar.

## Возможности

- **Telegram-бот** в режиме long polling — приём и обработка сообщений пользователей.
- **Голосовой ввод** — преобразование голосовых сообщений в текст через Salute Speech.
- **AI-разбор** — извлечение деталей события/задачи из естественного языка через LLM (Qwen-Max).
- **События и задачи** — создание и хранение с заголовком, описанием, датами и типом.
- **Google Calendar** — генерация ссылки на добавление события в календарь.
- **Мультипользовательность** — пользователи с ролями (admin / user) и хранение токенов интеграций.

## Стек

- PHP 8.4
- Symfony 7.4
- Doctrine ORM 3.x + Doctrine Migrations
- PostgreSQL 17.6
- PHPUnit 13.2
- Docker / Nginx

## Структура проекта

```
.
├── app/                      # Symfony-приложение
│   ├── src/
│   │   ├── Controller/       # HTTP-эндпоинты (EventController)
│   │   ├── Entity/           # Doctrine-сущности
│   │   ├── Repository/       # Репозитории
│   │   ├── Service/          # Бизнес-логика (Telegram, LLM, Speech, Calendar)
│   │   ├── Command/          # CLI-команды
│   │   ├── Dto/ Enum/ Exception/
│   │   └── Kernel.php
│   ├── config/               # Конфигурация Symfony
│   ├── migrations/           # Миграции Doctrine
│   ├── tests/                # Тесты PHPUnit
│   └── composer.json
├── .docker/                  # Dockerfile'ы и конфиги (php, nginx, supervisor)
├── .github/workflows/        # CI (tests.yml)
├── docker-compose.yaml       # Production
├── docker-compose-dev.yaml   # Development
└── Makefile                  # Команды сборки и запуска
```

## Требования

- Docker и Docker Compose
- GNU Make

## Установка и запуск (dev)

1. Заполните переменные окружения в `app/.env`:

   | Переменная | Назначение |
   |---|---|
   | `APP_ENV`, `APP_SECRET` | Окружение и секрет Symfony |
   | `DATABASE_URL` | Строка подключения к PostgreSQL |
   | `TG_BOT_TOKEN` | Токен Telegram-бота |
   | `LLM_API_KEY`, `LLM_BASE_URL`, `LLM_MODEL` | Доступ к LLM (Qwen-Max, Aliyun) |
   | `SALUTE_SPEECH_CLIENT_ID`, `SALUTE_SPEECH_CLIENT_SECRET`, `SALUTE_SPEECH_SCOPE`, `SALUTE_SPEECH_AUTHORIZATION_KEY` | Доступ к Salute Speech |

   > Не коммитьте реальные секреты в репозиторий.

2. Поднимите контейнеры:

   ```bash
   make dev-run
   ```

   После запуска доступны:
   - приложение (Nginx) — `http://localhost:1242`
   - PostgreSQL — `localhost:6432`

3. Примените миграции:

   ```bash
   make doctrine_migrate
   ```

4. Остановить окружение:

   ```bash
   make dev-down
   ```

## CLI-команды

Выполняются внутри контейнера `stm_php` (`docker exec -it stm_php php bin/console <команда>`):

- `app:telegram-bot:run` — запуск Telegram-бота в режиме long polling.
- `app:speech-to-event <file> <userId> [-f|--format OPUS] [-t|--text <текст>] [--dry-run]` —
  распознавание аудио и создание события для пользователя. `--text` минует распознавание речи,
  `--dry-run` не сохраняет событие в БД.
- `app:salute-speech:recognize <file> [-f|--format OPUS]` — распознавание речи через Salute Speech.

## API

| Метод | Маршрут | Описание | Коды ответа |
|---|---|---|---|
| `POST` | `/api/event` | Создать событие. Тело: `title`, `body`, `startAt`, `finishAt`, `date`, `type`, `userId` | `201` / `422` |
| `GET` | `/api/event/{id}/google-calendar-link` | Ссылка на добавление события в Google Calendar | `200` / `404` |

## Тесты

```bash
make test       # запуск PHPUnit
make coverage   # запуск с отчётом о покрытии
```

Тесты находятся в `app/tests/`.

## CI

GitHub Actions (`.github/workflows/tests.yml`) запускается на push в ветку `main` и на pull request:
устанавливает зависимости, запускает PHPUnit на PHP 8.4 и проверяет порог покрытия кода (≥ 80%).

## Сборка и деплой (prod)

```bash
make build      # сборка production-образов php и nginx
make push       # публикация образов в registry
make release    # build + push
```

Реестр и тег настраиваются через переменные `DOCKER_REGISTRY` и `TAG` (см. `Makefile`).
