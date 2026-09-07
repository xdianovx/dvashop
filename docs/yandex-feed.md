# Yandex YML feed

## Public contract

`GET /feeds/yandex.yml` (`feeds.yandex`) streams the last validated
`storage/app/feeds/yandex.yml`. `GET /feeds/yandex.yml.gz`
(`feeds.yandex.gzip`) streams its gzip representation from
`storage/app/feeds/yandex.yml.gz`. Neither endpoint queries products or rebuilds
on GET. Missing/disabled files return 503. Responses use the appropriate XML or
gzip content type, weak metadata ETag, Last-Modified and conditional 304. Metadata and
body come from one open file descriptor, so an atomic replacement cannot mix
versions within a response. Both routes are stateless: no storefront
session/CSRF cookies are created for robots. The ETag is made only from metadata
of the already opened published inode (device, inode, size,
mtime and ctime). It never hashes feed contents on a request. Opening the file
before `fstat` pins the exact body generation across an atomic replacement;
conditional 304 and HEAD are therefore O(1), while a 200 streams that descriptor
once.

One offer is one purchasable `ProductVariant`, the same entity used by cart and
checkout. ID is `variant-{database id}`; fitments do not multiply offers. The
feed URL selects that exact public variant on the product page before its option
state, price, SKU, stock and cart payload are rendered. Missing, malformed,
foreign or non-public variant IDs return 404. The page's SEO canonical remains
the ordinary product URL without this feed-only query parameter.

| YML data | Existing source |
| --- | --- |
| Shop name/company | ShopSetting.store_name / legal_name, with store_name fallback |
| Shop URL | APP_URL + named `home` route |
| Product URL | Named `products.show` route plus stable `?variant={ProductVariant.id}` deep link |
| Price | StorefrontProductAvailability.effectivePrice (variant price, existing product fallback) |
| Old price | variant.old_price ?? product.old_price, strictly greater than price |
| Availability | StorefrontProductAvailability.isPurchasable for quantity 1 |
| SKU | variant.sku ?: product.sku, omitted when absent |
| Name | Product.title + variant.title; deterministic feed-only 150-character limit |
| Description | description ?: short_description; never SEO description |
| Pictures | Product.mainImage / visibleImages and MediaUrlService; existing public files only |
| Parameters | partType, publicOptionsSnapshot, visible characteristics with units, active fitments |

All prices use `RUR`. Cart promotions do not affect the feed. There is no reliable
part manufacturer field, so no vendor is invented from VehicleMake.
Generic default part illustrations and the UI placeholder are deliberately not
exported as product photographs. Hidden/missing/private files and importer source
URLs are excluded. Real main image is first, followed by ordered gallery images
(up to 10 distinct URLs). An offer without a real photo omits picture elements.

ProductStatus has Draft/Active/Archived; only Active is public. Product has no
separate disabled flag. Variant uses is_active. Category has is_active and soft
deletion, not independent draft/archive enums. All category ancestors must be
active and not deleted. PublicProductCategoryVisibility shares this rule with
storefront product queries and purchase checks. Its recursive subquery executes
inside the existing product query (MySQL 8 / SQLite), avoiding extra storefront
queries. Orphan/cyclic category branches are excluded. Offers without a public
category are skipped, since YML requires a valid category reference.

OutOfStock and finite InStock quantity below one are excluded. PreOrder and
unlimited stock follow the existing purchase service. Every included offer is
therefore available=true; existence alone is never the availability rule.

## Generation and durable state

`php artisan feed:yandex:rebuild` builds and prints JSON metrics/state;
`php artisan feed:yandex:rebuild --status` only reads state. A deferred or failed
build returns nonzero. Reported duration is generation time; validation follows
generation. Memory is PHP process peak, not file size.

XMLWriter streams variants in chunks of 200 with eager-loaded relations. Only
the category tree and one variant chunk are retained. XMLReader validates the
document with one-offer DOM validation: UTF-8, structure, unique stable IDs,
category references/ancestry, positive prices, RUR and absolute URLs no longer
than 512 characters. Names and descriptions are UTF-8-safe truncated to 150 and
3,000 characters. No DTD or external XML resource is fetched. The validated raw
file is streamed once into gzip and the decompressed SHA-256 is checked against
that same raw candidate; the catalog is never generated a second time. Each
final file is replaced by same-filesystem rename only after successful
validation, gzip verification and a source revision check. Any publication or
state error rolls both files back to the prior validated pair.

