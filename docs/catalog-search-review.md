# Scout + Meilisearch — итоговая проверка

BASELINE_HEAD=ee557eb7c9daea77832750a8aad37373ec211040
CURRENT_HEAD=ee557eb7c9daea77832750a8aad37373ec211040

Работа выполнена напрямую в локальном проекте, без commit/push/deploy.
Реальная MySQL-база получила только согласованную миграцию aliases; словарь
русских названий не заполнялся автоматически.

## Закрытые замечания

Variant SKU теперь отбираются тем же StorefrontProductAvailability::variants(),
что и прежний SQL-поиск: неактивные значения и группы опций исключают SKU.
Регрессия проверяет товар, остающийся публичным через другой вариант:
HIDDEN-VALUE-SKU/HIDDEN-GROUP-SKU не дают кандидатов и карточек,
публичный SKU продолжает находить товар. Для изменения активности опций
добавлено пакетное обновление зависимых товарных документов.

Повторная проверка БД сохранена. Товары дочитываются максимум в трёх окнах по 50:
один общий multiSearch и максимум два product-only запроса. Повторы удаляются.
Проверки покрывают 40 скрытых кандидатов в начале, заполнение 12 карточек,
переход вперёд/назад без потери неиспользованной части окна и лимит запросов.
SQL budgets: <=18 запросов для сценария заполнения с карточными связями;
<=8 для трёх полностью устаревших окон. Обычная выдача требует один multiSearch.

Meilisearch estimatedTotalHits больше не используется как public total.
Поиск получил «Назад/Вперёд» без недостоверного числа страниц; при исчерпании
лимита просмотра — «Искать дальше». SQL fallback сохраняет точный SQL total
и прежнюю нумерованную навигацию. Изменения индекса между переходами могут
менять порядок, как при обычной offset-навигации.

## Проверки и локальная инфраструктура

- Targeted: 85 passed, 741 assertions.
- Real Meilisearch integration: 1 passed, 60 assertions; случайный dvashop_test_* prefix,
  English/Russian/mixed/prefix/short codes/all-fitments/product SKU/public variant SKU/hidden SKU.
  После cleanup осталось ровно четыре локальных индекса, test indexes = 0.
- Final full suite: 1373 passed, 2 skipped, 14603 assertions, 125.51s — PASS.
  Два пропуска ожидаемы: один baseline и один opt-in Meilisearch, который отдельно пройден.
  Лог: /tmp/dvashop_search_full_suite_final.txt.
- Первый повторный full suite: 1372 passed, 2 skipped, 1 failure в неизменённом
  YandexFeedTest: Future generation date. Отдельный повтор YandexFeedTest:
  70 passed / 455 assertions; его код не изменялся.
- php -l: PASS для всех 38 изменённых PHP/Blade-файлов.
- Pint --test: PASS для тех же 38 файлов.
- composer validate: PASS.
- Production Vite build через Docker node: PASS. Существующие предупреждения
  о runtime-путях шрифтов сохранены. Node остановлен, public/hot отсутствует.
- git diff --check: PASS, пустой вывод.
- Браузерная изолированная проверка использует настоящий Blade component и build:
  1366px — русские aliases марки/модели и canonical labels; 390px — alias марки.
  Поле модели в исходном мобильном дизайне скрыто <=1024px; дизайн не менялся.

Meilisearch v1.15.2 healthy, named volume meilisearch_data,
host port только 127.0.0.1:7700. Приватный nginx gateway :7701 использует
асинхронный Docker resolver и DNS/connect timeout 250 ms.
Проверено живым процессом: php artisan queue:work catalog-search
--queue=catalog-search --sleep=2 --tries=3 --timeout=600.
Connection driver=redis, retry_after=660 > job timeout=600.
Default connection=database, retry_after=660; Yandex connection=database,
queue=yandex-feed, retry_after=1500. Их конфигурация и команды worker не менялись.

## Миграция, зависимости, конфигурация

Миграция: 2026_09_09_000100_add_search_aliases_to_vehicles.php.
Nullable JSON на vehicle_makes/vehicle_models; обратимость up/down/up
и сохранение canonical данных проверены. Миграция применена локально.

Composer: laravel/scout 11.7.0, meilisearch/meilisearch-php 1.17.0;
транзитивно php-http/discovery 1.20.0, symfony/polyfill-php81 1.38.1.

Новые config/scout.php и config/catalog-search.php; в config/queue.php добавлена
отдельная connection catalog-search. Переменные приведены в .env.example:
SCOUT_DRIVER, SCOUT_QUEUE, SCOUT_QUEUE_CONNECTION, SCOUT_PREFIX,
MEILISEARCH_HOST, MEILISEARCH_KEY, MEILISEARCH_FORWARD_PORT,
CATALOG_SEARCH_DRIVER, CATALOG_SEARCH_TIMEOUT_MS.
Пример по умолчанию использует database для витрины и null для Scout.
Локально включены Scout и Meilisearch-витрина, queue=true, timeout=250 ms.

