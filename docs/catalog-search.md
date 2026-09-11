# Поиск каталога: Scout + Meilisearch

## Управление и локальный запуск

CATALOG_SEARCH_DRIVER=database|meilisearch управляет только витриной.
Без настройки используется database. SCOUT_DRIVER=meilisearch отдельно
включает поддержку индексов; SCOUT_QUEUE=true — обновления через очередь после commit.
В .env.example Scout выключен (null), витрина безопасно оставлена на database.

Локальные сервисы:

- meilisearch: getmeili/meilisearch:v1.15.2, volume meilisearch_data,
  healthcheck /health, только 127.0.0.1:7700 на хосте.
- Внутренний gateway http://nginx:7701 доступен только в Docker-сети.
  Асинхронный Docker DNS resolver и таймаут 250 мс устраняют наблюдавшееся
  долгое ожидание системного DNS при остановке Meilisearch.
- search-queue: connection catalog-search, queue catalog-search, Redis,
  worker timeout 600 секунд, retry_after 660 секунд.
  Worker зависит от app, nginx gateway, healthy meilisearch и redis, поэтому
  не стартует раньше внутреннего Meilisearch gateway.
  Очереди default,imports,imports-images и yandex-feed не изменены.

~~~sh
docker compose up -d meilisearch
docker compose exec -T app php artisan migrate
docker compose up -d --no-deps app search-queue
docker compose restart nginx
docker compose exec -T app php artisan scout:sync-index-settings --driver=meilisearch
docker compose exec -T app php artisan catalog-search:rebuild
~~~

После изменения Docker env_file требуется пересоздать затронутый сервис.
После пересоздания app локальный nginx следует перезапустить для обновления
upstream PHP. Секреты нельзя печатать через полный docker compose config.

catalog-search:rebuild проверяет Scout driver, доступность сервера, применяет
настройки Scout из репозитория и обходит таблицы чанками по 100 записей.
Команда не очищает индексы перед загрузкой, проверяет отправленные Meilisearch tasks,
обновляет документы и удаляет документы soft-deleted строк.
Повторный запуск допустим. Физически удалённые вне Eloquent старые документы
могут остаться кандидатами; витрина всегда перепроверяет их в БД.

Индексы: SCOUT_PREFIX + catalog_vehicle_makes, catalog_vehicle_models,
catalog_vehicle_generations, catalog_products. Default prefix — dvashop_local_.

## Состав документов и видимость

Все строки, не удалённые через soft delete, индексируются с is_active; активность
товара определяется его status. Изображения, описания, цены, характеристики,
опции, SEO-поля и ProductVariant.title в поиск не попадают.
Для товара добавлены SKU, публичные variant SKU и сведения обо **всех**
fitments, а также category/part_type ID как фильтры навигации.
SKU вариантов отбираются через StorefrontProductAvailability::variants(),
включая проверку активности выбранного значения и группы опций.

Scout hooks заранее загружают связи на коллекцию. Генерация документа не делает
ленивых запросов; незагруженная обязательная связь вызывает явную ошибку.
Публичные карточки и автомобильные группы проходят прежние правила видимости
в БД, а сортировка сохраняет порядок поисковых кандидатов.

Обычное изменение модели синхронизируется Scout после commit. Изменения родителей,
fitments, SKU вариантов и активности групп/значений опций вызывают пакетное
обновление зависимых документов. Изменения через прямой SQL, массовый update
без событий или saveQuietly требуют отдельного catalog-search:rebuild.

Граница CatalogImportChunkJob подавляет Scout и зависимые jobs на время обработки
строк и автоархивации. Успешный переход catalog ImportRun в Done без ошибок строк
создаёт одно пакетное обновление; Failed/Canceled его не создают.
Импорт не перезаписывает ручные search_aliases.

## Выдача и навигация

Один bounded multiSearch возвращает четыре группы сразу для всех нужных каналов.
Обычный English fast path остаётся одним original-каналом. Для кириллического proper-name
запроса в тот же HTTP-запрос добавляются strict transliteration, phonetic и при
необходимости safe phonetic-prefix каналы;
keyboard-layout формы индексируются отдельно и проверяются внутри original query.
Словаря автомобильных имён в production-коде нет.

