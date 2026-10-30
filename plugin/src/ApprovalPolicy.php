<?php
namespace Azula\SmsStudio;

final class ApprovalPolicy
{
    public const CAPTURE_SECONDS = 1800;
    public const FRESH_SECONDS = 7200;
    public const WINDOW_SECONDS = 86400;
    public const BOUND = ['revision', 'audience_id', 'manifest', 'segment_id', 'rules_hash', 'capture_started', 'capture_completed', 'adapter_version', 'key_version', 'counts_hash', 'message_hash', 'links_hash', 'sender', 'language', 'environment', 'start', 'dispatch_end', 'timezone', 'normalizer_version', 'renderer_version', 'policy_version'];
    public function fingerprint(array $binding): string
    {
        $out = [];
        foreach (self::BOUND as $key) {
            if (!array_key_exists($key, $binding)) throw new \InvalidArgumentException('Incomplete approval binding: ' . $key);
            $out[$key] = $binding[$key];
        }
        return hash('sha256', json_encode($out, JSON_THROW_ON_ERROR));
    }
    public function validate(array $campaign, array $audience, int $now): array
    {
        $errors = [];
        $start = (int) $campaign['start'];
        if ($now < $start - self::WINDOW_SECONDS) $errors[] = 'approval_window_not_open';
        if ($now > $start) $errors[] = 'intended_start_passed';
        if (($audience['state'] ?? '') !== 'complete') $errors[] = 'capture_incomplete';
        $finished = (int) ($audience['completed'] ?? 0);
        if ($finished <= 0 || $finished > $now || $finished < (int) $audience['started'] || $finished - (int) $audience['started'] > self::CAPTURE_SECONDS) $errors[] = 'capture_invalid';
        if ($now - $finished > self::FRESH_SECONDS) $errors[] = 'audience_stale';
        return $errors;
    }
    /** Immutable evidence factory for isolated policy testing; no UI/dispatch authority. */
    public function record(array $binding, array $audience, int $actor, int $now): array
    {
        if ($actor < 1 || $this->validate($binding, $audience, $now)) throw new \DomainException('Approval policy requirements are not met.');
        foreach (['audience_id' => 'id', 'manifest' => 'manifest', 'segment_id' => 'segment_id', 'rules_hash' => 'rules_hash', 'capture_started' => 'started', 'capture_completed' => 'completed', 'adapter_version' => 'adapter_version', 'key_version' => 'key_version', 'counts_hash' => 'counts_hash'] as $field => $source) {
            if (!array_key_exists($source, $audience) || !array_key_exists($field, $binding) || $binding[$field] !== $audience[$source]) throw new \DomainException('Approval binding does not match the captured audience.');
        }
        if (($binding['dispatch_end'] ?? 0) !== (int) $binding['start'] + self::WINDOW_SECONDS) throw new \DomainException('Invalid dispatch expiry.');
        return ['binding_hash' => $this->fingerprint($binding), 'binding' => $binding, 'actor' => $actor, 'approved_at' => $now, 'intended_start' => (int) $binding['start'], 'expires_at' => (int) $binding['start'] + self::WINDOW_SECONDS, 'operational' => false];
    }
    public function unchanged(array $record, array $binding): bool { return hash_equals($record['binding_hash'], $this->fingerprint($binding)); }
    public static function audienceEvidence(array $capture): array
    {
        return ['id' => (int) $capture['id'], 'state' => $capture['state'], 'manifest' => $capture['manifest_hash'], 'segment_id' => (int) $capture['segment_id'], 'rules_hash' => $capture['rules_hash'], 'started' => (int) $capture['started_at'], 'completed' => (int) $capture['completed_at'], 'adapter_version' => $capture['adapter_version'], 'key_version' => $capture['key_version'], 'counts_hash' => hash('sha256', $capture['counts_json'])];
    }
    public function dispatchWindowOpen(array $record, int $now): bool { return $now >= $record['intended_start'] && $now <= $record['expires_at']; }
    public function assertRefreshable(bool $hasTransmissionIntent): void
    {
        if ($hasTransmissionIntent) throw new \DomainException('A transmitted campaign cannot refresh its audience.');
    }
    public static function schedule(string $local, string $timezone, string $offset): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D', $local) || !preg_match('/^[+-]\d{2}:\d{2}$/D', $offset)) throw new \InvalidArgumentException('An explicit local minute, timezone and UTC offset are required.');
        $zone = new \DateTimeZone($timezone);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:iP', $local . $offset);
        if (!$date || $date->setTimezone($zone)->format('Y-m-d H:iP') !== $local . $offset) throw new \InvalidArgumentException('Invalid or nonexistent local time/offset.');
        return $date->getTimestamp();
    }
}
