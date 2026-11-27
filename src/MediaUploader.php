<?php

namespace CodePulse2020\MediaUpload;

use Closure;
use CodePulse2020\MediaUpload\Contracts\ImageTransformer;
use CodePulse2020\MediaUpload\Contracts\Stage;
use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Pipeline\HookRunner;
use CodePulse2020\MediaUpload\Exceptions\InvalidUploadTokenException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\Http\ServeFileController;
use CodePulse2020\MediaUpload\Support\DiskGuard;
use CodePulse2020\MediaUpload\Support\FilenameSanitizer;
use CodePulse2020\MediaUpload\Support\Locks;
use CodePulse2020\MediaUpload\Support\MimeMap;
use CodePulse2020\MediaUpload\Support\StorageWriter;
use CodePulse2020\MediaUpload\Support\TokenSigner;
use CodePulse2020\MediaUpload\Support\ServePolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\URL;
use SplFileInfo;

/**
 * Entry point behind the MediaUpload facade.
 *
 * @api
 */
final class MediaUploader
{
    /** @var array<string, list<mixed>> hooks registered with extend() */
    private array $extensions = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $repository,
    ) {
    }

    public function profile(?string $name = null): UploadBuilder
    {
        $config = $this->config();
        $name ??= $config['default_profile'] ?? 'image';

        return new UploadBuilder($this, Profile::fromConfig($name, $config));
    }

    public function store(UploadedFile|SplFileInfo $file, ?string $profile = null): StoredFile
    {
        return $this->profile($profile)->store($file);
    }

    public function storeAsync(UploadedFile|SplFileInfo $file, ?string $profile = null): PendingUpload
    {
        return $this->profile($profile)->storeAsync($file);
    }

    /**
     * Register a hook for every profile, e.g. in AppServiceProvider::boot():
     *   MediaUpload::extend('validated', CheckQuota::class);
     */
    public function extend(string $point, Stage|ImageTransformer|Closure|string|array $hook): self
    {
        if (! in_array($point, HookRunner::POINTS, true)) {
            throw new InvalidConfigException("Unknown hook point [{$point}]. Valid: ".implode(', ', HookRunner::POINTS).'.');
        }

        $this->extensions[$point][] = $hook;

        return $this;
    }

    /** @return list<mixed> */
    public function extensions(string $point): array
    {
        return $this->extensions[$point] ?? [];
    }

    /**
     * Temporary URL for a stored file.
     *
     * serve.mode "proxy": signed route through ServeFileController (all hardened headers).
     * serve.mode "presigned": S3 presigned GET with Content-Type / Content-Disposition /
     * Cache-Control overrides. Disks that cannot presign fall back to the proxy route.
     */
    public function url(StoredFile|string $file, ?int $minutes = null, ?string $disk = null, ?string $downloadName = null): string
    {
        $serve = $this->config('serve') ?? [];
        $policy = $this->container->make(ServePolicy::class);

        // Public files get a permanent URL; pending ones are never public yet.
        if ($file instanceof StoredFile ? ($file->isPublic() && ! $file->isPending()) : $this->isPublicPath($file)) {
            return $this->publicUrl($file instanceof StoredFile ? $file->path : $file, $file instanceof StoredFile ? $file->disk : $disk);
        }

        $path = $file instanceof StoredFile ? $file->path : $file;
        $disk ??= $file instanceof StoredFile ? $file->disk : ($this->config('disk') ?? 'local');
        $mime = $file instanceof StoredFile ? $file->mime : $policy->mimeForPath($path);
        $profileDisposition = $file instanceof StoredFile ? $this->config("profiles.{$file->profile}.disposition") : null;
        $downloadName ??= $file instanceof StoredFile
            ? $policy->downloadName($file->originalName, $file->extension)
            : basename($path);

        $ttl = 60 * ($minutes ?? (int) ($serve['url_ttl'] ?? 30));
        $expires = now()->addSeconds($ttl);
        $storage = $this->container->make('filesystem')->disk($disk);

        if (($serve['mode'] ?? 'proxy') === 'presigned' && $storage instanceof AwsS3V3Adapter) {
            return $storage->temporaryUrl($path, $expires, [
                'ResponseContentType' => $mime,
                'ResponseContentDisposition' => $policy->disposition($mime, $downloadName, $profileDisposition),
                'ResponseCacheControl' => $policy->cacheControl($ttl),
            ]);
        }

        $parameters = ['path' => $path, 'disk' => $disk, 'name' => FilenameSanitizer::sanitize($downloadName)];

        // Part of the signature, so the attachment policy cannot be removed from the URL.
        if (! $policy->isInline($mime, $profileDisposition)) {
            $parameters['download'] = 1;
        }

        return URL::temporarySignedRoute($this->serveRouteName($serve), $expires, $parameters);
    }

    /**
     * Register the signed file-serving route used by url().
     *
     * Call it wherever the route should live, e.g. in routes/web.php or inside a
     * Route::group() with your own prefix, domain or middleware. The signature check
     * always runs first; chain ->middleware() on the returned route to add more.
     */
    public function routes(?string $uri = null): Route
    {
        $serve = $this->config('serve') ?? [];

        return $this->container->make(Router::class)
            ->get($uri ?? $serve['route'] ?? 'media-upload/files/{path}', ServeFileController::class)
            ->where('path', '.*')
            ->middleware(array_values(array_unique(array_merge(
                [ValidateSignature::class],
                (array) ($serve['middleware'] ?? []),
            ))))
            ->name($serve['name'] ?? 'media-upload.serve');
    }

    /**
     * The configured name, or the name the route ended up with inside a group
     * that adds a name prefix (e.g. Route::name('admin.')->group(...)).
     *
     * @param  array<string, mixed>  $serve
     */
    private function serveRouteName(array $serve): string
    {
        $name = $serve['name'] ?? 'media-upload.serve';
        $routes = $this->container->make(Router::class)->getRoutes();

        if ($routes->hasNamedRoute($name)) {
            return $name;
        }

        // Invokable controllers are keyed with or without "@__invoke" depending on the Laravel version.
        $registered = ($routes->getByAction(ServeFileController::class)
            ?? $routes->getByAction(ServeFileController::class.'@__invoke'))?->getName();

        if ($registered === null) {
            throw new InvalidConfigException('The media-upload serve route is not registered. Call MediaUpload::routes() in your routes file.');
        }

        return $registered;
    }

    /** Read-only rules of a profile, e.g. to configure a form component. */
    public function rules(?string $profile = null): UploadRules
    {
        $config = $this->config();

        return UploadRules::fromProfile(
            Profile::fromConfig($profile ?? ($config['default_profile'] ?? 'image'), $config),
            $this->container->make(MimeMap::class),
        );
    }

    /**
     * Permanent URL of a file stored by a public profile: public.url (e.g. a
     * CDN domain) + path, or the disk's own url() when public.url is empty.
     */
    public function publicUrl(string $path, ?string $disk = null): string
    {
        $disk ??= $this->config('public.disk') ?? $this->config('disk') ?? 'local';
        $base = $this->config('public.url');

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        }

        return $this->container->make('filesystem')->disk($disk)->url($path);
    }

    /** Signed reference to a stored file for two-step flows. */
    public function token(StoredFile $file, ?int $ttlMinutes = null): string
    {
        $claims = $file->toArray();
        unset($claims['meta']); // may be large and is not needed to resolve the file

        return $this->signer()->sign(
            ['file' => $claims],
            60 * ($ttlMinutes ?? (int) ($this->config('tokens.ttl') ?? 1440)),
        );
    }

    /**
     * Resolves a token issued by token(). Checks the signature, expiry, profile,
     * owner and that the file still exists. A pending file that has already
     * been claimed resolves to its final location.
     *
     * When the upload had an owner, $owner must be given and match.
     *
     * @throws InvalidUploadTokenException
     */
    public function fromToken(string $token, ?string $profile = null, string|int|null $owner = null): StoredFile
    {
        $file = $this->verifiedFile($token, $profile, $owner);
        $filesystem = $this->container->make('filesystem');

        if ($filesystem->disk($file->disk)->exists($file->path)) {
            return $file;
        }

        if ($file->isPending() && $this->holdsContent($filesystem->disk($file->pendingTarget['disk']), $file->pendingTarget['path'], $file->sha256)) {
            return $this->claimedVersion($file);
        }

        throw new InvalidUploadTokenException(InvalidUploadTokenException::MISSING);
    }

    /**
     * Moves a pending upload to its final location and returns the final file.
     * Idempotent: claiming again returns the same final file.
     *
     * @throws InvalidUploadTokenException
     * @throws RejectedUploadException path_conflict when the final location is taken
     */
    public function claim(string $token, ?string $profile = null, string|int|null $owner = null): StoredFile
    {
        $file = $this->verifiedFile($token, $profile, $owner);

        if (! $file->isPending()) {
            return $this->fromToken($token, $profile, $owner);
        }

        // Lock the final location, the same key StoreFile uses, so two pending
        // uploads aimed at one hook-chosen name cannot both pass the existence
        // check and overwrite each other.
        return Locks::run(
            $this->container->bound('cache') ? $this->container->make('cache') : null,
            Locks::forTarget($file->pendingTarget['disk'], $file->pendingTarget['path']),
            fn () => $this->moveToTarget($file),
            (int) ($this->config('locks.wait') ?? 10),
        );
    }

    /**
     * Deletes a file stored by this package. Paths outside the package's own
     * directories are refused, so user supplied paths cannot delete arbitrary
     * objects. Content-addressed (hash) files may be shared: pass $force.
     */
    public function delete(StoredFile|string $file, ?string $disk = null, bool $force = false): bool
    {
        $path = ltrim($file instanceof StoredFile ? $file->path : $file, '/');
        $disk ??= $file instanceof StoredFile ? $file->disk : ($this->isPublicPath($path)
            ? ($this->config('public.disk') ?? $this->config('disk') ?? 'local')
            : ($this->config('disk') ?? 'local'));

        if (! in_array($disk, $this->managedDisks(), true)) {
            throw new \InvalidArgumentException("Disk [{$disk}] is not used by media-upload.");
        }

        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || ! $this->isManagedPath($path)) {
            throw new \InvalidArgumentException("Path [{$path}] is not managed by media-upload.");
        }

        if (! $force && $this->config('filename.generator') === 'hash'
            && preg_match('/^[0-9a-f]{64}$/', FilenameSanitizer::stem(basename($path)))) {
            throw new \LogicException("[{$path}] is content-addressed and may be shared; pass force: true to delete it.");
        }

        $storage = $this->container->make('filesystem')->disk($disk);

        return $storage->exists($path) && $storage->delete($path);
    }

    public function processor(): UploadProcessor
    {
        return $this->container->make(UploadProcessor::class);
    }

    public function config(?string $key = null): mixed
    {
        $config = (array) $this->repository->get('media-upload', []);

        return $key === null ? $config : data_get($config, $key);
    }

    private function verifiedFile(string $token, ?string $profile, string|int|null $owner): StoredFile
    {
        $claims = $this->signer()->verify($token);
        $data = $claims['file'] ?? null;

        if (! is_array($data)) {
            throw new InvalidUploadTokenException(InvalidUploadTokenException::INVALID);
        }

        $file = StoredFile::fromArray($data);

        if ($profile !== null && $file->profile !== $profile) {
            throw new InvalidUploadTokenException(InvalidUploadTokenException::WRONG_PROFILE);
        }

        // Fail closed both ways: an owned upload needs the caller to name its
        // owner, and a caller that names an owner never accepts an ownerless
        // upload. Compared as strings: ids may be int here and string there.
        if (($file->owner !== null || $owner !== null)
            && ($file->owner === null || $owner === null || (string) $owner !== (string) $file->owner)) {
            throw new InvalidUploadTokenException(InvalidUploadTokenException::WRONG_OWNER);
        }

        return $file;
    }

    private function claimedVersion(StoredFile $file): StoredFile
    {
        return $file->with([
            'disk' => $file->pendingTarget['disk'],
            'path' => $file->pendingTarget['path'],
            'visibility' => $file->pendingTarget['visibility'],
            'pending_target' => null,
        ]);
    }

    private function moveToTarget(StoredFile $file): StoredFile
    {
        $filesystem = $this->container->make('filesystem');
        $source = $filesystem->disk($file->disk);
        $targetDisk = $filesystem->disk($file->pendingTarget['disk']);
        $final = $this->claimedVersion($file);

        if (! $source->exists($file->path)) {
            // Already claimed only if the target holds this upload's bytes; another
            // upload may have taken the same name since this source disappeared.
            if ($this->holdsContent($targetDisk, $final->path, $file->sha256)) {
                return $final;
            }

            throw new InvalidUploadTokenException(InvalidUploadTokenException::MISSING);
        }

        if ($targetDisk->exists($final->path)) {
            // Content-addressed target with identical bytes: reuse it.
            if (FilenameSanitizer::stem(basename($final->path)) === $file->sha256) {
                $source->delete($file->path);

                return $final;
            }

            throw RejectedUploadException::because(RejectedUploadException::PATH_CONFLICT, 'A file with this name already exists.');
        }

        if (! $final->isPublic()) {
            DiskGuard::assertPrivate($final->disk, (array) $this->repository->get('filesystems.disks', []));
        }

        // Always copy through StorageWriter (not a native move) so the final
        // object gets its own metadata and no ACL on S3.
        $policy = $this->container->make(ServePolicy::class);
        $stream = $source->readStream($file->path);

        try {
            StorageWriter::write(
                $targetDisk,
                $final->path,
                $stream,
                $final->mime,
                $policy->disposition(
                    $final->mime,
                    $policy->downloadName($final->originalName, $final->extension),
                    $this->config("profiles.{$final->profile}.disposition"),
                ),
                $final->visibility,
                $final->isPublic() ? $this->config('public.cache_control') : 'private, no-store',
            );
        } finally {
            is_resource($stream) && fclose($stream);
        }

        $source->delete($file->path);

        return $final;
    }

    /** Whether $path exists on $disk with exactly the given SHA-256. */
    private function holdsContent(Filesystem $disk, string $path, string $sha256): bool
    {
        if (! $disk->exists($path)) {
            return false;
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_equals($sha256, hash_final($hash));
        } finally {
            fclose($stream);
        }
    }

    private function isPublicPath(string $path): bool
    {
        $public = trim((string) ($this->config('public.directory') ?? 'public'), '/');
        $path = ltrim($path, '/');

        return $public !== '' && str_starts_with($path, $public.'/');
    }

    /** @return list<string> */
    private function managedDisks(): array
    {
        $disks = [
            $this->config('disk') ?? 'local',
            $this->config('public.disk') ?? ($this->config('disk') ?? 'local'),
            $this->config('queue.quarantine_disk') ?? 'local',
        ];

        foreach ((array) $this->config('profiles') as $profile) {
            if (isset($profile['disk'])) {
                $disks[] = $profile['disk'];
            }
        }

        return array_values(array_unique($disks));
    }

    private function isManagedPath(string $path): bool
    {
        $config = $this->config();
        $prefixes = array_merge(
            [
                trim((string) ($config['public']['directory'] ?? 'public'), '/'),
                trim((string) ($config['pending']['directory'] ?? 'pending'), '/'),
                trim((string) ($config['queue']['quarantine_dir'] ?? 'quarantine'), '/'),
            ],
            array_map(fn ($directory) => trim((string) $directory, '/'), (array) ($config['managed_directories'] ?? [])),
        );

        foreach (array_keys((array) ($config['profiles'] ?? [])) as $name) {
            $prefixes[] = Profile::fromConfig($name, $config)->directory;
        }

        foreach (array_filter(array_unique($prefixes)) as $prefix) {
            if (str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function signer(): TokenSigner
    {
        return new TokenSigner((string) $this->repository->get('app.key', ''));
    }
}
