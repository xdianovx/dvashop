<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class YandexFeedRebuilder
{
    public function __construct(
        private readonly YandexFeedState $state,
        private readonly YandexFeedGenerator $generator,
        private readonly YandexFeedValidator $validator,
        private readonly YandexFeedCompressor $compressor,
        private readonly YandexFeedInvalidator $invalidator,
    ) {}

    public function rebuild(): bool
    {
        $lock = fopen($this->state->directory().'/generation.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open generation lock.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return false;
        }
        $this->cleanupTemporaryFiles();
        $temporary = null;
        $gzipTemporary = null;
        $rawPrevious = null;
        $gzipPrevious = null;
        $rawPublished = false;
        $gzipPublished = false;
        try {
            if ($this->invalidator->importInProgress()) {
                return false;
            }
            $revision = $this->state->read()['source_revision'];
            $temporary = $this->state->path().'.tmp.'.bin2hex(random_bytes(12));
            $gzipTemporary = $this->state->gzipPath().'.tmp.'.bin2hex(random_bytes(12));
            $metrics = $this->generator->generate($temporary);
            $counts = $this->validator->validate($temporary);
            if ($counts['offers_count'] !== $metrics['offers_count'] || $counts['categories_count'] !== $metrics['categories_count']) {
                throw new RuntimeException('Generated feed counters do not match validation.');
            }
            $metrics = array_replace($metrics, $this->compressor->compress($temporary, $gzipTemporary));
            if ($this->invalidator->importInProgress()) {
                return false;
            }
            if (is_file($this->state->path())) {
                $rawPrevious = $temporary.'.previous';
                if (! link($this->state->path(), $rawPrevious)) {
                    throw new RuntimeException('Cannot preserve previous feed before publication.');
                }
            }
            if (is_file($this->state->gzipPath())) {
                $gzipPrevious = $gzipTemporary.'.previous';
                if (! link($this->state->gzipPath(), $gzipPrevious)) {
                    throw new RuntimeException('Cannot preserve previous gzip feed before publication.');
                }
            }

            return $this->state->locked(function (array &$state) use (
                $revision,
                $metrics,
                $temporary,
                $gzipTemporary,
                &$rawPublished,
                &$gzipPublished,
            ): bool {
                // Discard a stale build instead of publishing a mixed catalog snapshot.
                if ($state['source_revision'] !== $revision) {
                    return false;
                }
                if (! rename($temporary, $this->state->path())) {
                    throw new RuntimeException('Atomic feed publication failed.');
                }
                $rawPublished = true;
                if (! rename($gzipTemporary, $this->state->gzipPath())) {
                    throw new RuntimeException('Atomic gzip feed publication failed.');
                }
                $gzipPublished = true;
                $state = array_replace($state, $metrics, [
                    'generated_revision' => $revision,
                    'generated_at' => now()->toIso8601String(),
                    'last_error' => null,
                ]);

                return true;
            });
        } catch (Throwable $exception) {
            // Even a state persistence error after rename must retain the old feed.
            if ($gzipPublished) {
                if ($gzipPrevious !== null) {
                    rename($gzipPrevious, $this->state->gzipPath());
                } else {
                    unlink($this->state->gzipPath());
                }
            }
            if ($rawPublished) {
                if ($rawPrevious !== null) {
                    rename($rawPrevious, $this->state->path());
                } else {
                    unlink($this->state->path());
                }
            }
            $this->state->locked(function (array &$state) use ($exception): void {
                // A manual rebuild can fail even when the previous revision was clean.
                $state['source_revision'] = max($state['source_revision'], $state['generated_revision'] + 1);
                $state['last_error'] = $exception->getMessage();
            });
            Log::error('Yandex feed rebuild failed.', ['error' => $exception->getMessage()]);
            throw $exception;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            if ($gzipTemporary !== null && is_file($gzipTemporary)) {
                unlink($gzipTemporary);
            }
            foreach ([$rawPrevious, $gzipPrevious] as $previous) {
                if ($previous !== null && is_file($previous)) {
                    unlink($previous);
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function cleanupTemporaryFiles(): void
    {
        foreach ([$this->state->path().'.tmp.*', $this->state->gzipPath().'.tmp.*'] as $pattern) {
            foreach (glob($pattern) ?: [] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }
}
