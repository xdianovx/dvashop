<?php

namespace App\Services\Media;

use App\Models\VehicleGeneration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Lightweight screening or optional visual verification, bound to exact pixels and policy. */
class VehicleImageQuality
{
    public function vehicle(VehicleGeneration $g): array
    {
        $g->loadMissing('model.make');

        return ['id' => $g->id, 'make' => $g->model?->make?->title, 'model' => $g->model?->title,
            'generation' => $g->title, 'years' => $g->years_label, 'body' => $g->body,
            'platform' => $g->platform, 'restyling' => $g->restyling];
    }

    public function policy(): string
    {
        $policy = config('vehicle_images');
        unset($policy['verifier_token'], $policy['max_candidates']);

        return hash('sha256', json_encode([1, $policy, config('media.allowed_mimes'), config('media.max_source_size')], JSON_THROW_ON_ERROR));
    }

    public function stored(VehicleGeneration $g, bool $verify = false): array
    {
        if (! $g->image || ! Storage::disk('public')->exists($g->image)) {
            return ['state' => 'missing', 'reason' => 'file_missing'];
        }

        try {
            if (Storage::disk('public')->size($g->image) > config('media.max_source_size')) {
                return ['state' => 'rejected', 'reason' => 'oversized_local_file'];
            }

            return $this->assess($g, Storage::disk('public')->get($g->image), $verify);
        } catch (Throwable) {
            return ['state' => 'unverified', 'reason' => 'local_file_unreadable'];
        }
    }

    public function assess(VehicleGeneration $g, string $bytes, bool $verify = true, bool $refresh = false): array
    {
        $vehicle = $this->vehicle($g);
        $base = ['image_sha256' => hash('sha256', $bytes), 'vehicle_sha256' => hash('sha256', json_encode($vehicle, JSON_THROW_ON_ERROR)),
            'policy' => $this->policy(), 'validation_level' => config('vehicle_images.verifier_url') ? 'visual' : 'light'];
        foreach (['make', 'model', 'generation', 'years', 'body'] as $required) {
            if (trim((string) ($vehicle[$required] ?? '')) === '') {
                return $base + ['state' => 'unverified', 'reason' => 'incomplete_vehicle_identity'];
            }
        }
        if (! $refresh && ($receipt = $this->cached($base))) {
            return $receipt;
        }
        $contentOnly = array_replace($base, ['vehicle_sha256' => '*']);
        if (! $refresh && ($receipt = $this->cached($contentOnly)) && $receipt['state'] === 'rejected'
            && in_array($receipt['reason'], $this->genericRejections(), true)) {
            return array_replace($receipt, ['vehicle_sha256' => $base['vehicle_sha256']]);
        }
        $dimensions = @getimagesizefromstring($bytes);
        if (strlen($bytes) > config('media.max_source_size') || ! $dimensions
            || ! in_array($dimensions['mime'] ?? '', config('media.allowed_mimes'), true)
            || $dimensions[0] < config('vehicle_images.min_width') || $dimensions[1] < config('vehicle_images.min_height')
            || config('vehicle_images.max_pixels') < $dimensions[0] * $dimensions[1]) {
            return $this->remember($base + ['state' => 'rejected', 'reason' => 'invalid_raster_or_dimensions']);
        }
        if (! config('vehicle_images.verifier_url')) {
            $light = app(VehicleImageLightCheck::class)->analyze($bytes);
            $result = $base + $light + ['vehicle_match_verified' => false];

            return $light['state'] === 'unverified' ? $result : $this->remember($result);
        }
        if (! $verify) {
            return $base + ['state' => 'unverified', 'reason' => 'visual_verification_required'];
        }
        try {
            $http = Http::acceptJson()->connectTimeout(3)->timeout(15);
            if (config('vehicle_images.verifier_token')) {
                $http = $http->withToken(config('vehicle_images.verifier_token'));
            }
            $response = $http->post(config('vehicle_images.verifier_url'), [
                'schema_version' => 1, 'revision' => config('vehicle_images.verifier_revision'),
                'vehicle' => $vehicle, 'vehicle_sha256' => $base['vehicle_sha256'],
                'image_sha256' => $base['image_sha256'], 'image_mime' => $dimensions['mime'], 'image_base64' => base64_encode($bytes),
            ]);
            $result = $response->json();
            if (! $response->successful() || ! is_array($result) || ($result['schema_version'] ?? null) !== 1
                || ($result['image_sha256'] ?? null) !== $base['image_sha256'] || ($result['vehicle_sha256'] ?? null) !== $base['vehicle_sha256']
                || ($result['revision'] ?? null) !== config('vehicle_images.verifier_revision')) {
                return $base + ['state' => 'unverified', 'reason' => 'invalid_verifier_response'];
            }
            $certain = is_numeric($result['confidence'] ?? null) && $result['confidence'] >= config('vehicle_images.min_confidence') && $result['confidence'] <= 1;
            if (($result['verdict'] ?? null) === 'reject' && $certain && in_array($result['reason'] ?? null,
                ['not_vehicle_photo', 'wrong_vehicle', 'advertisement', 'logo', 'screenshot', 'wrong_generation', 'wrong_body'], true)) {
                return $this->remember($base + ['state' => 'rejected', 'reason' => $result['reason']]);
            }
            $checks = ['vehicle_exterior_photo', 'whole_vehicle', 'not_advertisement', 'not_logo', 'not_screenshot'];
            foreach (['make', 'model', 'generation', 'years', 'body', 'platform', 'restyling'] as $field) {
                if (trim((string) ($vehicle[$field] ?? '')) !== '') {
                    $checks[] = 'matches_'.$field;
                }
            }
            $positive = ($result['verdict'] ?? null) === 'accept' && $certain;
            foreach ($checks as $check) {
                $positive = $positive && ($result['checks'][$check] ?? null) === true;
            }

            return $positive ? $this->remember($base + ['state' => 'accepted', 'reason' => 'verified_vehicle_photo'])
                : $base + ['state' => 'unverified', 'reason' => 'insufficient_visual_evidence'];
        } catch (Throwable) {
            return $base + ['state' => 'unverified', 'reason' => 'verifier_unavailable'];
        }
    }

