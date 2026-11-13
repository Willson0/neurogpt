# NeuroGPT

Telegram Mini App для работы с нейросетями: картинки, видео, песни, фотосеты (текст будет в чате с ботом), баланс в токенах, подписки. Видео через kie.ai. Оплата через Telegram Stars и ЮKassa.

```
backend/     Laravel 13 (PHP 8.3): API, авторизация через Telegram, генерации, платежи
frontend/    Vue 3 (Options API) + Vite: сам Mini App
docker/      php-fpm, nginx и сборка фронтенда
docker-compose.yml, .env.example
```

## Как это работает

- **Вход.** Mini App отправляет `Authorization: tma <initData>` с каждым запросом. Бэкенд проверяет подпись (HMAC-SHA256 по токену бота), срок `auth_date` и находит или создаёт пользователя по `telegram_id`. Отдельных токенов сессии нет. Новому пользователю начисляется приветственный бонус, по ссылке `?start=friend_<id>` фиксируется реферал.
- **Токены.** Любое изменение баланса идёт через `Wallet` в одной транзакции с блокировкой строки и записью в журнал (`transactions`). Ошибка генерации возвращает токены автоматически.
- **Генерации.** `POST /api/generations` списывает токены и ставит задачу в очередь. Воркер (`queue`) вызывает провайдера, сохраняет файл, отправляет результат в чат с ботом. Приложение опрашивает статус и показывает результат.
- **Оплата ЮKassa.** `POST /api/payments/topup|plan` создаёт платёж и отдаёт ссылку, Mini App открывает её через `openLink`. Токены зачисляются при статусе `succeeded`: либо по webhook, либо когда приложение опрашивает платёж. Тело webhook не считается доверенным, статус всегда перечитывается из API ЮKassa, сумма сверяется, зачисление идемпотентно.
- **Telegram Stars.** Тот же `POST /api/payments/topup|plan` с `method=stars` создаёт счёт (`createInvoiceLink`, валюта XTR), Mini App открывает его через `openInvoice`. Вебхук бота отвечает на `pre_checkout_query` (сверяет сумму и статус) и зачисляет токены по `successful_payment`; повторная доставка не начисляет второй раз.
- **Интерфейс.** Палитра своя, от Telegram берётся только светлая или тёмная тема, поэтому приложение выглядит одинаково на телефоне и в Telegram Desktop. На широком экране контент центрируется, главная делится на две колонки, шторки становятся диалогами, ленты тянутся мышью и колесом. BackButton заменяет «Назад», MainButton и SecondaryButton заменяют нижние кнопки (в обычном браузере остаются обычные), есть haptic, `shareToStory`, `downloadFile`.
- **Безопасность.** Доверенные прокси ограничены приватными сетями, лимиты запросов на все группы маршрутов, загруженные фото перекодируются (EXIF и вложенный код отбрасываются), токен бота вычищается из логов, CORS ограничен `APP_URL`.

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

### 4. HTTPS

**Вариант А: на сервере уже стоит nginx (порт 8089).** Запускайте отдельный файл, он слушает только `127.0.0.1:8089`:

```bash
docker compose -f docker-compose.server.yml up -d --build
```

Затем добавьте сайт в nginx на хосте, пример лежит в `docker/nginx/host-site.conf.example`:

```bash
sudo cp docker/nginx/host-site.conf.example /etc/nginx/sites-available/neurogpt
sudo nano /etc/nginx/sites-available/neurogpt        # замените app.example.com на ваш домен
sudo ln -s /etc/nginx/sites-available/neurogpt /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.example.com              # выпустит HTTPS-сертификат
```

Дальше все команды `docker compose ...` из этой инструкции выполняйте с `-f docker-compose.server.yml`.

**Вариант Б: nginx на сервере нет.** В `.env` поставьте `WEB_PORT=8080`, запустите обычный `docker compose up -d` и поставьте Caddy, он сам выпустит сертификат:

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
2. Вебхук бота, команды и приём платежей звёздами:
   `docker compose exec app php artisan telegram:set-webhook`

   Команду нужно выполнить заново после этого обновления: вебхук теперь подписан на `pre_checkout_query`, без этого оплата Telegram Stars не пройдёт.

Реферальная ссылка имеет вид `https://t.me/<бот>?start=friend_<id>`. `TELEGRAM_BOT_USERNAME` можно писать с собакой или без, в ссылку она не попадёт. Старые ссылки `ref_<id>` продолжают работать.

### 6. Оплата

**Telegram Stars** включены по умолчанию (`TELEGRAM_STARS_ENABLED=true`), отдельная регистрация не нужна. Цена в звёздах считается из рублёвой: `рубли / TELEGRAM_STARS_RUB_RATE`, округление вверх. Курс по умолчанию 2 ₽ за звезду, проверьте его под свою экономику. Возврат делается через `/paysupport` в боте (ссылка из `SUPPORT_URL`).

**ЮKassa** (карты и СБП) включается, когда заполнены `YOOKASSA_SHOP_ID` и `YOOKASSA_SECRET_KEY`. В личном кабинете: Интеграция → HTTP-уведомления → адрес `https://app.example.com/api/payments/yookassa/webhook`, события `payment.succeeded` и `payment.canceled`. Если в магазине включены чеки по 54-ФЗ, поставьте `YOOKASSA_RECEIPT=true` и `YOOKASSA_RECEIPT_EMAIL`, при необходимости поправьте `YOOKASSA_TAX_SYSTEM` и `YOOKASSA_VAT_CODE`.

