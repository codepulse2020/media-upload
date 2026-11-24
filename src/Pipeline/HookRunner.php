<?php

namespace CodePulse\MediaUpload\Pipeline;

use Closure;
use CodePulse\MediaUpload\Contracts\ImageTransformer;
use CodePulse\MediaUpload\Contracts\Stage;
use CodePulse\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse\MediaUpload\Support\HookResolver;
use CodePulse\MediaUpload\UploadContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;

/** @internal */
final class HookRunner
{
    public const VALIDATED = 'validated';
    public const TRANSFORM = 'transform';
    public const BEFORE_STORE = 'before_store';
    public const AFTER_STORE = 'after_store';

    public const POINTS = [self::VALIDATED, self::TRANSFORM, self::BEFORE_STORE, self::AFTER_STORE];

    public function __construct(
        private readonly Container $container,
        private readonly HookResolver $resolver,
    ) {
    }

    /** @param list<mixed> $definitions */
    public function run(string $point, array $definitions, UploadContext $ctx): void
    {
        if ($definitions === []) {
            return;
        }

        $pipes = array_map(
            fn (mixed $definition) => $this->toPipe($point, $this->resolver->resolve($definition)),
            $definitions,
        );

        $reached = false;

        $result = (new Pipeline($this->container))
            ->send($ctx)
            ->through($pipes)
            ->then(function (UploadContext $passed) use (&$reached) {
                $reached = true;

                return $passed;
            });

        // A hook that does not call $next, or swaps the context, rejects the upload.
        if (! $reached || $result !== $ctx) {
            throw RejectedUploadException::because(
                RejectedUploadException::HOOK_HALTED,
                "Upload halted by a [{$point}] hook.",
            );
        }
    }

    private function toPipe(string $point, Stage|ImageTransformer|Closure $hook): Closure
    {
        if ($hook instanceof ImageTransformer) {
            if ($point !== self::TRANSFORM) {
                throw new InvalidConfigException(
                    'ImageTransformer ['.$hook::class."] can only be used at the [transform] hook, not [{$point}]."
                );
            }

            return function (UploadContext $ctx, Closure $next) use ($hook) {
                $ctx->replaceImage($hook->transform($ctx->image(), $ctx));

                return $next($ctx);
            };
        }

        if ($hook instanceof Stage) {
            return fn (UploadContext $ctx, Closure $next) => $hook->handle($ctx, $next);
        }

        return $hook;
    }
}
