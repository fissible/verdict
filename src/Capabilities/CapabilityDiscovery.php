<?php

declare(strict_types=1);

namespace Fissible\Verdict\Capabilities;

use Fissible\Verdict\Contracts\DefinesCapability;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Finds capability definition classes and says what they are. It never builds one.
 *
 * A file's class name is derived from the PSR-4 pair it lives under — the root namespace plus the
 * file's path relative to the root path — the way Laravel's own discovery does it. Both halves are
 * injected rather than assumed, because the application's pair is `App\` + `app/` while the package's
 * own tests use a different one.
 *
 * @internal Resolve CapabilityDiscovery from the container. This constructor is not part of the
 *           supported surface and may gain required parameters in any release.
 *           See docs/adr/0019-verdict-services-are-container-resolved.md.
 */
final readonly class CapabilityDiscovery
{
    /** @param list<string> $paths */
    public function __construct(
        private string $rootPath,
        private string $rootNamespace,
        private array $paths,
    ) {}

    public function discover(): DiscoveredCapabilities
    {
        $affirmed = [];
        $unaffirmed = [];

        foreach ($this->paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->classesIn($path) as $class) {
                if (! class_exists($class)) {
                    $unaffirmed[] = new UnaffirmedDefinition($class, UnaffirmedDefinition::NO_CLASS);

                    continue;
                }

                if (! is_a($class, DefinesCapability::class, true)) {
                    $unaffirmed[] = new UnaffirmedDefinition($class, UnaffirmedDefinition::NO_CONTRACT);

                    continue;
                }

                // Abstract classes, interfaces, and traits can all implement a static contract.
                // Calling make() on one fails as an Error that reads like a broken definition, so
                // they are reported as unaffirmed rather than built. See ADR 0027 §4.
                if (! (new ReflectionClass($class))->isInstantiable()) {
                    $unaffirmed[] = new UnaffirmedDefinition($class, UnaffirmedDefinition::NOT_INSTANTIABLE);

                    continue;
                }

                /** @var class-string<DefinesCapability> $class */
                $affirmed[] = $class;
            }
        }

        return new DiscoveredCapabilities($affirmed, $unaffirmed);
    }

    /** @return list<string> */
    private function classesIn(string $path): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $classes[] = $this->classFor($file->getPathname());
        }

        sort($classes);

        return $classes;
    }

    private function classFor(string $file): string
    {
        $relative = trim(str_replace($this->rootPath, '', $file), DIRECTORY_SEPARATOR);

        return $this->rootNamespace.str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $relative,
        );
    }
}
