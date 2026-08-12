<?php

declare(strict_types=1);

it('keeps the independent-transaction guard and retry implementation behind the shared security-state boundary', function (): void {
    $src = realpath(__DIR__.'/../../src');
    $sourceRoot = str_replace('\\', '/', $src);
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if (! $file->isFile() || $file->getExtension() !== 'php' || str_starts_with($path, $sourceRoot.'/Support/')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (str_contains($source, 'TransactionRetry::') || str_contains($source, 'IndependentTransactionGuard::')) {
            $violations[] = str_replace($sourceRoot.'/', '', $path);
        }
    }

    expect($violations)->toBe([]);
});