`yml_catalog@date` is captured once immediately before the catalog scan and is
the generation start, serialized as RFC3339 with seconds and timezone offset
(`2026-09-07T12:34:56+03:00`). The validator rejects malformed and future
timestamps. State `generated_at` is the later successful publication time;
`generation_started_at` preserves the first timestamp separately.

Durable `state.json` records source_revision, generated_revision, both generation
timestamps, last_error, raw/gzip metrics and a pending-dispatch token/lease.
State updates use a separate stable flock inode and atomic JSON replacement.
`generation.lock` allows at most one active generator across commands/workers;
process death releases the OS lock. Temporary hard links preserve the old raw
and gzip files if state persistence fails after publication. Normal failures
remove only that build's temporary files, retain the previous pair and leave
dirty state plus last_error. A hard-killed process can leave unreferenced
`.tmp.*` files, never served by either endpoint; the next process holding the
exclusive generation lock removes them before rebuilding and never removes
either lock file.

Changes during generation increment the source revision. A stale candidate is
discarded rather than published; a queued follow-up builds the newer revision.
Feed/state/lock files are runtime data and are not committed.

## Automatic updates and import

AppServiceProvider registers a narrowly table-filtered QueryExecuted listener.
It registers invalidation on the writing connection's afterCommit callback.
Rolled-back transactions do not invalidate. This intentionally covers model
events, saveQuietly/deleteQuietly, bulk archive/update and option pivot writes.
SQL values/bindings are neither parsed nor logged. Reads, orders and unrelated
tables do not invalidate. Direct SQL writes outside this Laravel application
must be followed by an explicit rebuild.

Relevant tables: products, product_variants, product_categories, product_images,
part_types, product_fitments, vehicle_generations/models/makes,
product_option_values/groups, product_variant_option_values,
product_characteristics, shop_settings and import_runs.

Burst changes coalesce into one pending delayed job (30 seconds). The persisted
900-second dispatch lease recovers lost dispatches without depending on cache
survival. Jobs use an asynchronous connection, 3 attempts and 60/180/300-second
backoff, with a job-specific 1,200-second timeout and `failOnTimeout=true`.
The dedicated `yandex-feed` database connection uses the same database and jobs
table as the existing `database` connection, but reserves only the
`yandex-feed` queue and has a 1,500-second `retry_after`. Scheduling refuses a
configured numeric retry interval that is not greater than the job timeout. Its
worker has `--timeout=600`, but Laravel uses the job-specific 1,200-second timeout
when present. A terminal failure forces dirty state and records its error. A
live generation is additionally protected by flock. Scheduler fallback checks
every ten minutes; it dispatches only for dirty/missing raw or gzip files and
never builds a clean feed.

The existing `database` connection keeps its original 660-second retry lease.
The existing worker remains restricted to `default,imports,imports-images`, so
it cannot reserve a Yandex job and the recovery semantics of Bitrix, mail and
import jobs do not change. Yandex jobs dispatch explicitly to both the
`yandex-feed` connection and queue. They require this separate worker:

```bash
php artisan queue:work yandex-feed --queue=yandex-feed --sleep=3 --tries=3 --timeout=600
```

Running/RunningRows/ProcessingImages/Paused catalog imports block publication
and queue dispatch. Existing ImportStatusService finalizes rows with
markRowsDone; queued image jobs use imageProcessed/imageFailed, then Done only
after processed_images + failed_images reaches queued_images. The feed does not
change these semantics: failed image downloads count as terminal according to
the existing importer and only successfully stored visible files are exported.
Bulk auto-archive and all row/image updates mark revisions but do not cause
intermediate full rebuilds. The final Done write schedules one rebuild including
new variants/options, final photos, stock, prices and archived records. Manual
products are read normally and never written by the feed.

If the latest actually started import ends Failed/Canceled, publication remains
blocked to avoid exporting partially committed rows; a later successful import
unblocks it. An upload canceled before starting does not block. Paused imports
can be resumed through the existing admin workflow. Historical failures before
a later successful import do not block future rebuilds.

## Runtime requirements (for a later deployment)

- Persist and share `/var/www/html/storage/app/feeds` across app, queue,
  scheduler and the dedicated Yandex worker containers and releases. The
  repository's local Compose binds the same host project directory
  (`./:/var/www/html`) into all four, so it already shares the directory locally
  and retains it across container recreation.
  Production container/deploy configuration is not stored in this repository:
  the workflow only invokes the external `deploy <SHA>` command. That external
  deployment must mount one host-persistent feed directory at exactly
  `/var/www/html/storage/app/feeds` in all four containers and preserve it when
  switching releases. It must also add and supervise the dedicated Yandex
  worker above. These are explicit production deployment actions, not
  assumptions made by the application.