Если настроены оба способа, пользователь выбирает сам. Если только один, выбор не показывается.

### Обновление и обслуживание

```bash
git pull   # или залить новые файлы
docker compose up -d --build
docker compose logs --tail=100 queue scheduler
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

Тесты бэкенда: `cd backend && php artisan test` (авторизация, генерации, kie.ai, платежи, Stars).

## Провайдеры генерации

Каждая модель в таблице `ai_models` ссылается на драйвер, идентификатор модели у провайдера лежит в `provider_model`.

- `openai`: картинки, фотосеты и большинство инструментов раздела «Ещё». Подходит любой OpenAI-совместимый API. По умолчанию HydraAI (`OPENAI_BASE_URL=https://api.hydraai.ru/v1`, `OPENAI_IMAGE_EDIT=image_url`); для самого OpenAI: `OPENAI_BASE_URL=https://api.openai.com/v1`, `OPENAI_IMAGE_EDIT=multipart`. Этот же ключ используется для кнопки «Улучшить» и перевода запросов к видео.
- `kie`: видео и инструменты «Улучшить качество», «Убрать фон», «Оживить фото» через [kie.ai](https://kie.ai). Нужен только `KIE_API_KEY`. Без ключа видео выключено, а «Улучшить качество» и «Убрать фон» работают через модель картинок.
- `fake`: заглушки для разработки.

Видео-модели и их цены описаны в `backend/config/neurogpt.php` (раздел `video`): Seedance 2 Mini, Veo 3.1 Lite, Veo 3.1 Fast, Kling 3.0, Veo 3.1 Quality. Цены в токенах посчитаны от стоимости kie.ai с запасом, проверьте их перед запуском. Генерация видео идёт асинхронно: задача уходит в kie.ai, воркер опрашивает статус, результат скачивается в наше хранилище и отправляется пользователю в чат.

Инструменты раздела «Ещё» и их промпты лежат там же, в разделе `tools`; стили картинок в разделе `styles`.

После смены значений в `.env`: `docker compose up -d --force-recreate app queue scheduler` и `docker compose exec app php artisan db:seed --force`.

## Сервисы

- `app`: PHP-FPM с API.
- `queue`: воркер генераций (`--queue=high,default`, подписчики Pro идут в очередь `high`). Масштабируется: `docker compose up -d --scale queue=3`.
- `scheduler`: раз в две минуты возвращает токены за зависшие генерации, раз в час закрывает брошенные платежи. Должен работать ровно в одном экземпляре.
- `web`: nginx со статикой мини-аппа и файлами результатов.

## Админка

Раздел «Админка» появляется в профиле у администратора. Его Telegram id задан в `backend/config/neurogpt.php` (`admin_ids`, сейчас `1337592809`). Доступ проверяется на сервере при каждом запросе к `/api/admin/*`: остальные получают 403.

Что в ней есть:

- **Обзор**: пользователи, генерации, потраченные токены, оплаты, график за 14 дней.
- **Работы и промпты**: все картинки, видео, аудио, результаты инструментов и сообщения чата с промптами. Фильтры: тип, период (всё время, сегодня, 7 и 30 дней, свои даты), пользователь, текст промпта, статус.
- **Модели**: название, цена, тариф, плашка, провайдер, модель у провайдера, длительности и форматы видео. Можно добавлять и удалять.
- **Инструменты**: название, цена, промпт, варианты выбора, провайдер.
- **Фотосеты и новости**: тексты, промпты, обложки, порядок.
- **Цены и бонусы**: пакеты токенов, подписки, бонусы, курс Telegram Stars.
- **Настройки**: лимиты, вкладка «Песня», ссылка на поддержку, стили картинок.
- **Цвета**: цветовая гамма тёмной и светлой темы, готовые наборы, сброс к стандартной.
- **Пользователи**: поиск, начисление и списание токенов, подписка, блокировка, список платежей.

Изменения хранятся в базе (таблица `settings` и отметка `edited_at` у строк каталога) и имеют приоритет над `config/neurogpt.php` и `.env`. Сидер при перезапуске не перезаписывает то, что изменено или удалено в админке. Ключи API, токен бота и данные ЮKassa остаются только в `.env`.

**Вход с компьютера.** Откройте адрес приложения в обычном браузере: появится кнопка «Войти через Telegram». Чтобы она работала, один раз привяжите домен к боту: в @BotFather команда `/setdomain`, выберите бота и укажите домен приложения. Вход в браузере разрешён только администратору и действует 30 дней (`TELEGRAM_LOGIN_TTL`, в секундах).

## Цены и тарифы

Меняются в админке (разделы «Цены и бонусы» и «Модели»). Начальные значения лежат в `backend/config/neurogpt.php`: пакеты токенов, тарифы, бонусы, лимиты.

### Песни

Вкладка «Песня» пока закрыта заглушкой «Coming soon», а API отвечает `coming_soon`. Чтобы включить: впишите версию музыкальной модели Replicate в `REPLICATE_SONG_MODEL`, поставьте `FEATURE_SONGS=true` и выполните `php artisan db:seed --force`. Интерфейс песен в мини-аппе нужно будет доделать отдельно.
