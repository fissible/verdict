<?php

declare(strict_types=1);

namespace Fissible\Verdict\Tests\Support;

use Fissible\Attest\Anchor\FileAnchorClaimStore;
use Fissible\Attest\Chain\ChainStore;
use Fissible\Attest\Chain\FileChainStore;
use Fissible\Attest\Signing\KeyPair;
use Fissible\Attest\Signing\SodiumSigner;
use Fissible\AttestLaravel\Support\AttestRegistry;

final class AttestFixture
{
    public static function store(): FileChainStore
    {
        return new FileChainStore(sys_get_temp_dir().'/verdict-attest-test-'.uniqid('', true));
    }

    public static function registry(?ChainStore $store = null): AttestRegistry
    {
        return new AttestRegistry(
            store: $store ?? self::store(),
            claimStore: new FileAnchorClaimStore(sys_get_temp_dir().'/verdict-attest-claims-'.uniqid('', true)),
            signer: new SodiumSigner(KeyPair::generate(), 'verdict-test'),
        );
    }
}
