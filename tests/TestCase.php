<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\CollectionEngine;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MEILISEARCH_INTEGRATION') === '1') {
            return;
        }

        $this->app->make(EngineManager::class)->extend(
            'meilisearch',
            static fn (): CollectionEngine => new CollectionEngine,
        );
    }
}