Команды: официальный scout:sync-index-settings --driver=meilisearch
и новый catalog-search:rebuild. Последний не очищает индекс, обходит чанки по 100,
проверяет каждую отправленную задачу и поддерживает повторный запуск.

| Индекс | SQL / Meilisearch |
|---|---:|
| dvashop_local_catalog_vehicle_makes | 78 / 78 |
| dvashop_local_catalog_vehicle_models | 943 / 943 |
| dvashop_local_catalog_vehicle_generations | 2002 / 2002 |
| dvashop_local_catalog_products | 15791 / 15791 |

Отдельных variant documents нет.

## Final benchmark после gateway и последних исправлений

HTTP GET /catalog?q=..., warmup=2, runs=5 для каждого запроса.
p95 — верхний sample при пяти замерах. Во всех замерах HTTP 200.
Во время healthy benchmark зарегистрировано 0 SQL fallback warnings.
Сырые результаты: /tmp/dvashop_search_baseline.txt и
/tmp/dvashop_search_meilisearch.txt.

| Запрос | Baseline p50 / p95, мс | Final p50 / p95, мс | Изменение p50 / p95 |
|---|---:|---:|---:|
| Audi | 249.79 / 301.89 | 146.81 / 155.84 | -41.2% / -48.4% |
| Audi 100 | 223.41 / 230.12 | 139.24 / 144.39 | -37.7% / -37.3% |
| Toyota | 334.87 / 346.97 | 139.88 / 151.39 | -58.2% / -56.4% |
| Camry | 236.63 / 261.62 | 135.15 / 136.93 | -42.9% / -47.7% |
| BMW X5 | 223.71 / 262.68 | 126.40 / 129.99 | -43.5% / -50.5% |
| Mercedes | 246.52 / 251.17 | 140.59 / 198.22 | -43.0% / -21.1% |
| Chevrolet | 255.55 / 303.68 | 135.22 / 141.68 | -47.1% / -53.3% |
| A3 | 242.43 / 262.76 | 138.58 / 144.18 | -42.8% / -45.1% |
| Q5 | 213.48 / 220.30 | 97.47 / 116.18 | -54.3% / -47.3% |
| X5 | 226.14 / 228.32 | 126.30 / 131.85 | -44.1% / -42.3% |
| CX-5 | 145.20 / 154.15 | 89.30 / 95.91 | -38.5% / -37.8% |

Все 11 запросов быстрее baseline по p50 и p95.
Снижение p50: 37.7–58.2%.

## Реальная остановка Meilisearch

После docker compose stop meilisearch выполнены два прогревочных и пять измеряемых
запросов Audi. Статусы: [200, 200, 200, 200, 200].
Время пяти запросов, мс: [510.15, 500.63, 504.08, 512.9, 507.11].
p50=507.11 ms, p95=512.9 ms.
В журнале подтверждены все 7 переходов на SQL fallback. Время включает не только
ожидание Meilisearch (250 ms), но и выполнение прежнего SQL-поиска.
Восьмисекундной задержки нет. После проверки сервис снова поднят и healthy,
контрольный Audi вернул HTTP 200.
Сырые данные: /tmp/dvashop_search_outage.json.

## Следующий шаг с данными

Реальные Russian aliases следует заполнить отдельным согласованным data step.
Код их не выдумывает. Безопасный экспорт, dry-run, применение только search_aliases
по проверенным ID и единичный rebuild описаны в [инструкции](catalog-search.md).
Production prerequisites и rollback витрины также описаны там; production не менялся.

## Git

git status -sb:

~~~text
## main...origin/main
 M .env.example
 M app/Filament/Resources/VehicleMakes/VehicleMakeResource.php
 M app/Filament/Resources/VehicleModels/VehicleModelResource.php
 M app/Http/Controllers/CatalogController.php
 M app/Http/Controllers/Storefront/VehicleMakeModelsController.php
 M app/Jobs/CatalogImportChunkJob.php
 M app/Models/Product.php
 M app/Models/VehicleGeneration.php
 M app/Models/VehicleMake.php
 M app/Models/VehicleModel.php
 M app/Providers/AppServiceProvider.php
 M app/Services/Storefront/HomepageViewDataProvider.php
 M composer.json
 M composer.lock
 M config/queue.php
 M docker-compose.yml
 M docker/local/nginx/default.conf
 M phpunit.xml
 M resources/js/app.js
 M resources/views/catalog.blade.php
 M resources/views/components/search.blade.php
 M resources/views/components/storefront-pagination.blade.php
 M tests/Feature/Storefront/StorefrontCommerceTest.php
