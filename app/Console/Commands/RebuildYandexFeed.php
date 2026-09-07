<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Feeds\YandexFeedInvalidator;
use App\Services\Feeds\YandexFeedRebuilder;
use App\Services\Feeds\YandexFeedState;
use Illuminate\Console\Command;
use Throwable;

class RebuildYandexFeed extends Command
{
    protected $signature = 'feed:yandex:rebuild {--status : Show persisted state without rebuilding}';

    protected $description = 'Atomically rebuild the public Yandex YML feed';

    public function handle(YandexFeedRebuilder $rebuilder, YandexFeedState $state, YandexFeedInvalidator $invalidator): int
    {
        try {
            if (! $this->option('status') && ! $rebuilder->rebuild()) {
                $invalidator->dispatchIfNeeded();
                $this->warn('Rebuild deferred: active import, concurrent build or newer source revision.');

                return self::FAILURE;
            }
            $this->line(json_encode($state->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