- The shared filesystem must support POSIX flock, hard links and atomic
  same-directory rename; do not put state and feed on independent per-container
  disks or an object-store disk.
- Ensure the application user can write the directory and public storage images
  are served. Reserve space for the old feed and a new candidate.
- Keep APP_URL and existing shop settings correct; no production host is coded.
- Safe rollout defaults: YANDEX_FEED_ENABLED=false,
  YANDEX_FEED_QUEUE_CONNECTION=yandex-feed, YANDEX_FEED_QUEUE=yandex-feed and
  YANDEX_FEED_QUEUE_RETRY_AFTER=1500. No global DB_QUEUE_RETRY_AFTER change, new
  secret or production value is required. Absence of the feature variable
  cannot activate the filesystem feed during an automatic deploy.
- Enable `YANDEX_FEED_ENABLED=true` manually only after the persistent shared
  mount is installed, permissions are verified, app/queue/scheduler/Yandex
  worker containers are recreated, and a cross-container read/write visibility
  check passes.
- Keep the existing queue worker command unchanged and run the dedicated Yandex
  worker separately. A future deployment must restart long-lived workers
  normally to load the new listener/job code and verify the Yandex worker's
  process supervisor allows more than 1,200 seconds before forced termination;
  this task does not deploy or restart production services.
- XMLWriter, XMLReader, DOM and mbstring are required (present in the app image).
- No migrations or frontend build are required. No Yandex registration or
  external validation request is made by the application.

Tests default this feature off via phpunit.xml; YandexFeedTest enables it only
with a unique temporary state directory, fake queue/public disk and fake HTTP.

## Local acceptance measurement, 2026-09-07

On the existing local MySQL catalog (15,791 products / 378,984 variants), the
command successfully generated and validated all 378,984 offers in 9 categories;
0 skipped offers. Raw size: 866,275,001 bytes (826.14 MiB). Verified gzip size:
11,085,669 bytes (10.57 MiB), safely below the 512 MiB URL-feed limit for Yandex
Direct, so sharding is not required. Generation duration: 352.937 seconds. PHP
peak during generation: 63,438,848 bytes (60.5 MiB).
source_revision=generated_revision=1, dirty=false, last_error=null.
This is a local measurement, not a guarantee for other hardware/catalog sizes;
the 1,200-second job timeout provides current growth margin and remains below the
dedicated connection's 1,500-second queue retry interval.

The existing local Nginx endpoints returned 200 with XML UTF-8 / application-gzip
and their exact lengths. The downloaded gzip passed an integrity decompression
test; automated endpoint tests cover ETag and Last-Modified conditional 304 for
both files without cookies. No external Yandex request/registration or production
deployment was performed.

After the O(1) cache hardening, a process-local feature-enabled probe against
that existing 866,275,001-byte raw file produced If-Modified-Since 304 in about
1.0 ms, If-None-Match 304 in about 0.1 ms, and HEAD 200 in about 0.1 ms with the
exact Content-Length. A separate instrumented stream regression proves both 304
paths and HEAD read zero body bytes, while a raw 200 reads each body byte once.

Actual category excerpt:

```xml
<category id="1">Кузовные детали</category>
<category id="2" parentId="1">Ремонтные элементы кузова</category>
<category id="3" parentId="2">Пороги</category>
<category id="4" parentId="2">Арки</category>
```

Actual generated offer excerpt (only the local host is sanitized; description
and additional structured parameters omitted here for brevity):

```xml
<offer id="variant-1" available="true">
  <url>https://shop.example.test/products/porog-dlia-acura-integra-3-1993-2001-sedan-4-dv-d3ddf5b6?variant=1</url>
  <price>1790.00</price>
  <currencyId>RUR</currencyId>
  <categoryId>3</categoryId>
  <name>Порог для Acura Integra 3 1993 - 2001 Седан 4 дв. — Профиль: Полный; Положение: Левый + Правый; Материал: Оцинковка; Толщина металла: 1 мм</name>
  <param name="Тип детали">Порог</param>
  <param name="Профиль">Полный</param>
  <param name="Положение">Левый + Правый</param>
  <param name="Материал">Оцинковка; Сталь ГОСТ 19904-90</param>
  <param name="Толщина металла">1 мм</param>
  <param name="Марка автомобиля">Acura</param>
</offer>
```

This particular variant has no SKU, crossed price or real product photo, so
vendorCode/oldprice/picture are absent. Generic default images are not substituted.
