<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use RuntimeException;
use Throwable;

class YandexFeedCompressor
{
    /** @return array{gzip_size:int,raw_sha256:string,gzip_sha256:string} */
    public function compress(string $source, string $destination): array
    {
        $input = fopen($source, 'rb');
        $output = gzopen($destination, 'wb6');
        if ($input === false || $output === false) {
            is_resource($input) && fclose($input);
            is_resource($output) && gzclose($output);
            throw new RuntimeException('Cannot open YML compression streams.');
        }

        $sourceHash = hash_init('sha256');
        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read YML during compression.');
                }
                if ($chunk !== '') {
                    hash_update($sourceHash, $chunk);
                    if (gzwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Cannot write complete gzip YML.');
                    }
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }

        $rawHash = hash_final($sourceHash);
        $verifiedHash = hash_init('sha256');
        $gzip = gzopen($destination, 'rb');
        if ($gzip === false) {
            throw new RuntimeException('Cannot verify gzip YML.');
        }
        try {
            while (! gzeof($gzip)) {
                $chunk = gzread($gzip, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot decompress gzip YML during verification.');
                }
                hash_update($verifiedHash, $chunk);
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Gzip YML verification failed.', previous: $exception);
        } finally {
            gzclose($gzip);
        }
        if (! hash_equals($rawHash, hash_final($verifiedHash))) {
            throw new RuntimeException('Gzip YML content differs from validated raw YML.');
        }

        $gzipHash = hash_file('sha256', $destination);
        $gzipSize = filesize($destination);
        if (! is_string($gzipHash) || ! is_int($gzipSize) || $gzipSize <= 0) {
            throw new RuntimeException('Cannot inspect generated gzip YML.');
        }

        return ['gzip_size' => $gzipSize, 'raw_sha256' => $rawHash, 'gzip_sha256' => $gzipHash];
    }
}
