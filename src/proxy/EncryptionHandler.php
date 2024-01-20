<?php

namespace proxy;

use OpenSSLAsymmetricKey;
use RuntimeException;

class EncryptionHandler
{
    private OpenSSLAsymmetricKey $privateKey;
    // private string $publicKeyDER;

    private EncryptedSession|null $session = null;

    public function __construct()
    {
        // Source:
        // https://github.com/PrismarineJS/bedrock-protocol/blob/master/src/handshake/keyExchange.js

        // Generate a new EC key pair
        $this->privateKey = $privateKey = openssl_pkey_new(["ec" => ["curve_name" => "secp384r1"]]);
        if ($privateKey === false) {
            throw new RuntimeException("Failed to generate ECDH key pair (" . openssl_error_string() . ")");
        }

        // Get the details of the key, which include the public key
        // $keyDetails = openssl_pkey_get_details($privateKey);

        // The public key is already in PEM format
        // $publicKeyPEM = $keyDetails['key'];

        // To convert the public key to DER format, we remove the PEM headers and footers and decode the base64
        // $publicKeyDER = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', $publicKeyPEM);
        // $this->publicKeyDER = base64_decode($publicKeyDER);

        // var_dump($this->publicKeyDER);
    }

    public function startSession(string $encryptionKey): void {
        $this->session = new EncryptedSession($encryptionKey);
    }

    public function getSession(): ?EncryptedSession
    {
        return $this->session;
    }

    public static function generateSharedSecret(OpenSSLAsymmetricKey $localePrivateKey, OpenSSLAsymmetricKey $remotePublicKey): string
    {
        $hexSecret = openssl_pkey_derive($remotePublicKey, $localePrivateKey, 48);
        if ($hexSecret === false) {
            throw new RuntimeException("Failed to generate shared secret (" . openssl_error_string() . ")");
        }
        return gmp_init(bin2hex($hexSecret), 16);
    }

    public static function getDer(string $base64x5u): false|OpenSSLAsymmetricKey
    {
        $publicKeyPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($base64x5u, 64) . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($publicKeyPem);
    }

    public function getPrivateKey(): OpenSSLAsymmetricKey
    {
        return $this->privateKey;
    }
}