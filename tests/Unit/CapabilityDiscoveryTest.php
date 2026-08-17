<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\CapabilityDiscovery;
use Fissible\Verdict\Capabilities\UnaffirmedDefinition;
use Fissible\Verdict\Tests\Fixtures\Capabilities\AbstractAffirmedCapability;
use Fissible\Verdict\Tests\Fixtures\Capabilities\AffirmedCapability;
use Fissible\Verdict\Tests\Fixtures\Capabilities\Nested\NestedAffirmedCapability;
use Fissible\Verdict\Tests\Fixtures\Capabilities\UnaffirmedOrderCapability;
use Fissible\Verdict\Tests\Fixtures\ThrowingCapabilities\ThrowingRateLimitCapability;

function fixtureDiscovery(string ...$directories): CapabilityDiscovery
{
    return new CapabilityDiscovery(
        rootPath: dirname(__DIR__).'/Fixtures',
        rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
        paths: array_map(static fn (string $directory): string => dirname(__DIR__).'/Fixtures/'.$directory, $directories),
    );
}

/** @return list<string> */
function unaffirmedClasses(CapabilityDiscovery $discovery): array
{
    return array_map(
        static fn (UnaffirmedDefinition $definition): string => $definition->class,
        $discovery->discover()->unaffirmed,
    );
}

it('affirms a finished definition, and recurses into the subdirectories dotted names generate', function (): void {
    $affirmed = fixtureDiscovery('Capabilities')->discover()->affirmed;

    expect($affirmed)->toContain(AffirmedCapability::class)
        ->and($affirmed)->toContain(NestedAffirmedCapability::class);
});

it('leaves a class without the contract inert and names it', function (): void {
    $found = fixtureDiscovery('Capabilities')->discover();

    expect($found->affirmed)->not->toContain(UnaffirmedOrderCapability::class)
        ->and(unaffirmedClasses(fixtureDiscovery('Capabilities')))->toContain(UnaffirmedOrderCapability::class);

    $reasons = array_column(array_map(
        static fn (UnaffirmedDefinition $definition): array => ['class' => $definition->class, 'reason' => $definition->reason],
        $found->unaffirmed,
    ), 'reason', 'class');

    expect($reasons[UnaffirmedOrderCapability::class])->toBe(UnaffirmedDefinition::NO_CONTRACT);
});

/**
 * An abstract class implementing a static contract is legal PHP; without this check discovery would
 * call make() on it and fail as an Error, reported as a broken definition with a confusing message.
 */
it('never builds an abstract class that implements the contract', function (): void {
    $found = fixtureDiscovery('Capabilities')->discover();
    $reasons = [];

    foreach ($found->unaffirmed as $definition) {
        $reasons[$definition->class] = $definition->reason;
    }

    expect($found->affirmed)->not->toContain(AbstractAffirmedCapability::class)
        ->and($reasons[AbstractAffirmedCapability::class])->toBe(UnaffirmedDefinition::NOT_INSTANTIABLE);
});

it('reports a file that maps to no loadable class rather than throwing', function (): void {
    $directory = sys_get_temp_dir().'/verdict-discovery-'.bin2hex(random_bytes(6));
    mkdir($directory.'/Capabilities', recursive: true);
    file_put_contents(
        $directory.'/Capabilities/MismatchedCapability.php',
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fissible\\Verdict\\Tests\\Fixtures\\Capabilities;\n\nfinal class SomethingElseEntirely {}\n",
    );

    $found = (new CapabilityDiscovery(
        rootPath: $directory,
        rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
        paths: [$directory.'/Capabilities'],
    ))->discover();

    expect($found->affirmed)->toBe([])
        ->and($found->unaffirmed)->toHaveCount(1)
        ->and($found->unaffirmed[0]->reason)->toBe(UnaffirmedDefinition::NO_CLASS);

    unlink($directory.'/Capabilities/MismatchedCapability.php');
    rmdir($directory.'/Capabilities');
    rmdir($directory);
});

/**
 * Classification must have no side effects: verdict:validate consumes it to report on classes it
 * must not register into a booting application. A definition whose make() throws is still *affirmed*
 * — discovering it must not be what discovers that it is broken.
 */
it('classifies a definition whose make() throws without calling it', function (): void {
    $found = fixtureDiscovery('ThrowingCapabilities')->discover();

    expect($found->affirmed)->toBe([ThrowingRateLimitCapability::class])
        ->and($found->unaffirmed)->toBe([]);
});

it('finds nothing when no paths are configured', function (): void {
    $found = fixtureDiscovery()->discover();

    expect($found->affirmed)->toBe([])
        ->and($found->unaffirmed)->toBe([]);
});

/**
 * The root path is configuration — `app_path('Capabilities')`, a value from a config file, a value
 * built by concatenation — so it is not reliably a literal prefix of the paths the filesystem hands
 * back. On Windows it never is: SplFileInfo returns backslashes while a concatenated root carries
 * whatever separator the caller wrote. Deriving a class name by string-subtracting one from the
 * other therefore has to normalise both sides first.
 *
 * This is a regression test for 18 Windows CI failures where every class derived a wrong name,
 * failed class_exists, and landed in unaffirmed — discovery silently finding nothing at all.
 */
it('derives class names when the configured root is not a literal prefix of the file path', function (): void {
    $discovery = new CapabilityDiscovery(
        rootPath: dirname(__DIR__).'/./Fixtures/',
        rootNamespace: 'Fissible\\Verdict\\Tests\\Fixtures\\',
        paths: [dirname(__DIR__).'/Fixtures/Capabilities'],
    );

    expect($discovery->discover()->affirmed)->toContain(AffirmedCapability::class);
});
