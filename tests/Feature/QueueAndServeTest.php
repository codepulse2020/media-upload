<?php

use CodePulse2020\MediaUpload\Events\UploadRejected;
use CodePulse2020\MediaUpload\Events\UploadStored;
use CodePulse2020\MediaUpload\Exceptions\NonSerializableHookException;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Jobs\ProcessQuarantinedUpload;
use CodePulse2020\MediaUpload\Rules\SafeFile;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use CodePulse2020\MediaUpload\Transformers\Watermark;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

it('quarantines and processes uploads on the queue', function () {
    Queue::fake();
    Event::fake([UploadStored::class]);

    $pending = MediaUpload::profile('image')
        ->transform(new Watermark(text: 'ACME'))
        ->withContext(['user_id' => 7])
        ->storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(Storage::disk('local')->exists('quarantine/'.$pending->id))->toBeTrue();

    $job = null;
    Queue::assertPushed(ProcessQuarantinedUpload::class, function ($pushed) use (&$job, $pending) {
        $job = $pushed;

        return $pushed->id === $pending->id;
    });

    // Simulate the worker (serialize round trip included).
    unserialize(serialize($job))->handle(app(\CodePulse2020\MediaUpload\MediaUploader::class));

    Event::assertDispatched(UploadStored::class, fn ($e) => $e->id === $pending->id
        && $e->context === ['user_id' => 7]
        && Storage::disk('local')->exists($e->file->path));

    expect(Storage::disk('local')->exists('quarantine/'.$pending->id))->toBeFalse();
});

it('reports rejections from the queue through events', function () {
    Queue::fake();
    Event::fake([UploadRejected::class]);

    $pending = MediaUpload::storeAsync(Fixtures::upload(Fixtures::pngBomb(), 'bomb.png'));

    Queue::assertPushed(ProcessQuarantinedUpload::class, function ($job) {
        $job->handle(app(\CodePulse2020\MediaUpload\MediaUploader::class));

        return true;
    });

    Event::assertDispatched(UploadRejected::class, fn ($e) => $e->id === $pending->id
        && $e->reason === RejectedUploadException::IMAGE_TOO_LARGE);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('runs cheap checks before queueing', function () {
    Queue::fake();

    expect(fn () => MediaUpload::storeAsync(Fixtures::upload('<?php', 'a.jpg')))->toThrow(RejectedUploadException::class);

    Queue::assertNothingPushed();
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses closures for queued uploads', function () {
    MediaUpload::profile('image')
        ->hook('validated', fn ($ctx, $next) => $next($ctx))
        ->storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
})->throws(NonSerializableHookException::class);

it('validates in form requests', function () {
    $good = Validator::make(['f' => Fixtures::upload(Fixtures::jpeg(), 'a.jpg')], ['f' => [new SafeFile('image')]]);
    $bad = Validator::make(['f' => Fixtures::upload('<?php', 'a.jpg')], ['f' => [new SafeFile('image')]]);

    expect($good->passes())->toBeTrue()->and($bad->fails())->toBeTrue();
});

it('serves files through signed URLs with hardened headers', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $response = $this->get($stored->url());

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('serves non images as attachments', function () {
    $stored = MediaUpload::profile('document')->store(Fixtures::upload(Fixtures::cleanPdf(), 'invoice.pdf'));

    expect($this->get($stored->url())->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('rejects unsigned or tampered URLs', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $this->get('/media-upload/files/'.$stored->path.'?disk=local')->assertForbidden();
    $this->get(str_replace('disk=local', 'disk=s3', $stored->url()))->assertForbidden();
});