?? app/Console/Commands/CatalogSearchRebuild.php
?? app/Jobs/RefreshCatalogSearchDependencies.php
?? app/Models/Concerns/
?? app/Observers/
?? app/Services/Search/
?? config/catalog-search.php
?? config/scout.php
?? database/migrations/2026_09_09_000100_add_search_aliases_to_vehicles.php
?? docs/catalog-search-review.md
?? docs/catalog-search.md
?? tests/Feature/Search/
?? tests/Support/
~~~

git diff --stat (Git не включает untracked-файлы в эту команду):

~~~text
 .env.example                                       |  10 +
 .../Resources/VehicleMakes/VehicleMakeResource.php |   6 +
 .../VehicleModels/VehicleModelResource.php         |   6 +
 app/Http/Controllers/CatalogController.php         | 169 +----------
 .../Storefront/VehicleMakeModelsController.php     |   3 +-
 app/Jobs/CatalogImportChunkJob.php                 |  11 +
 app/Models/Product.php                             |   3 +
 app/Models/VehicleGeneration.php                   |   3 +
 app/Models/VehicleMake.php                         |   8 +
 app/Models/VehicleModel.php                        |   8 +
 app/Providers/AppServiceProvider.php               |  14 +
 .../Storefront/HomepageViewDataProvider.php        |   1 +
 composer.json                                      |   6 +-
 composer.lock                                      | 322 ++++++++++++++++++++-
 config/queue.php                                   |   9 +
 docker-compose.yml                                 |  41 +++
 docker/local/nginx/default.conf                    |  20 ++
 phpunit.xml                                        |   6 +
 resources/js/app.js                                |   7 +-
 resources/views/catalog.blade.php                  |   4 +-
 resources/views/components/search.blade.php        |   2 +-
 .../components/storefront-pagination.blade.php     |  16 +-
 .../Feature/Storefront/StorefrontCommerceTest.php  |   4 +-
 23 files changed, 510 insertions(+), 169 deletions(-)
~~~

git diff --check: пустой вывод, PASS.

## CHANGED FILES

- .env.example
- app/Console/Commands/CatalogSearchRebuild.php
- app/Filament/Resources/VehicleMakes/VehicleMakeResource.php
- app/Filament/Resources/VehicleModels/VehicleModelResource.php
- app/Http/Controllers/CatalogController.php
- app/Http/Controllers/Storefront/VehicleMakeModelsController.php
- app/Jobs/CatalogImportChunkJob.php
- app/Jobs/RefreshCatalogSearchDependencies.php
- app/Models/Concerns/CatalogSearchable.php
- app/Models/Concerns/HasSearchAliases.php
- app/Models/Product.php
- app/Models/VehicleGeneration.php
- app/Models/VehicleMake.php
- app/Models/VehicleModel.php
- app/Observers/CatalogSearchDependencyObserver.php
- app/Observers/CatalogSearchImportObserver.php
- app/Providers/AppServiceProvider.php
- app/Services/Search/CatalogSearchDocument.php
- app/Services/Search/CatalogSearchIndexer.php
- app/Services/Search/CatalogSearchPaginator.php
- app/Services/Search/CatalogSearchService.php
- app/Services/Search/CatalogSearchSync.php
- app/Services/Search/DatabaseCatalogSearchProvider.php
- app/Services/Search/MeilisearchCatalogSearchProvider.php
- app/Services/Search/SearchText.php
- app/Services/Storefront/HomepageViewDataProvider.php
- composer.json
- composer.lock
- config/catalog-search.php
- config/queue.php
- config/scout.php
- database/migrations/2026_09_09_000100_add_search_aliases_to_vehicles.php
- docker-compose.yml
- docker/local/nginx/default.conf
- docs/catalog-search-review.md
- docs/catalog-search.md
- phpunit.xml
- resources/js/app.js
- resources/views/catalog.blade.php
- resources/views/components/search.blade.php
- resources/views/components/storefront-pagination.blade.php
- tests/Feature/Search/CatalogSearchPaginationTest.php
- tests/Feature/Search/CatalogSearchSyncTest.php
- tests/Feature/Search/CatalogSearchTest.php
- tests/Feature/Search/MeilisearchIntegrationTest.php
- tests/Feature/Storefront/StorefrontCommerceTest.php
- tests/Support/CatalogSearchFixtures.php

## Исходные acceptance markers

