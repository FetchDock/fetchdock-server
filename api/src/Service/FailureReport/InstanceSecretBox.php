<?php

namespace App\Service\FailureReport;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the collector instance secret at rest with a key derived from APP_SECRET (sodium secretbox). A changed
 * APP_SECRET makes the stored secret unreadable, and the instance registers again.
 */
final readonly class InstanceSecretBox
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        $this->key = hash_hmac('sha256', 'fetchdock-collector-instance-secret', $appSecret, true);
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_bin2base64($nonce.sodium_crypto_secretbox($secret, $nonce, $this->key), \SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(?string $encrypted): ?string
    {
        if (null === $encrypted || '' === $encrypted) {
            return null;
        }
        try {
            $raw = sodium_base642bin($encrypted, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            return null;
        }
        if (\strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $secret = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key,
        );

        return false === $secret ? null : $secret;
    }
}
