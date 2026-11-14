# NeuroGPT

Telegram Mini App для работы с нейросетями: картинки, видео, песни, фотосеты (текст будет в чате с ботом), баланс в токенах, подписки. Видео через kie.ai. Оплата через Telegram Stars и ЮKassa.

```
backend/     Laravel 13 (PHP 8.3): API, авторизация через Telegram, генерации, платежи
frontend/    Vue 3 (Options API) + Vite: сам Mini App
docker/      php-fpm, nginx и сборка фронтенда
docker-compose.yml, .env.example
```

## Как это работает

- **База общая с ботом.** Приложение работает прямо на таблицах Telegram-бота из его `models.py` (MySQL) и ничего в их структуре не меняет; свои данные хранит в новых таблицах `wa_*`. Подробно в разделе «База данных бота».
- **Вход.** Mini App отправляет `Authorization: tma <initData>` с каждым запросом. Бэкенд проверяет подпись (HMAC-SHA256 по токену бота), срок `auth_date` и находит пользователя бота по `users.id` (= Telegram id) или создаёт его. Отдельных токенов сессии нет. Новому пользователю начисляется приветственный бонус, по ссылке `?start=friend_<id>` фиксируется реферал.
- **Токены.** Баланс = счётчики бота `tokens` + `sub_tokens` (пока активна подписка) + `temp_tokens` (до `temp_tokens_until`). Любое изменение идёт через `Wallet` в одной транзакции с блокировкой строки `users` и записью в журнал (`wa_transactions`). Ошибка генерации возвращает токены автоматически.
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
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | база бота (MySQL). Для удалённой базы уберите строку `COMPOSE_PROFILES=local-db`. Без `DB_HOST` поднимается локальный MySQL в контейнере |
| `DB_ROOT_PASSWORD` | только для локального контейнера MySQL |
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

Ссылки на конкретный экран (профиль, подписка, модель, фотосет, раздел админки): `?startapp=<экран>` в URL web_app кнопки или `https://t.me/<бот>/<short_name>?startapp=<экран>`. Полный список экранов и куда какую ссылку вставлять: `NeuroGPT-docs/bot-links.md`, готовые ссылки с вашим доменом печатает `php artisan telegram:links`. Для прямых ссылок задайте `TELEGRAM_WEBAPP_SHORT_NAME` (short name из `/newapp`). `telegram:set-webhook` теперь также ставит кнопку меню бота на адрес вебаппа.

Реферальная ссылка имеет вид `https://t.me/<бот>?start=friend_<id>`. `TELEGRAM_BOT_USERNAME` можно писать с собакой или без, в ссылку она не попадёт. Старые ссылки `ref_<id>` продолжают работать.

### 6. Оплата

**Telegram Stars** включены по умолчанию (`TELEGRAM_STARS_ENABLED=true`), отдельная регистрация не нужна. Цена в звёздах считается из рублёвой: `рубли / TELEGRAM_STARS_RUB_RATE`, округление вверх. Курс по умолчанию 2 ₽ за звезду, проверьте его под свою экономику. Возврат делается через `/paysupport` в боте (ссылка из `SUPPORT_URL`).

**ЮKassa** (карты и СБП) включается, когда заполнены `YOOKASSA_SHOP_ID` и `YOOKASSA_SECRET_KEY` или те же данные введены в админке (раздел «Платежи и ЮKassa»). В личном кабинете: Интеграция → HTTP-уведомления → адрес `https://app.example.com/api/payments/yookassa/webhook`, события `payment.succeeded` и `payment.canceled`. Если в магазине включены чеки по 54-ФЗ, поставьте `YOOKASSA_RECEIPT=true` и `YOOKASSA_RECEIPT_EMAIL`, при необходимости поправьте `YOOKASSA_TAX_SYSTEM` и `YOOKASSA_VAT_CODE`.

Если настроены оба способа, пользователь выбирает сам. Если только один, выбор не показывается.

### Обновление и обслуживание

```bash
git pull   # или залить новые файлы
docker compose up -d --build
docker compose logs --tail=100 queue scheduler
docker compose exec db sh -c 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > backup.sql   # локальный контейнер
```

## База данных бота

Приложение и бот работают с одной MySQL-базой бота (схема из `models.py`, Tortoise ORM).

**Подключение.** В `.env`: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (для TLS `MYSQL_ATTR_SSL_CA`). Для удалённой базы удалите `COMPOSE_PROFILES=local-db`, тогда локальный контейнер MySQL не запускается. MySQL на этом же сервере вне Docker: `DB_HOST=host.docker.internal`. Пользователю базы нужны права `SELECT, INSERT, UPDATE` на таблицы бота и `CREATE, ALTER, INDEX, DROP, REFERENCES` для своих таблиц `wa_*` (миграции). Нужен Docker Compose 2.20 или новее.

