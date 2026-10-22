<?php

return [
    // Optional future local verifier. Empty URL uses bounded GD checks, without AI.
    'light_revision' => '1', // Bump when changing lightweight rules to invalidate signed evidence/plans.
    'verifier_url' => env('VEHICLE_IMAGE_VERIFIER_URL'),
    'verifier_token' => env('VEHICLE_IMAGE_VERIFIER_TOKEN'),
    'verifier_revision' => env('VEHICLE_IMAGE_VERIFIER_REVISION', '1'),
    'min_confidence' => 0.98,
    'min_width' => 320,
    'min_height' => 180,
    'max_pixels' => 16000000,
    'max_candidates' => 3,
];
