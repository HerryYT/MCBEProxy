<?php

namespace proxy;

use Crypto\Cipher;
use pocketmine\utils\Binary;
use RuntimeException;

class EncryptedSession
{
    private Cipher $encryptCipher;
    private int $encryptCounter = 0;
    private Cipher $decryptCipher;
    private int $decryptCounter = 0;

    public function __construct(private string $encryptionKey) {
        // Source:
        // https://github.com/pmmp/PocketMine-MP/blob/stable/src/network/mcpe/encryption/EncryptionContext.php#L67
        $iv = substr($encryptionKey, 0, 12) . "\x00\x00\x00\x02";
        $this->encryptCipher = new Cipher(
            "AES-256-CTR"
        );
        $this->encryptCipher->encryptInit($encryptionKey, $iv);
        $this->decryptCipher = new Cipher(
            "AES-256-CTR"
        );
        $this->decryptCipher->decryptInit($encryptionKey, $iv);
    }

    public function encrypt(string $data): string {
        return $this->encryptCipher->encryptUpdate($data . $this->calculateChecksum($this->encryptCounter++, $data));
    }

    public function decrypt(string $data): string {
        $decrypted = $this->decryptCipher->decryptUpdate($data);
        $payload = substr($decrypted, 0, -8);

        $packetCounter = $this->decryptCounter++;

        if(($expected = $this->calculateChecksum($packetCounter, $payload)) !== ($actual = substr($decrypted, -8))) {
            throw new RuntimeException(
                "Invalid checksum in encrypted packet (expected " . bin2hex($expected) . ", got " . bin2hex($actual) . ")"
            );
        }
        return $payload;
    }

    private function calculateChecksum(int $counter, string $payload) : string{
        $hash = openssl_digest(Binary::writeLLong($counter) . $payload . $this->encryptionKey, 'sha256', true);
        if($hash === false){
            throw new RuntimeException("Failed to calculate checksum (" . openssl_error_string() . ")");
        }
        return substr($hash, 0, 8);
    }
}