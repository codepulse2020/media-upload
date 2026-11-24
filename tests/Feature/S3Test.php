<?php

use Aws\CommandInterface;
use Aws\Middleware;
use CodePulse\MediaUpload\Facades\MediaUpload;
use CodePulse\MediaUpload\MediaUploader;
use CodePulse\MediaUpload\Jobs\ProcessQuarantinedUpload;
use CodePulse\MediaUpload\Tests\Fixtures;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;

/*
| Runs only through `bash tests/run-s3-tests.sh` (AWS Emulator in Docker).
| The bucket uses BucketOwnerEnforced (ACLs disabled), the S3 default since 2023.
*/

beforeEach(function () {
    $endpoint = getenv('MEDIA_UPLOAD_S3_ENDPOINT');

    if ($endpoint === false || $endpoint === '') {
        // CI sets MEDIA_UPLOAD_REQUIRE_S3 so a broken emulator setup cannot pass as "skipped".
        if (getenv('MEDIA_UPLOAD_REQUIRE_S3')) {
            $this->fail('MEDIA_UPLOAD_S3_ENDPOINT is not set; run bash tests/run-s3-tests.sh.');
        }

        $this->markTestSkipped('Run bash tests/run-s3-tests.sh to include the S3 tests.');
    }

    $credentials = parse_ini_file((string) getenv('MEDIA_UPLOAD_S3_CREDENTIALS'), true, INI_SCANNER_RAW)['default'] ?? [];
    $this->ca = (string) getenv('MEDIA_UPLOAD_S3_CA');
    $this->bucket = 'media-upload-'.Str::lower(Str::random(12));

    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => $credentials['aws_access_key_id'] ?? '',
        'secret' => $credentials['aws_secret_access_key'] ?? '',
        'region' => 'us-east-1',
        'bucket' => $this->bucket,
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => true,
        'http' => ['verify' => $this->ca],
        'throw' => true,
    ]);
    config()->set('media-upload.disk', 's3');

    $this->client = Storage::disk('s3')->getClient();
    $this->client->createBucket(['Bucket' => $this->bucket, 'ObjectOwnership' => 'BucketOwnerEnforced']);

    // Record which writes carried an ACL header.
    $this->acl = [];
    $this->client->getHandlerList()->appendSign(Middleware::tap(function (CommandInterface $command, ?RequestInterface $request) {
        if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'], true) && $request !== null) {
            $this->acl[$command['Key']] = $request->getHeaderLine('x-amz-acl');
        }
    }));
});

afterEach(function () {
    if (! isset($this->client)) {
        return;
    }

    foreach ($this->client->getIterator('ListObjects', ['Bucket' => $this->bucket]) as $object) {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $object['Key']]);
    }

    $this->client->deleteBucket(['Bucket' => $this->bucket]);
});

function s3Head(string $bucket, string $key): Aws\Result
{
    return Storage::disk('s3')->getClient()->headObject(['Bucket' => $bucket, 'Key' => $key]);
}

it('stores images on a bucket without ACLs', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpegWithExif('SECRET-GPS'), 'photo.jpg'));

    expect($stored->disk)->toBe('s3')
        ->and($this->acl)->toBe([$stored->path => ''])
        ->and(Storage::disk('s3')->get($stored->path))->not->toContain('SECRET-GPS');

    $head = s3Head($this->bucket, $stored->path);
    expect($head['ContentType'])->toBe('image/jpeg')
        ->and($head['ContentDisposition'])->toStartWith('inline');
});

it('stores non images as attachments', function () {
    $stored = MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::cleanPdf(), 'invoice.pdf'));

    $head = s3Head($this->bucket, $stored->path);
    expect($head['ContentType'])->toBe('application/pdf')
        ->and($head['ContentDisposition'])->toStartWith('attachment')
        ->and($head['ContentDisposition'])->toContain('invoice.pdf');
});

it('serves through the proxy route', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $response = $this->get($stored->url());

    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe(Storage::disk('s3')->get($stored->path));
});

it('serves through presigned URLs', function () {
    config()->set('media-upload.serve.mode', 'presigned');

    $image = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $pdf = MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::cleanPdf(), 'invoice.pdf'));

    $imageUrl = $image->url();
    expect($imageUrl)->toStartWith(getenv('MEDIA_UPLOAD_S3_ENDPOINT'));

    $response = Http::withOptions(['verify' => $this->ca])->get($imageUrl);
    expect($response->status())->toBe(200)
        ->and($response->header('Content-Type'))->toBe('image/jpeg')
        ->and($response->header('Content-Disposition'))->toStartWith('inline')
        ->and($response->body())->toBe(Storage::disk('s3')->get($image->path));

    $response = Http::withOptions(['verify' => $this->ca])->get($pdf->url());
    expect($response->status())->toBe(200)
        ->and($response->header('Content-Type'))->toBe('application/pdf')
        ->and($response->header('Content-Disposition'))->toStartWith('attachment');
});

it('limits presigned URLs to their lifetime and signature', function () {
    config()->set('media-upload.serve.mode', 'presigned');
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $url = $stored->url(2);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['X-Amz-Expires'])->toBe('120');

    // Switching the disposition to bypass inline/attachment breaks the signature.
    $tampered = preg_replace('/response-content-disposition=[^&]+/', 'response-content-disposition=attachment', $url);
    expect(Http::withOptions(['verify' => $this->ca])->get($tampered)->status())->toBe(403);
});

it('falls back to the proxy route for disks that cannot presign', function () {
    config()->set('media-upload.serve.mode', 'presigned');
    config()->set('media-upload.disk', 'local');

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($stored->url())->toContain('/media-upload/files/');
});

it('quarantines on S3 and finishes on the queue', function () {
    Queue::fake();
    config()->set('media-upload.queue.quarantine_disk', 's3');

    $pending = MediaUpload::storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    expect(Storage::disk('s3')->exists('quarantine/'.$pending->id))->toBeTrue();

    Queue::assertPushed(ProcessQuarantinedUpload::class, function ($job) {
        $job->handle(app(MediaUploader::class));

        return true;
    });

    $files = Storage::disk('s3')->allFiles();
    expect($files)->toHaveCount(1)
        ->and($files[0])->toStartWith('uploads/image/')
        ->and(array_filter($this->acl))->toBe([]);
});
