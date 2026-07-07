# DvaShop

Laravel 13 Blade-монолит для интернет-магазина автотоваров.

Текущий рабочий фокус проекта: backend, Filament-админка, каталог, импорт Excel/CSV, обработка изображений, очереди и storage hygiene. Публичную Blade-вёрстку магазина на этом этапе не развиваем и не меняем без отдельной задачи.

## Что уже есть

- Docker-окружение для Laravel, MySQL, Redis, очередей, scheduler, Vite, Mailpit и Adminer.
- Web/session-авторизация и Filament-админка `/admin`.
- Простые роли пользователей: `super_admin`, `admin`, `manager`, `customer`.
- Модели каталога: категории товаров, марки, модели, поколения авто, товары, варианты, изображения и применимость.
- Заказы, корзина и базовая checkout-логика в backend.
- Импорт каталога из CSV/XLSX через Filament.
- Очереди для строк импорта и удалённых изображений.
- Media pipeline: загрузка, валидация, конвертация в WebP, conversions, дедупликация и cleanup старых файлов.

## Сервисы Docker

`docker-compose.yml` поднимает:

- `app` — PHP-FPM 8.4, Laravel-приложение;
- `nginx` — web-сервер, порт по умолчанию `8080`;
- `mysql` — MySQL 8.4, порт хоста по умолчанию `33066`;
- `redis` — Redis 7, порт хоста по умолчанию `63790`;
- `queue` — `php artisan queue:work`;
- `scheduler` — `php artisan schedule:run` каждую минуту;
- `node` — Vite dev-server, порт по умолчанию `5173`;
- `mailpit` — локальная почта, web-интерфейс `8025`;
- `adminer` — web-интерфейс к БД, порт `8081`.

`queue` и `scheduler` ждут появления `vendor/autoload.php`, поэтому первый запуск до `composer install` не валит окружение постоянными PHP-ошибками.

## Первый запуск в Docker

Локальный `.env` создаётся вручную из примера и никогда не хранится в репозитории или clean-архивах:

```bash
cp .env.docker.example .env
```

Перед миграциями поменяй в `.env` данные первого администратора:

```dotenv
ADMIN_NAME="DvaShop Super Admin"
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=change-me
```

Запуск контейнеров:

```bash
docker compose up -d --build
```

Установка PHP-зависимостей:

```bash
docker compose exec app composer install
```

Если `composer.lock` еще не обновлен после добавления Filament, один раз выполни:

```bash
docker compose exec app composer update filament/filament --with-dependencies
```

Ключ приложения:

```bash
docker compose exec app php artisan key:generate
```

Storage link создаётся локально и не коммитится:

```bash
docker compose exec app php artisan storage:link
```

Миграции и сидер первого `super_admin`:

```bash
docker compose exec app php artisan migrate --seed
```

Сборка фронтенда:

```bash
docker compose run --rm node npm install
docker compose run --rm node npm run build
```

Dev-режим Vite уже запускается сервисом `node`. Если нужно запустить вручную:

```bash
docker compose run --rm --service-ports node npm run dev -- --host 0.0.0.0
```

Тесты:

```bash
docker compose exec app php artisan test
```

## Локальные команды без Docker

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan storage:link
php artisan migrate --seed
php artisan test
npm install
npm run build
npm run dev
```

## Админка

Админ-панель доступна по адресу:

```text
/admin
```

Используется стандартный Laravel `web` guard на сессиях. Passport и API-авторизация не подключались.

Роли пользователей хранятся простым полем `users.role`:

- `super_admin`;
- `admin`;
- `manager`;
- `customer`.

В админку допускаются `super_admin`, `admin`, `manager`. `customer` остается обычным клиентским пользователем без доступа к `/admin`.

Первый `super_admin` создается сидером `Database\Seeders\AdminUserSeeder` из переменных окружения `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`.

## Проверка после запуска

```bash
curl -I http://localhost:8080/
curl -I http://localhost:8080/admin
```

Ожидаемо:

- `/` отвечает `200`;
- `/admin` для гостя редиректит на страницу входа Filament;
- пользователь с ролью `super_admin` входит в админку.

## Импорт каталога

Инфраструктура импорта доступна в Filament-админке:

```text
/admin/imports/catalog
```

Импорт загружает CSV/XLSX, читает лист `Каталог`, создаёт/обновляет марки, модели, поколения, товарные категории, товары, default variants, применимость и ставит изображения в очередь.

Структура реального XLSX:

- A: фото автомобиля;
- B: марка;
- C: модель;
- D: поколение;
- E: годы выпуска;
- F: кузов;
- G+ товарные детали.

Импорт сейчас делает:

- создаёт марки, модели и поколения авто;
- создаёт дерево товарных категорий из первых двух строк файла;
- учитывает реальные merged ranges XLSX: `H:L` попадает в `Арка`, `M:O` в `Пенка`, а `P:S` остаются корневыми категориями;
- нормализует текст из Excel: переносы строк, табы, неразрывные и повторные пробелы сворачиваются в один пробел;
- создаёт товары, default variants и применимость товара к поколению авто;
- обрабатывает фото авто из колонки A как изображение `VehicleGeneration`;
- обрабатывает URL в товарной ячейке как изображение товара;
- ставит загрузку изображений в очередь `imports-images`;
- конвертирует jpeg/png/webp в WebP и создаёт conversions;
- чистит старые файлы и conversions при замене/удалении изображений;
- не ставит повторную image job, если source URL уже скачан и файл существует.

Импорт не делает:

- не подставляет выдуманные цены;
- не генерирует выдуманные SKU;
- не заполняет SEO и описания товара, если их нет в файле;
- не трогает публичный фронт магазина.

Очереди для импорта:

```text
imports
imports-images
```

Локальный queue worker в Docker слушает `default,imports,imports-images`. Для ручного запуска можно использовать:

```bash
docker compose exec app php artisan queue:work --queue=default,imports,imports-images --sleep=3 --tries=3 --timeout=90
```

Пауза в V1 останавливает только обработку строк; уже поставленные image jobs продолжают выполняться. После обработки всех image jobs импорт переходит из `processing_images` в `done`, если он не `failed` и не `canceled`.

Архивация:

- отсутствующие товары архивируются только в рамках текущего `import_source`;
- если во время обработки строк есть `errors_count > 0`, автоархивация пропускается и в лог пишется warning;
- `failed` и `canceled` импорты ничего не архивируют;
- image jobs не переводят `failed/canceled` импорт в `done`.

Диагностика файла без записи в БД:

```bash
php artisan import:inspect-file storage/app/imports/catalog/your-file.xlsx
```

Команда выводит количество строк, марок, моделей, поколений/кузовов, заполненных товарных ячеек, URL в колонке A, URL в товарных ячейках и дерево категорий из заголовков. Для файла `БАЗА АВТО исправлена.xlsx` ориентиры: около 2080 data rows, 79 марок, 948 моделей, 2002 поколений/кузовов, 16394 заполненных товарных ячеек и около 15791 уникальных товаров после дедупликации. Это ориентиры для ручной проверки, не жёсткие production-asserts.

Проверка инфраструктуры импорта:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan test --filter=Import
```

