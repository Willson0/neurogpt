# NeuroGPT

Telegram Mini App для работы с нейросетями: текст, картинки, видео, фотосеты, баланс в токенах, подписки. Оплата через ЮKassa.

```
backend/     Laravel 13 (PHP 8.3): API, авторизация через Telegram, генерации, платежи
frontend/    Vue 3 (Options API) + Vite: сам Mini App
docker/      php-fpm, nginx и сборка фронтенда
docker-compose.yml, .env.example
scripts/     api_smoke_test.py: сквозная проверка API
```

## Как это работает

- **Вход.** Mini App отправляет `Authorization: tma <initData>` с каждым запросом. Бэкенд проверяет подпись (HMAC-SHA256 по токену бота), срок `auth_date` и находит или создаёт пользователя по `telegram_id`. Отдельных токенов сессии нет. Новому пользователю начисляется приветственный бонус, по ссылке `?start=ref_<id>` фиксируется реферал.
- **Токены.** Любое изменение баланса идёт через `Wallet` в одной транзакции с блокировкой строки и записью в журнал (`transactions`). Ошибка генерации возвращает токены автоматически.
- **Генерации.** `POST /api/generations` списывает токены и ставит задачу в очередь. Воркер (`queue`) вызывает провайдера, сохраняет файл, отправляет результат в чат с ботом. Приложение опрашивает статус и показывает результат.
- **Оплата ЮKassa.** `POST /api/payments/topup|plan` создаёт платёж и отдаёт ссылку, Mini App открывает её через `openLink`. Токены зачисляются при статусе `succeeded`: либо по webhook, либо когда приложение опрашивает платёж. Тело webhook не считается доверенным, статус всегда перечитывается из API ЮKassa, сумма сверяется, зачисление идемпотентно.
- **Telegram-интерфейс.** Цвета берутся из темы Telegram, кнопка BackButton заменяет «Назад», MainButton и SecondaryButton заменяют нижние кнопки (в обычном браузере остаются обычные), учтены безопасные зоны, есть haptic, `shareToStory`, `downloadFile`.

## Запуск на сервере (Docker)

Нужен сервер с Ubuntu 22.04/24.04, домен, направленный на его IP, и открытые порты 80 и 443. Telegram открывает Mini App только по HTTPS.

### 1. Установить Docker

```bash
sudo apt update && sudo apt install -y ca-certificates curl git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER      # затем выйти и зайти по SSH заново
docker --version && docker compose version
```

### 2. Забрать проект и заполнить настройки

```bash
# с мака, из папки Документы/projects:
scp -r NeuroGPT user@SERVER_IP:~/NeuroGPT
# на сервере:
cd ~/NeuroGPT
cp .env.example .env
nano .env
```

В `.env` заполните:

| Переменная | Откуда взять |
| --- | --- |
| `APP_URL`, `TELEGRAM_WEBAPP_URL`, `YOOKASSA_RETURN_URL` | ваш домен с `https://` |
| `APP_KEY` | `docker compose run --rm --entrypoint php app artisan key:generate --show` (после первой сборки, см. шаг 3) |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | придумайте |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` | @BotFather |
| `TELEGRAM_WEBHOOK_SECRET` | любая длинная случайная строка: `openssl rand -hex 24` |
| `YOOKASSA_SHOP_ID`, `YOOKASSA_SECRET_KEY` | личный кабинет ЮKassa, раздел «Интеграция» |
| `OPENAI_API_KEY` (и `GENERATION_DRIVER`) | ключ провайдера, см. раздел «Провайдеры» |

### 3. Собрать и запустить

```bash
docker compose build
docker compose run --rm --entrypoint php app artisan key:generate --show   # вставьте результат в APP_KEY
docker compose up -d
docker compose ps
docker compose logs -f app queue
```

При старте контейнер сам применяет миграции и заполняет каталог (модели, фотосеты, новости).

### 4. HTTPS через Caddy (сертификат выпускается сам)

В `.env` поставьте `WEB_PORT=8080`, затем `docker compose up -d`. Установите Caddy на хост:

```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy
echo 'app.example.com { reverse_proxy 127.0.0.1:8080 }' | sudo tee /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

Проверка: `https://app.example.com/up` отвечает 200.

### 5. Подключить Telegram

1. В @BotFather: `/newapp` (или Bot Settings → Menu Button) и укажите адрес `https://app.example.com`.
2. Вебхук бота (команда `/start` с кнопкой открытия и рефералы):
   `docker compose exec app php artisan telegram:set-webhook`

### 6. Подключить ЮKassa

В личном кабинете: Интеграция → HTTP-уведомления → адрес `https://app.example.com/api/payments/yookassa/webhook`, события `payment.succeeded` и `payment.canceled`. Если в магазине включены чеки по 54-ФЗ, поставьте `YOOKASSA_RECEIPT=true` и `YOOKASSA_RECEIPT_EMAIL` (email, на который уйдут чеки), при необходимости поправьте `YOOKASSA_TAX_SYSTEM` и `YOOKASSA_VAT_CODE`.

### Обновление и обслуживание

```bash
git pull   # или залить новые файлы
docker compose up -d --build
docker compose logs --tail=100 queue
docker compose exec db sh -c 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > backup.sql
```

## Локальная разработка

```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
php artisan serve &  php artisan queue:work &
cd ../frontend && npm install && npm run dev
```

В `backend/.env` поставьте `TELEGRAM_ALLOW_DEV_AUTH=true` (работает только при `APP_ENV=local`), а во `frontend/.env` — `VITE_DEV_TELEGRAM_ID=1`, тогда приложение открывается в обычном браузере. Драйвер `fake` (по умолчанию) рисует заглушки без сети.

Проверка API целиком: `python3 scripts/api_smoke_test.py` (нужны токен бота `123456:TEST-TOKEN` в `backend/.env`, поднятые сервер и воркер и мок ЮKassa, см. комментарии в скрипте).

## Провайдеры генерации

Каждая модель в таблице `ai_models` ссылается на драйвер: `openai` (любой OpenAI-совместимый API: OpenAI, OpenRouter, свой шлюз), `replicate` (видео и инструменты), `fake`. Идентификатор модели на стороне провайдера лежит в `provider_model`. Сидер заполняет их значениями по умолчанию (`gpt-4o-mini`, `gpt-4o`, `gpt-image-1`), замените на нужные в `database/seeders/DatabaseSeeder.php` или прямо в таблице. Видео и инструменты («Улучшить качество», «Убрать фон», «Оживить фото») работают через `replicate`: впишите версию модели в `provider_model` и поставьте `driver = replicate`; инструменты в `ai_models` с `kind = tool` по умолчанию выключены.

## Цены и тарифы

Задаются в `backend/config/neurogpt.php`: пакеты токенов, тарифы, бонусы. Цены в рублях взяты из прототипа как «звёзды × 2», при необходимости поменяйте. Цены моделей в токенах лежат в `ai_models.price`.