**Что не трогается.** Таблицы бота не создаются заново, не меняются и не удаляются: миграция `2026_11_14_000001_bot_schema` создаёт таблицу бота только если её нет (пустая база для разработки), а `down()` у неё пустой. Команды `migrate:fresh`, `migrate:reset`, `migrate:refresh` и `db:wipe` в приложении запрещены: они удалили бы и таблицы бота. Все свои таблицы приложение называет с `wa_` (включая `wa_migrations`, `wa_jobs`, `wa_cache`), внешних ключей на таблицы бота у них нет.

**Как данные связаны.**

| Бот (`models.py`) | Приложение |
| --- | --- |
| `users` | человек. `id` = Telegram id. Баланс: `tokens`, `sub_tokens`, `temp_tokens`; подписка: `sub`, `start_sub_time`, `end_sub_time`; пригласивший: `referal_user_from`. Имя, аватар, серия бонусов, блокировка в приложении: `wa_user_profiles` |
| `models` | каталог моделей. Строка `wa_models` с `bot_model_id` берёт название (`text_name`), цену и доступ по подписке (`allowed_subs`) из бота; правка в админке пишется обратно в `models`. Новые модели бота появляются в приложении сами (`neurogpt:sync-bot` каждые 5 минут); видео включается вручную, потому что ему нужны настройки kie.ai |
| `prompts` | фотосеты. Название, промпт, «тренд» и позиция берутся из бота и правятся в обе стороны; описание и обложка только в приложении. Пример в виде Telegram file_id скачивается в обложку |
| `roles` | роль, выбранная в боте, становится системным промптом в чате приложения; `save_context = false` отключает историю |
| `payments`, `shop` | оплаченная в приложении покупка записывается в `payments` с `was_bought = is_proceed = 1` (бот не выдаст её второй раз), `users.paid_money` растёт на сумму в рублях. Незавершённые платежи живут только в `wa_payments`. `item_id` ищется в `shop` по категории/товару/количеству (`BOT_SHOP_*`), если такой строки нет, добавляется скрытая (`active = 0`) |
| `query_logs` | каждая готовая генерация и ответ в чате (тип `image`, `video`, `photoset`, `tool:<id>`, `text`) |
| `links` | если `start`-параметр нового пользователя совпадает с `links.name`, он сохраняется в `users.link` |

Списание идёт сначала из `temp_tokens`, потом из `sub_tokens`, потом из `tokens`; возврат кладёт токены туда, откуда они были взяты (в `tokens`, если временные или подписочные уже сгорели). Покупки и бонусы идут в `tokens`, токены подписки в `sub_tokens`. `promos`, `op`, `payment_methods`, `veo3_prompts`, `logs_table`, `config` приложение не использует.

**Допущения, которые стоит сверить с ботом:** время подписки и временных токенов в секундах Unix (`BOT_TIME_UNIT=ms`, если в миллисекундах); `models.text_name` это название для людей, `models.name` это id модели у провайдера; у подписки в `shop.amount` дни (`BOT_SHOP_SUB_AMOUNT=months` для месяцев); `sub_tokens` тратятся только при активной подписке. Бот на Tortoise по умолчанию сохраняет пользователя целиком (`await user.save()`), поэтому если бот прочитал пользователя, а приложение в это время списало токены, бот может перезаписать баланс старым значением. Надёжнее, чтобы бот менял счётчики через `update_fields=[...]` или `F('tokens') - n`.

### Перенос данных из старой базы приложения

Старая база (где были таблицы `users`, `generations`, `transactions` приложения) только читается. Порядок:

```bash
# 1. В .env: DB_* = база бота, LEGACY_DB_* = старая база приложения
#    (если она в локальном контейнере: LEGACY_DB_HOST=db и оставить COMPOSE_PROFILES=local-db на время переноса)
docker compose up -d --build
# 2. Посмотреть, что будет перенесено
docker compose exec app php artisan neurogpt:import-legacy --dry-run
# 3. Перенести (можно запускать повторно: уже перенесённое пропускается)
docker compose exec app php artisan neurogpt:import-legacy
# 4. Проверить: количество строк по каждой таблице и сравнение записей поле в поле
docker compose exec app php artisan neurogpt:verify-sync --sample=100000
```

Что делает перенос: человек, которого бот уже знает, сохраняет все значения бота, а баланс старого приложения **прибавляется** к `tokens` (с записью «Перенос баланса» в истории; `--balance=skip` чтобы не переносить). Подписка из приложения переносится, только если она действует дольше, чем в боте. Пригласивший ставится, только если в боте его нет. Оплаченные платежи попадают в `payments` бота (`--no-mirror` чтобы нет). Генерации, чаты, история операций, каталог и настройки админки копируются в таблицы `wa_*`. Файлы картинок остаются в том же томе `storage`.

`neurogpt:verify-sync` можно запускать в любое время: он проверяет колонки таблиц бота, ссылки из `wa_*` в таблицы бота, совпадение платежей приложения и бота, журнал операций, а после переноса ещё и количество строк и выборку записей против старой базы. При ошибке код выхода 1.

## Локальная разработка

```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
php artisan serve &  php artisan queue:work &
cd ../frontend && npm install && npm run dev
```