Локальный приоритет кандидатов: canonical exact, verified alias exact, canonical prefix,
verified alias prefix, keyboard layout, strict transliteration, confident phonetic,
controlled normal typo. matchingStrategy=all. Phonetic-кандидаты проходят distance,
collision и make/model hierarchy gate; неоднозначность даёт EMPTY, а не догадку.
Strict transliteration дополнительно перепроверяется в PHP как exact-token channel,
поэтому встроенный Meilisearch prefix matching не превращает `vi` в случайный
`Vision/Vista`. Для кириллических word-token длиной 3–5 создаются отдельные exact
prefix signatures на основе generic spelling equivalences; typo на них выключен.
Если одному prefix signature соответствует несколько сущностей, prefix возвращает EMPTY.
Short-code и numeric token классифицируются отдельно: `бмв X5` сохраняет phonetic
для `бмв`, а `X5` проверяется exact; `камри 2012` аналогично сохраняет name phonetic
и strict numeric token.
Одна обычная опечатка разрешена с 5 символов, две — с 10; SKU, variant_skus,
layout_terms, strict_transliteration_terms, phonetic_terms, phonetic_prefix_terms и
structured_terms исключены из typo tolerance.

Make/Model/Generation используют такой же bounded refill после DB visibility, как
товары: первое окно — 50 кандидатов. Если после MySQL-проверки группа уже дала 10
публичных результатов, дополнительных запросов нет. Недозаполненные группы с полным
предыдущим окном объединяются в один дополнительный multiSearch; максимум три окна
по 50 кандидатов на группу. Порядок relevance сохраняется, DB остаётся финальным
источником публичности.

Страница товаров — 12 карточек. Первое окно содержит 50 кандидатов.
Если после проверки БД недостаточно карточек и одного подтверждённого следующего
результата, допускаются ещё два product-only multiSearch по 50 кандидатов.
Итого максимум три HTTP-запроса и 150 просмотренных кандидатов одного потока.
Повторы между окнами удаляются; карточные связи загружаются один раз в конце.

Для Meilisearch используется cursor-навигация «Назад/Вперёд» без выдуманного total
и числа страниц. Cursor хранит позицию последней показанной записи, направление,
канал и fingerprint запроса/фильтров. Неиспользованная часть окна не теряется.
При исчерпании лимита сканирования показывается «Искать дальше»: это продолжение
проверки, а не утверждение о наличии следующей заполненной страницы.
Как и при обычной offset-навигации, изменения индекса между запросами могут менять порядок.
SQL-поиск сохраняет прежнюю нумерованную пагинацию с точным SQL total.

Ожидаемые ошибки соединения, таймауты и server/gateway errors возвращают SQL-поиск.
Неизвестные ошибки запроса, TypeError и другие ошибки приложения не скрываются.
В журнал пишутся только тип исключения и HTTP status, без запроса, URL или ключей.
CATALOG_SEARCH_TIMEOUT_MS=250 ограничивает один HTTP-запрос витрины;
дочитывание имеет отдельный общий предел в три запроса.

## Aliases и следующий шаг с данными

У VehicleMake и VehicleModel добавлен nullable JSON search_aliases с array cast
и TagsInput «Поисковые названия». До 20 строк по 100 символов, без HTML;
пробелы и пустые строки удаляются, дубли без учёта регистра сворачиваются.
Название, slug, norm_key, import_key, URL и SEO остаются каноническими.

Основная cross-language обработка generated и не требует массового заполнения aliases:
normalization → keyboard layout → strict transliteration → folded metaphone / safe prefix
signature → confidence gate. Meaningful keyboard-layout alternative проходит тот же
strict/phonetic/prefix pipeline внутри того же bounded multiSearch; исходный query всегда
ранжируется выше, а structured/mixed queries не открывают layout guessing.
Generated формы вычисляются только при indexing/reindex и не записываются в MySQL.
`search_aliases` остаются ручным verified exception layer для имён, которые нельзя
безопасно вывести алгоритмически (например, из-за collision/неоднозначности).

Для массовой работы с verified aliases есть deterministic UTF-8 JSON workflow:

~~~sh
php artisan catalog-search:aliases:export storage/app/catalog-search/verified-aliases.json
php artisan catalog-search:aliases:import storage/app/catalog-search/verified-aliases.json --dry-run
php artisan catalog-search:aliases:import storage/app/catalog-search/verified-aliases.json
~~~

