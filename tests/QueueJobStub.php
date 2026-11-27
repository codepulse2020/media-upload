<?php

namespace CodePulse2020\MediaUpload\Tests;

use Illuminate\Queue\Jobs\SyncJob;

/** Lets tests control attempts() and observe release() on Laravel 10 – 12. */
final class QueueJobStub extends SyncJob
{
    public int $attempt = 1;

    public ?int $releasedWith = null;

    public function __construct()
    {
        parent::__construct(app(), '{}', 'sync', 'default');
    }

    public function attempts()
    {
        return $this->attempt;
    }

    public function release($delay = 0)
    {
        $this->releasedWith = (int) $delay;
        parent::release($delay);
    }
}
