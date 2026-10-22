<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Explicit fake visual oracle; solid test rasters are not real vehicle photographs. */
function test_vehicle_verdict(Request $request, array $overrides = []): array
{
    $checks = array_fill_keys(['vehicle_exterior_photo', 'whole_vehicle', 'not_advertisement', 'not_logo', 'not_screenshot',
        'matches_make', 'matches_model', 'matches_generation', 'matches_years', 'matches_body', 'matches_platform', 'matches_restyling'], true);

    return array_replace_recursive(['schema_version' => 1, 'revision' => $request['revision'],
        'image_sha256' => $request['image_sha256'], 'vehicle_sha256' => $request['vehicle_sha256'],
        'verdict' => 'accept', 'confidence' => 0.99, 'checks' => $checks], $overrides);
}

function fake_vehicle_photo_verifier(?callable $answer = null, bool $smallFixtures = false): void
{
    config(['vehicle_images.verifier_url' => 'https://vision.example.test/check']);
    if ($smallFixtures) {
        config(['vehicle_images.min_width' => 1, 'vehicle_images.min_height' => 1]);
    }
    Http::fake(['https://vision.example.test/check' => function ($request) use ($answer) {
        return Http::response($answer ? $answer($request) : test_vehicle_verdict($request));
    }]);
}

function test_image_binary(string $format = 'jpeg', int $width = 32, int $height = 24): string
{
    $image = imagecreatetruecolor($width, $height);
    $background = imagecolorallocate($image, 210, 80, 40);
    imagefilledrectangle($image, 0, 0, $width, $height, $background);

    ob_start();

    match (strtolower($format)) {
        'png' => imagepng($image),
        'webp' => imagewebp($image, null, 80),
        default => imagejpeg($image, null, 85),
    };

    $binary = (string) ob_get_clean();
    imagedestroy($image);

    return $binary;
}