## Media storage and image processing

Public images are stored on the `public` filesystem disk. Import source files are stored on the local private disk under `storage/app/imports/...` and are downloaded only through Filament actions.

Storage link:

```bash
php artisan storage:link
```

`public/storage` создаётся только этой командой и не должен попадать в git или clean-архив.

Image uploads and import downloads use the shared media pipeline:

- product images: `uploads/products/{product_id}/{uuid}.webp`
- product conversions: `uploads/products/{product_id}/conversions/{uuid}_thumb.webp`, `..._card.webp`
- vehicle generation images: `uploads/vehicles/generations/{generation_id}/{uuid}.webp`
- vehicle make images: `uploads/vehicles/makes/{make_id}/{uuid}.webp`

Supported source formats are JPEG, PNG and WebP. Non-images, SVG, PDF and HTML responses are rejected. The default source file limit is configured in `config/media.php` and can be overridden with `MEDIA_MAX_SOURCE_SIZE`.

The local PHP image must have GD with WebP support. After Dockerfile changes rebuild the app container before running image tests:

```bash
docker compose build app
docker compose up -d
docker compose exec app php -r "var_dump(extension_loaded('gd'), function_exists('imagewebp'));"
```

## Storage structure

Стандартная структура `storage` хранится в репозитории только через `.gitignore`-файлы:

```text
storage/app/.gitignore
storage/app/public/.gitignore
storage/framework/.gitignore
storage/framework/cache/.gitignore
storage/framework/cache/data/.gitignore
storage/framework/sessions/.gitignore
storage/framework/testing/.gitignore
storage/framework/views/.gitignore
storage/logs/.gitignore
```

В репозиторий и clean-архив не кладутся реальные логи, import-файлы, processed images, sessions, cache и compiled views.

## Bootstrap cache

В репозитории должен быть только:

```text
bootstrap/cache/.gitignore
```

Generated files не хранятся в git и не передаются в clean-архивах:

```text
bootstrap/cache/packages.php
bootstrap/cache/services.php
bootstrap/cache/config.php
bootstrap/cache/routes*.php
bootstrap/cache/events.php
```

Эти файлы создаются локально командами Composer/Laravel `optimize`, `config:cache`, `route:cache`, `event:cache` и могут быть очищены через:

```bash
php artisan optimize:clear
```

## Clean archive hygiene

Локальная разработка может иметь `.env`, `public/storage`, логи, cache, sessions и build-файлы. Но git и clean-архив не должны их содержать.

Проверка tracked-файлов и обязательной структуры:

```bash
php artisan project:check-clean-tree
```

Строгая проверка физического clean-tree перед ручной упаковкой:

```bash
php artisan project:check-clean-tree --strict
```

Перед созданием clean-архива нельзя передавать:

- `.env`, `.env.*`, кроме `.env.example` и `.env.docker.example`;
- `public/storage`;
- `public/hot`;
- `bootstrap/cache/*.php`;
- `vendor`;
- `node_modules`;
- `*.patch`;
- `*:Zone.Identifier`;
- реальные файлы внутри `storage/app/imports`, `storage/app/public`, `storage/logs`, `storage/framework/cache`, `storage/framework/sessions`, `storage/framework/views`.

Создать clean-архив из tracked-файлов git можно скриптом:

```bash
scripts/make-clean-archive.sh ../dvashop_clean_$(date +%Y%m%d_%H%M%S).tar.gz
```

Скрипт использует `git archive`, проверяет hygiene через `project:check-clean-tree`, затем дополнительно проверяет содержимое созданного архива. `.env.example`, `.env.docker.example`, `storage/**/.gitignore` и `bootstrap/cache/.gitignore` остаются в архиве.

## Базовые проверки перед реальным импортом

```bash
composer validate
php artisan optimize:clear
php artisan migrate:fresh --seed
php artisan test
npm run build
```
