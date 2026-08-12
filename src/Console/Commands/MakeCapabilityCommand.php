<?php

declare(strict_types=1);

namespace Fissible\Verdict\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class MakeCapabilityCommand extends Command
{
    protected $signature = 'verdict:make-capability
        {capability? : Dot-separated capability name, for example orders.refund}
        {--model= : Application model class or short model name}
        {--ability= : Laravel authorization ability}
        {--target-argument= : Proposed argument containing the trusted target identifier}
        {--confirmation : Include an approval-binding TODO}
        {--claim : Include an execution-claim TODO}
        {--rate-limit : Include a rate-limit TODO}
        {--path= : Application root for generated files (defaults to Laravel base path)}
        {--force : Replace generated files when they already exist}';

    protected $description = 'Generate fail-closed Verdict capability wiring without modifying application code';

    public function handle(Filesystem $files): int
    {
        try {
            $input = $this->generatorInput();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $paths = $this->paths($input);
        $existing = array_filter($paths, $files->exists(...));

        if ($existing !== [] && ! $this->option('force')) {
            $this->components->error('Refusing to overwrite existing generated files. Re-run with --force after reviewing them.');

            return self::FAILURE;
        }

        foreach ($paths as $path) {
            $files->ensureDirectoryExists(dirname($path));
        }

        $files->put($paths['capability'], $this->capabilityStub($input));
        $files->put($paths['test'], $this->testStub($input));

        $this->components->info("Created [{$paths['capability']}].");
        $this->components->info("Created [{$paths['test']}].");
        $this->newLine();
        $this->line('Add a fail-closed policy method, then replace its TODO with application-owned authorization:');
        $this->line($this->policyFragment($input));
        $this->newLine();
        $this->line('Register this capability in your application provider after replacing every TODO:');
        $this->line($this->registrationSnippet($input));
        $this->newLine();
        $this->line('Then run: php artisan verdict:validate');

        return self::SUCCESS;
    }

    /** @return array{capability: string, model: string, ability: string, targetArgument: string, confirmation: bool, claim: bool, rateLimit: bool} */
    private function generatorInput(): array
    {
        $capability = $this->required('capability', 'Capability name');
        $model = $this->required('model', 'Model class');
        $ability = $this->required('ability', 'Authorization ability');
        $targetArgument = $this->required('target-argument', 'Target argument');

        if (! preg_match('/\A[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)*\z/', $capability)) {
            throw new InvalidArgumentException('Capability name must be dot-separated lowercase identifiers, for example orders.refund.');
        }

        if (! preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $targetArgument)) {
            throw new InvalidArgumentException('Target argument must be a valid argument identifier.');
        }

        return [
            'capability' => $capability,
            'model' => ltrim($model, '\\'),
            'ability' => $ability,
            'targetArgument' => $targetArgument,
            'confirmation' => $this->selected('confirmation', 'Require a human approval binding?'),
            'claim' => $this->selected('claim', 'Require at-most-once execution admission?'),
            'rateLimit' => $this->selected('rate-limit', 'Require a semantic rate limit?'),
        ];
    }

    private function required(string $key, string $question): string
    {
        $value = $key === 'capability' ? $this->argument($key) : $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            if ($this->input->isInteractive()) {
                $value = $this->ask($question);
            }
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The [--{$key}] value is required when not running interactively.");
        }

        return trim($value);
    }

    private function selected(string $option, string $question): bool
    {
        if ($this->option($option)) {
            return true;
        }

        return $this->input->isInteractive() && $this->confirm($question, false);
    }

    /** @param array{capability: string, model: string} $input
     * @return array{capability: string, test: string}
     */
    private function paths(array $input): array
    {
        $root = $this->option('path');
        $root = is_string($root) && trim($root) !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : base_path();
        $segments = explode('.', $input['capability']);
        $directories = array_map(Str::studly(...), array_slice($segments, 0, -1));
        $class = Str::studly((string) end($segments)).'Capability';
        $relative = $directories === [] ? '' : implode(DIRECTORY_SEPARATOR, $directories).DIRECTORY_SEPARATOR;

        return [
            'capability' => $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Capabilities'.DIRECTORY_SEPARATOR.$relative.$class.'.php',
            'test' => $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Feature'.DIRECTORY_SEPARATOR.'Capabilities'.DIRECTORY_SEPARATOR.$relative.$class.'Test.php',
        ];
    }

    /** @param array{capability: string, model: string, ability: string, targetArgument: string, confirmation: bool, claim: bool, rateLimit: bool} $input */
    private function capabilityStub(array $input): string
    {
        [$namespace, $class] = $this->classParts($input['capability']);
        [$modelClass, $modelImport] = $this->modelParts($input['model']);
        $controls = '';

        if ($input['confirmation']) {
            $controls .= "\n            ->requiresConfirmation(\n                bindUsing: function (ActionEnvelope \$envelope, {$modelClass} \$target): array {\n                    throw new LogicException('TODO: bind approval to canonical application-owned facts.');\n                },\n                reason: 'TODO: explain why this action needs approval.',\n            )";
        }

        if ($input['claim']) {
            $controls .= "\n            ->atMostOnce(ExecutionClaimPolicy::named(\n                name: '{$input['capability']}-once',\n                keyUsing: function (ActionEnvelope \$envelope, {$modelClass} \$target): array {\n                    throw new LogicException('TODO: bind duplicate admission to canonical application-owned identity.');\n                },\n            ))";
        }

        if ($input['rateLimit']) {
            $controls .= "\n            ->rateLimit(self::rateLimitPolicy())";
        }

        $claimUse = $input['claim'] ? "use Fissible\\Verdict\\ExecutionClaims\\ExecutionClaimPolicy;\n" : '';
        $rateLimitUse = $input['rateLimit'] ? "use Fissible\\Verdict\\RateLimits\\RateLimitPolicy;\n" : '';
        $rateLimitMethod = $input['rateLimit'] ? <<<'PHP'

    private static function rateLimitPolicy(): RateLimitPolicy
    {
        throw new LogicException('TODO: choose application-owned rate-limit scope, limit, window, and binding.');
    }
PHP : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$modelImport};
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
{$claimUse}{$rateLimitUse}use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use LogicException;