    /** A local resize inherits evidence from its exact verified source, without another HTTP call. */
    public function inherit(VehicleGeneration $g, string $source, string $derived, array $provenance = []): void
    {
        $receipt = $this->assess($g, $source, false);
        if ($receipt['state'] !== 'accepted') {
            throw new VehicleImageNotFound('Нет положительной проверки исходной фотографии.');
        }
        $receipt['origin_sha256'] = $receipt['image_sha256'];
        $receipt['image_sha256'] = hash('sha256', $derived);
        $receipt['provenance'] = array_intersect_key($provenance, array_flip(['source_url', 'page_url', 'image_url']));
        $this->remember($receipt);
    }

    public function fingerprint(VehicleGeneration $g): string
    {
        $file = $g->image && Storage::disk('public')->exists($g->image) ? @hash_file('sha256', Storage::disk('public')->path($g->image)) : null;

        return hash('sha256', json_encode([$this->vehicle($g), $g->only(['image', 'image_checksum', 'image_source_url', 'image_conversions', 'updated_at']), $file], JSON_THROW_ON_ERROR));
    }

    private function path(array $receipt): string
    {
        return 'vehicle-image-reviews/'.hash('sha256', implode(':', [$receipt['policy'], $receipt['vehicle_sha256'], $receipt['image_sha256']])).'.json';
    }

    private function cached(array $base): ?array
    {
        try {
            $path = $this->path($base);
            if (! Storage::disk('local')->exists($path)) {
                return null;
            }
            $doc = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
            $body = $doc['receipt'];
            if (hash_equals(hash_hmac('sha256', json_encode($body, JSON_THROW_ON_ERROR), config('app.key')), $doc['seal'])
                && array_intersect_key($body, $base) === $base && in_array($body['state'], ['accepted', 'rejected'], true)) {
                return $body;
            }
        } catch (Throwable) {
            // Missing, damaged or edited evidence never approves a photo.
        }

        return null;
    }

    private function remember(array $receipt): array
    {
        $write = function (array $value): void {
            if (! Storage::disk('local')->put($this->path($value), json_encode(['receipt' => $value,
                'seal' => hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), config('app.key'))], JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('Не удалось сохранить результат проверки изображения.');
            }
        };
        $write($receipt);
        if ($receipt['state'] === 'rejected' && in_array($receipt['reason'], $this->genericRejections(), true)) {
            $write(array_replace($receipt, ['vehicle_sha256' => '*']));
        }

        return $receipt;
    }

    private function genericRejections(): array
    {
        return ['logo', 'advertisement', 'screenshot', 'not_vehicle_photo', 'invalid_raster_or_dimensions',
            'flat_graphic_or_placeholder'];
    }
}
