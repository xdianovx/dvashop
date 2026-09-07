<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use Closure;
use RuntimeException;

class YandexFeedState
{
    public function directory(): string
    {
        $directory = (string) config('yandex-feed.directory');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create feed directory.');
        }

        return $directory;
    }

    public function path(): string
    {
        return $this->directory().'/yandex.yml';
    }

    public function gzipPath(): string
    {
        return $this->directory().'/yandex.yml.gz';
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        return $this->locked(fn (array &$state): array => $state, false);
    }

    /**
     * A stable lock inode plus atomic state replacement survives process crashes.
     * The directory must be shared/persistent, just like the generated feed itself.
     *
     * @template T
     *
     * @param  Closure(array<string, mixed>&): T  $callback
     * @return T
     */
    public function locked(Closure $callback, bool $write = true): mixed
    {
        $lock = fopen($this->directory().'/state.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock feed state.');
        }
        $path = $this->directory().'/state.json';
        $temporary = null;
        try {
            $state = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [
                'source_revision' => 1, 'generated_revision' => 0, 'generated_at' => null,
                'generation_started_at' => null,
                'last_error' => null, 'categories_count' => 0, 'offers_count' => 0,
                'skipped_offers_count' => 0, 'skip_reasons' => [], 'file_size' => 0,
                'duration' => 0, 'memory_peak_bytes' => 0, 'gzip_size' => 0,
                'scheduled_token' => null, 'scheduled_until' => 0,
            ];
            $result = $callback($state);
            if ($write) {
                $temporary = $path.'.tmp.'.bin2hex(random_bytes(8));
                $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if (file_put_contents($temporary, $json) !== strlen($json) || ! rename($temporary, $path)) {
                    throw new RuntimeException('Cannot persist feed state.');
                }
            }

            return $result;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $state = $this->read();
        $state['dirty'] = $state['source_revision'] > $state['generated_revision']
            || ! is_file($this->path()) || ! is_file($this->gzipPath());
        $state['final_path'] = $this->path();
        $state['gzip_path'] = $this->gzipPath();

        return $state;
    }
}