~~~text
SCOUT_INSTALLED=YES
MEILISEARCH_CLIENT_INSTALLED=YES
MEILISEARCH_DOCKER_HEALTHY=YES
MAKE_ALIASES_IMPLEMENTED=YES
MODEL_ALIASES_IMPLEMENTED=YES
ALIASES_NOT_HARDCODED=YES
IMPORT_PRESERVES_ALIASES=YES
MAKE_INDEX=YES
MODEL_INDEX=YES
GENERATION_INDEX=YES
PRODUCT_INDEX=YES
VARIANTS_INDEXED_AS_SEPARATE_DOCS=NO
PRODUCT_SEARCH_INCLUDES_ALL_FITMENTS=YES
PRODUCT_SEARCH_INCLUDES_PRODUCT_SKU=YES
PRODUCT_SEARCH_INCLUDES_VARIANT_SKUS=YES
ENGLISH_SEARCH=PASS
RUSSIAN_ALIAS_SEARCH=PASS
MIXED_LANGUAGE_SEARCH=PASS
PREFIX_SEARCH=PASS
SHORT_CODE_SEARCH=PASS
PRODUCT_SEARCH_BY_RUSSIAN_VEHICLE_ALIAS=PASS
HOMEPAGE_MAKE_ALIAS_SEARCH=PASS
HOMEPAGE_MODEL_ALIAS_SEARCH=PASS
CANONICAL_DISPLAY_LABELS_PRESERVED=YES
TRANSLITERATION_GENERIC=YES
TRANSLITERATION_IS_ONLY_FALLBACK=YES
QUERY_SPECIFIC_HARDCODE=NO
MEILISEARCH_MULTI_SEARCH=YES
SEARCH_HYDRATION_QUERY_COUNT_BOUNDED=YES
INDEX_IMPORT_QUERY_COUNT_BOUNDED=YES
NO_SEARCH_N_PLUS_ONE=YES
SQL_FALLBACK_PRESERVED=YES
MEILISEARCH_DOWN_RETURNS_200=YES
EXPECTED_TRANSPORT_ERRORS_FALL_BACK=YES
PROGRAMMING_ERRORS_NOT_HIDDEN=YES
SEARCH_TIMEOUT_BOUNDED=YES
SCOUT_SYNC_AFTER_COMMIT=YES
DEPENDENCY_REINDEX_BATCHED=YES
IMPORT_SEARCH_JOB_EXPLOSION_PREVENTED=YES
REBUILD_COMMAND_IMPLEMENTED=YES
PUBLIC_VISIBILITY_REVALIDATED_IN_MYSQL=YES
SOFT_DELETED_RESULTS_HIDDEN=YES
INACTIVE_RESULTS_HIDDEN=YES
REAL_MEILISEARCH_INTEGRATION_PASS=YES
SEARCH_LATENCY_NOT_WORSE_THAN_BASELINE=YES
FULL_SUITE_PASS=YES
PINT_PASS=YES
COMPOSER_VALIDATE_PASS=YES
FRONTEND_BUILD_PASS=YES
GIT_DIFF_CHECK_PASS=YES
PUBLIC_HOT_ABSENT=YES
BITRIX_PRESERVED=YES
UIS_PRESERVED=YES
YANDEX_PRESERVED=YES
PROMO_PRESERVED=YES
ORDER_SNAPSHOTS_PRESERVED=YES
REAL_EXTERNAL_PAID_REQUESTS_SENT=NO
PRODUCTION_CHANGED=NO
COMMIT_CREATED=NO
PUSH_PERFORMED=NO
DEPLOY_PERFORMED=NO
TASK_READY_FOR_REVIEW=YES
~~~

## Финальные markers продолжения

~~~text
VARIANT_SKU_PUBLIC_CONTRACT_PRESERVED=YES
HIDDEN_VARIANT_SKU_NOT_SEARCHABLE=YES
PRODUCT_VISIBILITY_REVALIDATED_IN_DB=YES
PRODUCT_CANDIDATE_REFILL_BOUNDED=YES
PRODUCT_PAGINATION_CORRECT=YES
SEARCH_SQL_QUERY_COUNT_BOUNDED=YES
MEILISEARCH_HEALTHY_SEARCH=PASS
MEILISEARCH_DOWN_HTTP_200=PASS
MEILISEARCH_DOWN_P50_MS=507.11
MEILISEARCH_DOWN_P95_MS=512.90
SEARCH_TIMEOUT_BOUNDED=YES
SEARCH_QUEUE_REDIS=YES
SEARCH_QUEUE_LEASE_SAFE=YES
DEFAULT_QUEUE_PRESERVED=YES
YANDEX_QUEUE_PRESERVED=YES
REAL_MEILISEARCH_INTEGRATION_PASS=YES
SEARCH_LATENCY_NOT_WORSE_THAN_BASELINE=YES
FULL_SUITE_PASS=YES
PINT_PASS=YES
COMPOSER_VALIDATE_PASS=YES
FRONTEND_BUILD_PASS=YES
GIT_DIFF_CHECK_PASS=YES
PUBLIC_HOT_ABSENT=YES
COMMIT_CREATED=NO
PUSH_PERFORMED=NO
DEPLOY_PERFORMED=NO
TASK_READY_FOR_REVIEW=YES
~~~
