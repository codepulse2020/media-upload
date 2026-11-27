<?php

use CodePulse2020\MediaUpload\Events\UploadStored;
use CodePulse2020\MediaUpload\Exceptions\InvalidUploadTokenException as Invalid;
use CodePulse2020\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse2020\MediaUpload\Facades\MediaUpload;
use CodePulse2020\MediaUpload\Jobs\ProcessQuarantinedUpload;
use CodePulse2020\MediaUpload\MediaUploader;
use CodePulse2020\MediaUpload\Rules\UploadToken;
use CodePulse2020\MediaUpload\Tests\Fixtures;
use CodePulse2020\MediaUpload\UploadContext;
use CodePulse2020\MediaUpload\Support\Locks;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

function tokenReason(callable $callback): ?string
{
    try {
        $callback();
    } catch (Invalid $e) {
        return $e->reason;
    }

    return null;
}

/* ----------------------------------------------------------------- tokens */

it('resolves its own tokens', function () {
    $stored = MediaUpload::profile('image')->ownedBy(7)->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $resolved = MediaUpload::fromToken($stored->token(), 'image', '7');

    expect($resolved->path)->toBe($stored->path)
        ->and($resolved->owner)->toBe(7)
        ->and($resolved->sha256)->toBe($stored->sha256);
});

it('rejects bad tokens', function (callable $make, string $reason) {
    $stored = MediaUpload::profile('image')->ownedBy(7)->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(tokenReason(fn () => $make($stored)))->toBe($reason);
})->with([
    'tampered' => [fn ($f) => MediaUpload::fromToken(substr($f->token(), 0, -2).'xx', 'image', 7), Invalid::INVALID],
    'garbage' => [fn ($f) => MediaUpload::fromToken('not-a-token', 'image', 7), Invalid::INVALID],
    'expired' => [fn ($f) => MediaUpload::fromToken($f->token(-1), 'image', 7), Invalid::EXPIRED],
    'wrong profile' => [fn ($f) => MediaUpload::fromToken($f->token(), 'avatar', 7), Invalid::WRONG_PROFILE],
    'wrong owner' => [fn ($f) => MediaUpload::fromToken($f->token(), 'image', 8), Invalid::WRONG_OWNER],
    'owner omitted' => [fn ($f) => MediaUpload::fromToken($f->token(), 'image'), Invalid::WRONG_OWNER],
    'file deleted' => [function ($f) {
        $token = $f->token();
        Storage::disk('local')->delete($f->path);

        return MediaUpload::fromToken($token, 'image', 7);
    }, Invalid::MISSING],
]);

it('never accepts an ownerless upload when an owner is required', function () {
    $ownerless = MediaUpload::profile('image')->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(tokenReason(fn () => MediaUpload::fromToken($ownerless->token(), 'image', 5)))->toBe(Invalid::WRONG_OWNER)
        ->and(Validator::make(['f' => $ownerless->token()], ['f' => [new UploadToken('image', owner: 5)]])->fails())->toBeTrue()
        // Without an owner requirement, an ownerless token stays a plain bearer reference.
        ->and(MediaUpload::fromToken($ownerless->token(), 'image')->path)->toBe($ownerless->path);
});

it('cannot forge a token for another path', function () {
    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    [$payload, $signature] = explode('.', $stored->token());

    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $claims['file']['path'] = 'imports/secret.csv';
    $forged = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$signature;

    expect(tokenReason(fn () => MediaUpload::fromToken($forged)))->toBe(Invalid::INVALID);
});

it('validates tokens in form requests', function () {
    $mine = MediaUpload::profile('image')->ownedBy(1)->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $theirs = MediaUpload::profile('image')->ownedBy(2)->store(Fixtures::upload(Fixtures::jpeg(), 'b.jpg'));
    $rules = ['files.*' => ['required', 'string', new UploadToken('image', owner: fn () => 1)]];

    expect(Validator::make(['files' => [$mine->token()]], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['files' => [$theirs->token()]], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['files' => [$mine->path]], $rules)->fails())->toBeTrue();
});

/* ---------------------------------------------------------------- pending */

