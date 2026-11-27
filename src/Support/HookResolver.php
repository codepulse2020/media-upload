<?php

namespace CodePulse2020\MediaUpload\Support;

use Closure;
use CodePulse2020\MediaUpload\Contracts\ImageTransformer;
use CodePulse2020\MediaUpload\Contracts\Stage;
use CodePulse2020\MediaUpload\Exceptions\InvalidConfigException;
use CodePulse2020\MediaUpload\Exceptions\NonSerializableHookException;
use Illuminate\Contracts\Container\Container;

/**
 * Turns hook definitions into instances. Accepted definitions:
 *  - class-string                      App\Upload\CheckQuota::class
 *  - [class-string, array $params]     [Watermark::class, ['path' => '...']]
 *  - Stage / ImageTransformer instance new Watermark(text: 'ACME')
 *  - Closure (sync uploads only)       fn (UploadContext $ctx, Closure $next) => $next($ctx)
 *
 * @internal
 */
final class HookResolver
{
    public function __construct(private readonly Container $container)
    {
    }

    public function resolve(mixed $definition): Stage|ImageTransformer|Closure
    {
        if ($definition instanceof Stage || $definition instanceof ImageTransformer || $definition instanceof Closure) {
            return $definition;
        }

        if (is_string($definition) && class_exists($definition)) {
            return $this->assertHook($this->container->make($definition), $definition);
        }

        if (is_array($definition) && isset($definition[0]) && is_string($definition[0]) && class_exists($definition[0])) {
            $params = $definition[1] ?? [];

            if (! is_array($params)) {
                throw new InvalidConfigException("Hook parameters for [{$definition[0]}] must be an array.");
            }

            return $this->assertHook($this->container->make($definition[0], $params), $definition[0]);
        }

        throw new InvalidConfigException('Invalid hook definition: '.get_debug_type($definition).'.');
    }

    /** @param array<string, list<mixed>> $hooks */
    public static function assertSerializable(array $hooks): void
    {
        foreach ($hooks as $point => $definitions) {
            foreach ($definitions as $definition) {
                if ($definition instanceof Closure) {
                    throw new NonSerializableHookException(
                        "Closures cannot be used as [{$point}] hooks for queued uploads. Use a class instead."
                    );
                }

                // Covers objects and [Class::class, $params] arrays, including
                // anything nested inside the parameters.
                try {
                    serialize($definition);
                } catch (\Throwable $e) {
                    $name = is_object($definition) ? $definition::class : (is_array($definition) ? (string) ($definition[0] ?? 'array') : get_debug_type($definition));

                    throw new NonSerializableHookException(
                        "Hook [{$name}] at [{$point}] is not serializable: {$e->getMessage()}", 0, $e
                    );
                }
            }
        }
    }

    private function assertHook(mixed $instance, string $class): Stage|ImageTransformer
    {
        if (! $instance instanceof Stage && ! $instance instanceof ImageTransformer) {
            throw new InvalidConfigException(
                "Hook [{$class}] must implement ".Stage::class.' or '.ImageTransformer::class.'.'
            );
        }

        return $instance;
    }
}
