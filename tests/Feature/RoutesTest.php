<?php

use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Facades\MediaUpload;
use CodePulse\MediaUpload\Tests\Fixtures;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

function resetRoutes(callable $register): void
{
    app('router')->setRoutes(new RouteCollection());
    $register();
    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();
}

it('does not register the serve route on its own', function () {
    resetRoutes(fn () => null);

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect(fn () => $stored->url())->toThrow(InvalidConfigException::class, 'MediaUpload::routes()');
});

it('registers the serve route inside a group with prefix and name prefix', function () {
    resetRoutes(fn () => Route::prefix('admin')->name('admin.')->group(fn () => MediaUpload::routes()));

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));
    $url = $stored->url();

    expect($url)->toContain('/admin/media-upload/files/');
    $this->get($url)->assertOk();
    $this->get('/admin/media-upload/files/'.$stored->path.'?disk=local')->assertForbidden();
});

it('accepts a custom uri and keeps the signature check when middleware is chained', function () {
    resetRoutes(fn () => MediaUpload::routes('files/{path}')->middleware('throttle:60,1'));

    $stored = MediaUpload::store(Fixtures::upload(Fixtures::jpeg(), 'a.jpg'));

    expect($stored->url())->toContain('/files/');
    $this->get('/files/'.$stored->path.'?disk=local')->assertForbidden();
    $this->get($stored->url())->assertOk();
});
