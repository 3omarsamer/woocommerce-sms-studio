<?php
namespace Azula\SmsStudio;

final class DispatchGate
{
    public static function available(): bool { return false; }
    public static function assertAvailable(): void { throw new \LogicException('M1 dispatch is structurally unavailable.'); }
    /** Pure future policy decision. This method cannot authorize transmission. */
    public static function recipientDecision(array $approved, bool $suppressed, ?string $currentPhoneHash): string
    {
        if ($suppressed) return 'suppressed';
        if ($currentPhoneHash === null || !hash_equals($approved['phone_hash'], $currentPhoneHash)) return 'identity_unverified';
        return 'unchanged_destination';
    }
}
