<?php

namespace CodePulse\MediaUpload;

use CodePulse\MediaUpload\Console\PruneQuarantineCommand;
use CodePulse\MediaUpload\Contracts\VirusScanner;
use CodePulse\MediaUpload\Handlers\PdfHandler;
use CodePulse\MediaUpload\Handlers\SvgHandler;
use CodePulse\MediaUpload\Http\ServeFileController;
use CodePulse\MediaUpload\Pipeline\ScanVirus;
use CodePulse\MediaUpload\Pipeline\SniffMimeType;
use CodePulse\MediaUpload\Pipeline\StoreFile;
use CodePulse\MediaUpload\Pipeline\ValidateUpload;
use CodePulse\MediaUpload\Scanning\ClamAvScanner;
use CodePulse\MediaUpload\Support\MimeMap;
use CodePulse\MediaUpload\Support\ServePolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MediaUploadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media-upload.php', 'media-upload');

        $this->app->singleton(MediaUploader::class);

        $config = fn (Application $app, ?string $key = null, mixed $default = null) => $app['config']->get(
            'media-upload'.($key === null ? '' : '.'.$key),
            $default,
        );

        $this->app->bind(MimeMap::class, fn (Application $app) => new MimeMap($config($app, 'mime_map', [])));

        $this->app->bind(ValidateUpload::class, function (Application $app) use ($config) {
            $blocked = array_map('strtolower', $config($app, 'blocked_extensions', []));

            if ($config($app, 'svg.enabled', false)) {
                $blocked = array_values(array_diff($blocked, ['svg']));
            }

            return new ValidateUpload($app->make(MimeMap::class), $blocked);
        });

        $this->app->bind(ServePolicy::class, fn (Application $app) => new ServePolicy(
            $config($app, 'mime_map', []),
            $config($app, 'serve.inline_mimes', []),
            (bool) $config($app, 'serve.cache', true),
        ));

        $this->app->bind(SniffMimeType::class, fn (Application $app) => new SniffMimeType($app->make(MimeMap::class)));

        $this->app->bind(VirusScanner::class, function (Application $app) use ($config) {
            $scanner = $config($app, 'virus_scan.scanner');

            if ($scanner === ClamAvScanner::class) {
                return new ClamAvScanner(
                    $config($app, 'virus_scan.clamav.socket', 'unix:///var/run/clamav/clamd.ctl'),
                    (int) $config($app, 'virus_scan.clamav.timeout', 30),
                    (int) $config($app, 'virus_scan.clamav.max_size', 25 * 1024 * 1024),
                );
            }

            return $app->make($scanner ?: Scanning\NullScanner::class);
        });

        $this->app->bind(ScanVirus::class, fn (Application $app) => new ScanVirus(
            $app->make(VirusScanner::class),
            (bool) $config($app, 'virus_scan.fail_closed', true),
        ));

        $this->app->bind(StoreFile::class, fn (Application $app) => new StoreFile(
            $app['filesystem'],
            $app['config']->get('filesystems.disks', []),
            $app->make(ServePolicy::class),
            $app->bound('cache') ? $app['cache'] : null,
            $config($app, 'filename.generator', 'ulid'),
        ));

        $this->app->bind(SvgHandler::class, fn (Application $app) => new SvgHandler((bool) $config($app, 'svg.enabled', false)));
        $this->app->bind(PdfHandler::class, fn (Application $app) => new PdfHandler($config($app, 'pdf.blocked_keywords', [])));

        $this->app->bind(UploadProcessor::class, fn (Application $app) => new UploadProcessor(
            container: $app,
            validate: $app->make(ValidateUpload::class),
            sniff: $app->make(SniffMimeType::class),
            scan: $app->make(ScanVirus::class),
            store: $app->make(StoreFile::class),
            hooks: $app->make(Pipeline\HookRunner::class),
            events: $app['events'],
            filesystem: $app['filesystem'],
            handlers: $config($app, 'handlers', []),
            maxOriginalNameLength: (int) $config($app, 'filename.max_original_length', 200),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/media-upload.php' => config_path('media-upload.php'),
            ], 'media-upload-config');

            $this->commands([PruneQuarantineCommand::class]);
        }

        if (config('media-upload.serve.enabled', true)) {
            Route::get(config('media-upload.serve.route', 'media-upload/files/{path}'), ServeFileController::class)
                ->where('path', '.*')
                // The signature check is always first; serve.middleware can only add to it.
                ->middleware(array_values(array_unique(array_merge(
                    [ValidateSignature::class],
                    (array) config('media-upload.serve.middleware', []),
                ))))
                ->name(config('media-upload.serve.name', 'media-upload.serve'));
        }
    }
}
