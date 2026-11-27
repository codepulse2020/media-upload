<?php

namespace CodePulse2020\MediaUpload;

use CodePulse2020\MediaUpload\Console\PruneCommand;
use CodePulse2020\MediaUpload\Contracts\VirusScanner;
use CodePulse2020\MediaUpload\Handlers\PdfHandler;
use CodePulse2020\MediaUpload\Handlers\SvgHandler;
use CodePulse2020\MediaUpload\Pipeline\ScanVirus;
use CodePulse2020\MediaUpload\Pipeline\SniffMimeType;
use CodePulse2020\MediaUpload\Pipeline\StoreFile;
use CodePulse2020\MediaUpload\Pipeline\ValidateUpload;
use CodePulse2020\MediaUpload\Scanning\ClamAvScanner;
use CodePulse2020\MediaUpload\Support\MimeMap;
use CodePulse2020\MediaUpload\Support\ServePolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/** @internal */
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
            (string) $config($app, 'public.directory', 'public'),
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
            $config($app, 'public.cache_control'),
            (int) $config($app, 'locks.wait', 10),
            (array) $config($app, 'managed_directories', []),
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

            $this->commands([PruneCommand::class]);
        }
    }
}