it('keeps pending uploads private until claimed', function () {
    $pending = MediaUpload::profile('public_image')->pending()->ownedBy(5)->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($pending->isPending())->toBeTrue()
        ->and($pending->visibility)->toBe('private')
        ->and($pending->path)->toStartWith('pending/public_image/')
        ->and($pending->url())->toContain('signature=');

    $final = MediaUpload::claim($pending->token(), 'public_image', 5);

    expect($final->isPending())->toBeFalse()
        ->and($final->visibility)->toBe('public')
        ->and($final->path)->toStartWith('public/uploads/public_image/')
        ->and(Storage::disk('local')->exists($final->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($pending->path))->toBeFalse();
});

it('claims idempotently', function () {
    $pending = MediaUpload::profile('image')->pending()->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $token = $pending->token();

    $first = MediaUpload::claim($token);
    $second = MediaUpload::claim($token);

    expect($second->path)->toBe($first->path)
        ->and(MediaUpload::fromToken($token)->path)->toBe($first->path)
        ->and(Storage::disk('local')->files(dirname($first->path)))->toHaveCount(1);
});

it('reuses identical content when claiming with the hash generator', function () {
    config()->set('media-upload.filename.generator', 'hash');
    $existing = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $pending = MediaUpload::profile('image')->pending()->store(Fixtures::upload(Fixtures::jpeg(), 'b.jpg'));

    expect(MediaUpload::claim($pending->token())->path)->toBe($existing->path)
        ->and(Storage::disk('local')->exists($pending->path))->toBeFalse();
});

it('refuses to claim onto an existing hook chosen name', function () {
    config()->set('media-upload.managed_directories', ['covers']);
    $named = fn () => MediaUpload::profile('image')->pending()
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->directory = 'covers';
            $ctx->filename = 'shop-1';

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    $first = $named();
    $second = $named(); // both pending: the final name is free at upload time
    MediaUpload::claim($first->token());

    expect(fn () => MediaUpload::claim($second->token()))->toThrow(RejectedUploadException::class);
});

it('locks the claim target, not the pending source', function () {
    config()->set('media-upload.managed_directories', ['covers']);
    config()->set('media-upload.locks.wait', 0);

    $pending = MediaUpload::profile('image')->pending()
        ->hook('before_store', function (UploadContext $ctx, Closure $next) {
            $ctx->directory = 'covers';
            $ctx->filename = 'shop-1';

            return $next($ctx);
        })
        ->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    // Another request (a concurrent claim or store) holds the final location.
    $held = Cache::lock(Locks::name(Locks::forTarget('local', 'covers/shop-1.jpg')), 30);
    expect($held->get())->toBeTrue();

    expect(fn () => MediaUpload::claim($pending->token()))->toThrow(LockTimeoutException::class);

    $held->release();
    expect(MediaUpload::claim($pending->token())->path)->toBe('covers/shop-1.jpg');
});

it('queues pending uploads with their owner', function () {
    Queue::fake();
    Event::fake([UploadStored::class]);

    MediaUpload::profile('image')->pending()->ownedBy(9)->storeAsync(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    Queue::assertPushed(ProcessQuarantinedUpload::class, function ($job) {
        unserialize(serialize($job))->handle(app(MediaUploader::class));

        return true;
    });

    Event::assertDispatched(UploadStored::class, fn ($e) => $e->file->isPending() && $e->file->owner === 9);
});

/* ------------------------------------------------------------------ prune */

it('prunes stale pending and quarantine files', function () {
    $stale = MediaUpload::profile('image')->pending()->store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $fresh = MediaUpload::profile('image')->pending()->store(Fixtures::upload(Fixtures::jpeg(), 'b.jpg'));
    Storage::disk('local')->put('quarantine/old', 'x');

    $old = now()->subDays(2)->getTimestamp();
    touch(Storage::disk('local')->path($stale->path), $old);
    touch(Storage::disk('local')->path('quarantine/old'), $old);

    $this->artisan('media-upload:prune')->assertSuccessful();

    expect(Storage::disk('local')->exists($stale->path))->toBeFalse()
        ->and(Storage::disk('local')->exists('quarantine/old'))->toBeFalse()
        ->and(Storage::disk('local')->exists($fresh->path))->toBeTrue();

    $this->artisan('media-upload:prune-quarantine')->assertSuccessful(); // 0.x alias
});
