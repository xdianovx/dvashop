<?php

namespace App\Services\Media;

/** Bounded GD checks, not a classifier of vehicle identity. */
class VehicleImageLightCheck
{
    public function analyze(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return ['state' => 'rejected', 'reason' => 'invalid_raster_or_dimensions'];
        }
        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $small = imagecreatetruecolor(96, 64);
            imagefill($small, 0, 0, 0xFFFFFF);
            imagecopyresampled($small, $image, 0, 0, 0, 0, 96, 64, $width, $height);
            $histogram = [];
            $flat = 0;
            $previousRow = [];
            for ($y = 0; $y < 64; $y++) {
                $row = [];
                for ($x = 0; $x < 96; $x++) {
                    $c = imagecolorat($small, $x, $y);
                    $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                    $bucket = (($rgb[0] >> 4) << 8) | (($rgb[1] >> 4) << 4) | ($rgb[2] >> 4);
                    $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
                    $row[] = $rgb;
                    if ($x && $y) {
                        $delta = 0;
                        foreach (range(0, 2) as $channel) {
                            $delta += abs($rgb[$channel] - $row[$x - 1][$channel]) + abs($rgb[$channel] - $previousRow[$x][$channel]);
                        }
                        $flat += $delta <= 12 ? 1 : 0;
                    }
                }
                $previousRow = $row;
            }
            imagedestroy($small);
            $entropy = 0;
            foreach ($histogram as $count) {
                $p = $count / 6144;
                $entropy -= $p * log($p, 2);
            }
            $metrics = ['entropy' => round($entropy, 3), 'dominant' => round(max($histogram) / 6144, 3),
                'flat' => round($flat / (95 * 63), 3), 'colors' => count($histogram), 'aspect' => round($width / $height, 3)];
            if ($metrics['aspect'] < 0.75 || $metrics['aspect'] > 2.6) {
                return ['state' => 'unverified', 'reason' => 'unsupported_photo_shape', 'metrics' => $metrics];
            }
            // Combined evidence of a flat graphic, rather than a ban on white-background car photos.
            if ($entropy < 0.5 || ($entropy < 2 && $metrics['dominant'] > 0.75 && count($histogram) < 120)) {
                return ['state' => 'rejected', 'reason' => 'flat_graphic_or_placeholder', 'metrics' => $metrics];
            }
            if ($entropy >= 5.5 && $entropy < 9.5 && $metrics['dominant'] < 0.42 && $metrics['flat'] < 0.65 && count($histogram) >= 120) {
                return ['state' => 'accepted', 'reason' => 'photo_like_light_checks', 'metrics' => $metrics];
            }

            return ['state' => 'unverified', 'reason' => 'inconclusive_light_checks', 'metrics' => $metrics];
        } finally {
            imagedestroy($image);
        }
    }
}
