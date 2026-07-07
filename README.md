# DvaShop

Laravel 13 Blade-монолит для будущего интернет-магазина автотоваров.

На этом этапе в проекте подготовлена только базовая инфраструктура: Docker-окружение, web/session-авторизация, Filament-админка `/admin`, простые роли пользователей и smoke-тесты. Бизнес-логика магазина и текущая Blade-верстка не менялись.

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

Миграции и сидер первого `super_admin`:

```bash
docker compose exec app php artisan migrate --seed
```

Сборка фронтенда:

```bash
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
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
npm run dev
php artisan test
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
curl -I http://localhost:8080/catalog
curl -I http://localhost:8080/admin
```

Ожидаемо:

- `/` отвечает `200`;
- `/catalog` отвечает `200`;
- `/admin` для гостя редиректит на страницу входа Filament;
- пользователь с ролью `super_admin` входит в админку.

## Импорт каталога

Инфраструктура импорта доступна в Filament-админке:

```text
/admin/imports/catalog
```

Импорт загружает CSV/XLSX, читает лист `Каталог`, создаёт/обновляет марки, модели, поколения, товарные категории, товары, варианты, применимость и ставит изображения в очередь. Пауза в V1 останавливает только обработку строк; уже поставленные задачи изображений продолжают выполняться в очереди `imports-images`.

Очереди для импорта:

```text
imports
imports-images
```

Локальный queue worker в Docker слушает `default,imports,imports-images`. Для ручного запуска можно использовать:

```bash
docker compose exec app php artisan queue:work --queue=default,imports,imports-images --sleep=3 --tries=3 --timeout=90
```

Проверка инфраструктуры импорта:

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan test tests/Feature/Import/CatalogImportInfrastructureTest.php
```

## Media storage and image processing

Public images are stored on the `public` filesystem disk. Import source files are stored on the local private disk under `storage/app/imports/...` and are downloaded only through Filament actions. For local development run:

```bash
php artisan storage:link
```

Image uploads and import downloads use the shared media pipeline:

- product images: `uploads/products/{product_id}/{uuid}.webp`
- product conversions: `uploads/products/{product_id}/conversions/{uuid}_thumb.webp`, `..._card.webp`
- vehicle generation images: `uploads/vehicles/generations/{generation_id}/{uuid}.webp`
- vehicle make images: `uploads/vehicles/makes/{make_id}/{uuid}.webp`

Supported source formats are JPEG, PNG and WebP. Non-images, SVG, PDF and HTML responses are rejected. The default source file limit is configured in `config/media.php` and can be overridden with `MEDIA_MAX_SOURCE_SIZE`.

Import queues:

- row import jobs: `imports`
- remote image jobs: `imports-images`

The local PHP image must have GD with WebP support. After Dockerfile changes rebuild the app container before running image tests:

```bash
docker compose build app
docker compose up -d
```
