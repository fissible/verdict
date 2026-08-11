<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests;

use Fissible\AttestLaravel\AttestServiceProvider;
use Illuminate\Foundation\Application;

abstract class AttestTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AttestServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        putenv('ATTEST_SIGNING_KEY_SEED='.base64_encode(random_bytes(32)));
        putenv('ATTEST_SIGNING_KEY_ID=verdict-test');
    }

    protected function tearDown(): void
    {
        putenv('ATTEST_SIGNING_KEY_SEED');
        putenv('ATTEST_SIGNING_KEY_ID');

        parent::tearDown();
    }
}
