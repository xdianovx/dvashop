<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Feeds\YandexFeedInvalidator;
use App\Services\Feeds\YandexFeedRebuilder;
use App\Services\Feeds\YandexFeedState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RebuildYandexFeedJob implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 1200;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [60, 180, 300];

    public function __construct(public string $dispatchToken) {}

    public function handle(YandexFeedInvalidator $invalidator, YandexFeedRebuilder $rebuilder, YandexFeedState $state): void
    {
        $invalidator->releaseDispatch($this->dispatchToken);
        if (! config('yandex-feed.enabled') || ! $state->status()['dirty'] || $invalidator->importInProgress()) {
            return;
        }
        $rebuilder->rebuild();
        // Covers edits made while generating, including a lost/coalesced dispatch.
        $invalidator->dispatchIfNeeded();
    }

    public function failed(?Throwable $exception): void
    {
        app(YandexFeedState::class)->locked(function (array &$state) use ($exception): void {
            if ($state['scheduled_token'] === $this->dispatchToken) {
                $state['scheduled_token'] = null;
                $state['scheduled_until'] = 0;
            }

            // A timeout can interrupt publication cleanup. Force a fresh validated
            // generation while the last complete pair remains available.
            $state['source_revision'] = max(
                (int) $state['source_revision'],
                (int) $state['generated_revision'] + 1,
            );
            $state['last_error'] = 'Yandex feed job failed: '.($exception?->getMessage() ?? 'unknown queue failure');
        });
    }
}
