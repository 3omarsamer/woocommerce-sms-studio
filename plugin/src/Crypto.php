<?php
namespace Azula\SmsStudio;

final class Crypto
{
    private string $key;
    public function __construct(string $encodedKey)
    {
        if (!function_exists('sodium_crypto_secretbox')) throw new \RuntimeException('Sodium encryption is required.');
        $key = base64_decode($encodedKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('A dedicated base64 32-byte SMS data key is required.');
        $this->key = $key;
    }
    public static function deployment(): self
    {
        if (!defined('AZULA_SMS_STUDIO_DATA_KEY')) throw new \RuntimeException('SMS data key is not configured. Audience storage is blocked.');
        return new self((string) constant('AZULA_SMS_STUDIO_DATA_KEY'));
    }
    public function seal(array $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($json, $nonce, $this->key));
    }
    public function open(string $value): array
    {
        if (!str_starts_with($value, 'v1:')) throw new \RuntimeException('Unsupported encrypted record.');
        $raw = base64_decode(substr($value, 3), true);
        if ($raw === false || strlen($raw) < 40) throw new \RuntimeException('Invalid encrypted record.');
        $plain = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), $this->key);
        if ($plain === false) throw new \RuntimeException('Encrypted record could not be authenticated.');
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
    public function correlate(string $value): string { return hash_hmac('sha256', 'phone:v1:' . $value, $this->key); }
    public function digest(array $value): string { return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), $this->key); }
}