В `backend/.env` поставьте `TELEGRAM_ALLOW_DEV_AUTH=true` (работает только при `APP_ENV=local`), а во `frontend/.env` — `VITE_DEV_TELEGRAM_ID=1`, тогда приложение открывается в обычном браузере. Драйвер `fake` (по умолчанию) рисует заглушки без сети.

Тесты бэкенда: `cd backend && php artisan test` (авторизация, генерации, kie.ai, платежи, Stars, работа на таблицах бота). На MySQL: `DB_CONNECTION=mysql DB_DATABASE=neurogpt_test ... php artisan test` (база очищается, не запускайте на базе бота).

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

Админка открывается по прямой ссылке `https://<домен приложения>/admin` или из профиля (строка «Админка»). Это отдельная страница со своей сборкой (`frontend/admin.html`, вход `src/admin.js`): она не грузит экраны приложения и анимированный фон. Раздел можно задать ссылкой: `/admin?section=payments` (dashboard, content, models, tools, showcase, pricing, settings, payments, theme, users). nginx отдаёт её по адресу `/admin`, поэтому после обновления пересоберите контейнер `web`. Главный администратор задан в `backend/config/neurogpt.php` (`admin_ids`, сейчас `1337592809`). Он добавляет и убирает других администраторов в разделе «Пользователи», вкладка «Администраторы»; самого главного убрать нельзя. Доступ проверяется на сервере при каждом запросе к `/api/admin/*`: остальные получают 403.

Что в ней есть:

- **Обзор**: пользователи, генерации, потраченные токены, оплаты, график за 14 дней.
- **Работы и промпты**: все картинки, видео, аудио, результаты инструментов и сообщения чата с промптами. Фильтры: тип, период (всё время, сегодня, 7 и 30 дней, свои даты), пользователь, текст промпта, статус.
- **Модели**: название, цена, тариф, плашка, провайдер, модель у провайдера, длительности и форматы видео. Можно добавлять и удалять.
- **Инструменты**: название, цена, промпт, варианты выбора, провайдер.
- **Фотосеты и новости**: тексты, промпты, картинки-примеры, порядок.
- **Цены и бонусы**: пакеты токенов, подписки, бонусы, курс Telegram Stars.
- **Настройки**: лимиты, вкладка «Песня», ссылка на поддержку, стили картинок.
- **Цвета**: цветовая гамма тёмной и светлой темы, готовые наборы, сброс к стандартной.
- **Пользователи**: поиск, начисление и списание токенов, подписка, блокировка, администраторы.
- **Платежи и ЮKassa**: все платежи и настройка магазина.

Изменения хранятся в базе (таблица `settings` и отметка `edited_at` у строк каталога) и имеют приоритет над `config/neurogpt.php` и `.env`. Сидер при перезапуске не перезаписывает то, что изменено или удалено в админке. Ключи API и токен бота остаются только в `.env`.

**Примеры в фотосетах.** В разделе «Фотосеты и новости» у каждого фотосета есть картинка-пример, она же обложка в приложении. Кнопка «Сгенерировать пример» запускает промпт фотосета на вашей модели картинок (бесплатно для администратора), «Сгенерировать по фото» делает то же с вашей фотографией, «Загрузить свою» ставит готовую картинку. «Сгенерировать для всех» по очереди создаёт примеры для фотосетов без картинки. Пока картинки нет, приложение показывает прежний цветной узор.

**Платежи и ЮKassa.** Раздел показывает все платежи с фильтрами по периоду, статусу, способу и покупателю, выручку в рублях и звёздах, и по каждому платежу id в ЮKassa, способ оплаты, сумму после комиссии и причину отмены. Зависший платёж можно перепроверить кнопкой «Проверить в ЮKassa». На вкладке «Настройка ЮKassa» главный администратор задаёт shopId, секретный ключ, адрес возврата и чеки, и проверяет подключение. Значения из админки важнее `YOOKASSA_*` в `.env`. Секретный ключ хранится в базе в зашифрованном виде (ключом `APP_KEY`) и в админке не показывается: если поменять `APP_KEY`, ключ ЮKassa нужно ввести заново.

**Вход с компьютера.** Откройте `https://<домен приложения>/admin` в обычном браузере: появится кнопка «Войти через Telegram», после входа сразу откроется админка. Чтобы она работала, один раз привяжите домен к боту: в @BotFather команда `/setdomain`, выберите бота и укажите домен приложения. Вход в браузере разрешён только администраторам и действует 30 дней (`TELEGRAM_LOGIN_TTL`, в секундах).

## Цены и тарифы

Меняются в админке (разделы «Цены и бонусы» и «Модели»). Начальные значения лежат в `backend/config/neurogpt.php`: пакеты токенов, тарифы, бонусы, лимиты.

### Песни

Вкладка «Песня» пока закрыта заглушкой «Coming soon», а API отвечает `coming_soon`. Чтобы включить: впишите версию музыкальной модели Replicate в `REPLICATE_SONG_MODEL`, поставьте `FEATURE_SONGS=true` и выполните `php artisan db:seed --force`. Интерфейс песен в мини-аппе нужно будет доделать отдельно.