final class {$class}
{
    public static function make(): Capability
    {
        return Capability::usingPolicy(
            name: '{$input['capability']}',
            ability: '{$input['ability']}',
            resolveTarget: function (ActionEnvelope \$envelope): {$modelClass} {
                throw new LogicException('TODO: resolve {$input['targetArgument']} through tenant- and ownership-scoped application data.');
            },
        )
            ->executionTarget(ExecutionTargetPolicy::refresh(
                name: '{$input['capability']}-target',
                identityUsing: function (ActionEnvelope \$envelope, {$modelClass} \$target): array {
                    throw new LogicException('TODO: return stable, canonical target identity.');
                },
                refreshUsing: function (ActionEnvelope \$envelope, {$modelClass} \$target): {$modelClass} {
                    throw new LogicException('TODO: re-load the target through tenant- and ownership-scoped application data.');
                },
            )){$controls}
            ->executeUsing(function (AuthorizedAction \$action): void {
                throw new LogicException('TODO: implement the application side effect after its security material is defined.');
            });
    }
{$rateLimitMethod}
}
PHP;
    }

    /** @param array{capability: string, confirmation: bool, claim: bool, rateLimit: bool} $input */
    private function testStub(array $input): string
    {
        [, $class] = $this->classParts($input['capability']);
        $selected = '';

        foreach ([
            'confirmation' => [
                'approval binding invalidation',
                'assertApprovalBindingInvalidation(\$approvedEnvelope, \$approvedBy, \$invalidateBinding, \$assertNoSideEffects)',
            ],
            'claim' => [
                'execution-claim duplicate admission',
                'assertExecutionClaimDuplicateAdmission(\$firstEnvelope, \$duplicateEnvelope, \$assertOneSideEffect)',
            ],
            'rateLimit' => [
                'rate-limit enforcement',
                'assertRateLimitEnforcement(\$firstPermittedEnvelope, [], \$deniedEnvelope, \$assertExpectedSideEffects)',
            ],
        ] as $control => $label) {
            if ($input[$control]) {
                [$description, $assertion] = $label;
                $selected .= "\nit('checks {$description}', function (): void {\n    \$this->markTestIncomplete('TODO: provide application fixtures and call the capability security test kit.');\n\n    CapabilitySecurityTestKit::for(app(VerdictManager::class), '{$input['capability']}')\n        ->{$assertion};\n});\n";
            }
        }

        return <<<PHP
<?php

declare(strict_types=1);

use Fissible\Verdict\Testing\CapabilitySecurityTestKit;
use Fissible\Verdict\VerdictManager;

it('denies {$input['capability']} without executing', function (): void {
    \$this->markTestIncomplete('TODO: register {$class}::make(), provide a denied envelope, and assert policy observation plus no side effects.');

    CapabilitySecurityTestKit::for(app(VerdictManager::class), '{$input['capability']}')
        ->assertPolicyDenial(\$deniedEnvelope, \$assertPolicyWasApplied, \$assertNoSideEffects);
});

it('uses a refreshed {$input['capability']} target', function (): void {
    \$this->markTestIncomplete('TODO: provide proposal and refreshed target fixtures plus an executor side-effect assertion.');

    CapabilitySecurityTestKit::for(app(VerdictManager::class), '{$input['capability']}')
        ->assertRefreshedTargetSubstitution(\$permittedEnvelope, \$assertRefreshedSideEffects);
});
{$selected}
PHP;
    }

    /** @return array{string, string} */
    private function classParts(string $capability): array
    {
        $segments = explode('.', $capability);
        $class = Str::studly((string) array_pop($segments)).'Capability';
        $namespace = 'App\\Capabilities'.($segments === [] ? '' : '\\'.implode('\\', array_map(Str::studly(...), $segments)));

        return [$namespace, $class];
    }

    /** @return array{string, string} */
    private function modelParts(string $model): array
    {
        $class = Str::afterLast($model, '\\');
        $import = str_contains($model, '\\') ? $model : 'App\\Models\\'.Str::studly($model);

        return [$class, $import];
    }

    /** @param array{capability: string} $input */
    private function registrationSnippet(array $input): string
    {
        [$namespace, $class] = $this->classParts($input['capability']);

        return "app(\\Fissible\\Verdict\\VerdictManager::class)->capability(\\{$namespace}\\{$class}::make());";
    }

    /** @param array{model: string, ability: string} $input */
    private function policyFragment(array $input): string
    {
        [$modelClass] = $this->modelParts($input['model']);

        return "public function {$input['ability']}(mixed \$actor, {$modelClass} \$target): bool\n{\n    // TODO: authorize using tenant and ownership facts from this application.\n    return false;\n}";
    }
}
