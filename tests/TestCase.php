<?php

namespace CodePulse2020\MediaUpload\Tests;

use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\MediaUploadServiceProvider;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Fixtures::reset();
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [MediaUploadServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function defineRoutes($router): void
    {
        MediaUpload::routes();
    }
}
