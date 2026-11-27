<?php

namespace CodePulse2020\MediaUpload\Http;

use CodePulse2020\MediaUpload\MediaUploader;
use CodePulse2020\MediaUpload\Support\ServePolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves stored files behind a signed URL with headers that stop MIME
 * sniffing, script execution and cross-site embedding. Works for any disk,
 * including S3 (the bytes are streamed through the application).
 *
 * @internal
 */
final class ServeFileController
{
    public function __invoke(Request $request, MediaUploader $uploader, ServePolicy $policy, string $path): StreamedResponse
    {
        $config = $uploader->config();
        $disk = (string) $request->query('disk', $config['disk'] ?? 'local');

        abort_unless(in_array($disk, $this->allowedDisks($config), true), 404);
        abort_if($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/'), 404);

        $storage = app('filesystem')->disk($disk);
        abort_unless($storage->exists($path), 404);

        // The type comes from the extension we chose when storing, never from content sniffing.
        $mime = $policy->mimeForPath($path);
        $name = (string) $request->query('name', basename($path));

        // Signed URLs carry "expires"; a cached copy must not outlive it.
        $secondsLeft = $request->has('expires') ? (int) $request->query('expires') - time() : 0;

        $headers = array_merge($config['serve']['headers'] ?? [], [
            'Content-Type' => $mime,
            'Content-Disposition' => $policy->disposition($mime, $name, null, $request->boolean('download')),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $policy->cacheControl($secondsLeft),
        ]);

        return $storage->response($path, null, $headers);
    }

    /** @return list<string> */
    private function allowedDisks(array $config): array
    {
        $disks = [$config['disk'] ?? 'local'];

        foreach ($config['profiles'] ?? [] as $profile) {
            if (isset($profile['disk'])) {
                $disks[] = $profile['disk'];
            }
        }

        return array_values(array_unique($disks));
    }
}