Export содержит canonical title, id, norm_key и make context для Model. Import
двухфазный: сначала весь JSON полностью проверяется без writes, затем только при нуле
ошибок весь change plan применяется одной DB transaction. `--dry-run` заканчивается после
первой фазы. Import нормализует и дедуплицирует aliases через те же лимиты, меняет только
search_aliases, идемпотентен и отправляет dependency reindex jobs пакетами только после
успешного commit. Generated phonetic/prefix terms в JSON не экспортируются и автоматически
в aliases не записываются.

На главной Choices ищет label и customProperties.searchText; AJAX списка моделей
дополнен search_aliases. Отображаются canonical названия.
В существующем мобильном макете поле модели скрыто CSS при ширине <=1024px;
этот дизайн в задаче не менялся. Alias марки проверен на 390px, марки и модели — на 1366px.

## Проверки

Обычный PHPUnit/Pest использует sqlite, SCOUT_DRIVER=null,
SCOUT_QUEUE=false, CATALOG_SEARCH_DRIVER=database через env и server overrides.
Живой Meilisearch по умолчанию не нужен.

~~~sh
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan test tests/Feature/Search
docker compose exec -T -e MEILISEARCH_INTEGRATION=1 app php artisan test tests/Feature/Search/MeilisearchIntegrationTest.php
docker compose run --rm --no-deps node npm run build
docker compose stop node
test ! -e public/hot
docker compose exec -T app php artisan test
~~~

Opt-in integration создаёт изолированный случайный dvashop_test_* prefix,
проверяет настройки, English/Russian/mixed/prefix/short-code/SKU/fitments/hidden SKU,
а затем удаляет свои индексы в finally. Реальная MySQL-база fixtures не получает.

## Будущий production rollout — здесь не выполнялся

Нужны отдельные service/volume и резервное копирование индекса, приватная сеть
или внутренний gateway с проверенными DNS/connect timeouts, уникальный master key,
отдельный production prefix, лимиты памяти и диска, health monitoring и worker.
Development key из локального Docker применять в production нельзя.

Сначала оставить CATALOG_SEARCH_DRIVER=database, настроить Scout/queue,
применить миграцию, собрать индексы, проверить counts, задачи индексации и качество.
Потом отдельно разрешить cutover на meilisearch. Быстрый rollback витрины:
CATALOG_SEARCH_DRIVER=database с обновлением конфигурации и процессов.
Публикации и production-изменений эта задача не выполняет.

Официальные API:
[Laravel Scout](https://laravel.com/framework/docs/13.x/scout),
[Meilisearch matching strategy](https://www.meilisearch.com/docs/capabilities/full_text_search/how_to/use_matching_strategy),
[Choices](https://github.com/Choices-js/Choices).

## Dynamic cross-language vehicle names

Vehicle make/model names are enriched at indexing time only. Canonical database values and
`search_aliases` are not rewritten. `search_aliases` remain a verified exception layer.

The search document keeps separate `layout_terms`, `strict_transliteration_terms`, and
`phonetic_terms`. Typo tolerance is disabled for those derived attributes and for SKU fields.
A meaningful keyboard-layout alternative is re-normalized and sent through the same bounded
strict/phonetic/prefix pipeline in the existing multiSearch. The phonetic channel is based on
generic proper-name folding plus `metaphone()` and is accepted only after distance, collision,
and make/model hierarchy checks. Short alphanumeric codes never enter the phonetic channel.
Products carry derived representations from every fitment plus non-searchable
`vehicle_entities` metadata so confidence checks cannot combine make/model tokens from
different fitments.

Developer diagnostics:

```bash
php artisan catalog-search:explain "камри"
php artisan catalog-search:explain "бмв X5"
php artisan catalog-search:audit
```

`catalog-search:explain` prints normalization, token classification (`word`, `short_code`,
`numeric`), per-token channels, layout/transliteration/phonetic/prefix channels,
candidate IDs, and accept/reject reasons. `catalog-search:audit` compares raw Metaphone,
Soundex, selected folded-Metaphone collision profile and canonical 3/4/5-character
safe-prefix ambiguity/false-positive metrics on the current make/model catalog.
