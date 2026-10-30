<?php
namespace Azula\SmsStudio;

/** No persistence dependency. At most one current 100-row keyset batch, no historical reconstruction. */
final class DiagnosticsReader
{
    public function __construct(private AudienceSource $source, private PhoneDiagnostics $diagnostics) {}
    public function inspect(int $segmentId, bool $originalRejected = false): array
    {
        $segment = $this->source->segment($segmentId);
        $page = $this->source->scan($segment, 0, $this->source->ceiling($segment));
        if (count($page['members']) > 100) throw new \RuntimeException('Diagnostic source exceeded its bound.');
        $rows = [];
        foreach ($page['members'] as $member) {
            $phone = (string) ($member['phone'] ?? '');
            $warning = !empty($member['identity_warning']);
            $row = $originalRejected ? OriginalPhoneDiagnostics::inspect($phone, $warning) : $this->diagnostics->inspect($phone, $warning);
            if ($row !== null) $rows[] = $row;
        }
        // Original rejected phones are returned only in explicit reveal mode. No identity metadata.
        return ['rows' => $rows, 'inspected' => count($page['members']), 'current_only' => true];
    }
}
