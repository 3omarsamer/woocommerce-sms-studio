<?php
namespace Azula\SmsStudio;

interface AudienceStore
{
    public function campaign(int $id): array;
    public function begin(int $campaign, array $segment, int $ceiling, int $now): array;
    public function audience(int $id): array;
    public function atomic(int $id, callable $callback): array;
    public function suppressed(string $phoneHash): bool;
    public function recipient(int $audience, string $phoneHash): ?array;
    public function saveRecipient(array $audience, string $hash, string $payload, string $digest): void;
    public function saveMember(int $audience, int $source, ?string $phoneHash, string $disposition, string $payload, string $digest): void;
    public function checkpoint(int $id, int $cursor, array $counts): void;
    public function manifestRows(int $audience): iterable;
    public function complete(int $id, string $manifest, int $now): void;
    public function fail(int $id): void;
    public function differences(int $old, int $new): array;
}
