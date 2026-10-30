<?php
namespace Azula\SmsStudio;

final class DiagnosticsScreen
{
    public function register(): void { add_action('admin_menu', [$this, 'menu']); }
    public function menu(): void
    {
        if (DiagnosticsAccess::current()) {
            $hook = add_submenu_page('azula-sms-studio', 'Phone diagnostics', 'Phone diagnostics', 'manage_options', 'azula-sms-phone-diagnostics', [$this, 'screen']);
            if ($hook) add_action('load-' . $hook, [$this, 'headers']);
        }
    }
    public function headers(): void
    {
        if (!DiagnosticsAccess::current()) { wp_die('Diagnostic access denied.', '', ['response' => 403]); return; }
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0', true);
        header('Referrer-Policy: no-referrer', true);
        header('X-Robots-Tag: noindex, nofollow', true);
        header('X-Content-Type-Options: nosniff', true);
    }
    public function screen(): void
    {
        if (!DiagnosticsAccess::current()) { wp_die('Phone diagnostics require explicitly enabled STAGING access and administrator capabilities.', '', ['response' => 403]); return; }
        // load-$hook sends headers before WordPress emits the administrator page.
        if (!headers_sent()) $this->headers();
        $diagnostics = new PhoneDiagnostics();
        echo '<div class="wrap"><h1>Phone validation diagnostics · STAGING only</h1><p>Read-only, temporary diagnostics. No values are repaired, saved, exported or sent. Current reads do not reconstruct previously rejected originals.</p>';
        echo '<p>' . esc_html(CustomerSegmentsAdapterV1::COVERAGE) . '</p>';
        $selfTest = $diagnostics->selfTest();
        echo '<h2>Installed normalizer synthetic self-test</h2><p>Normalizer version ' . (int) $selfTest['normalizer_version'] . '; SHA-256 <code>' . esc_html($selfTest['normalizer_sha256']) . '</code>.</p><ul>';
        foreach ($selfTest['cases'] as $case) echo '<li>' . esc_html($case['case']) . ': <strong>' . esc_html($case['status']) . '</strong></li>';
        echo '</ul><h2>Inspect a current segment batch</h2><p>Each manual request inspects only the first current keyset batch (up to 100 members) of the selected saved segment. No automatic pagination, customer identifiers or export is provided. Segment number is a saved-segment reference, not a customer identifier.</p>';
        echo '<form method="post" autocomplete="off">';
        wp_nonce_field('azula_sms_diagnostics');
        echo '<label>Saved segment number <input type="number" min="1" name="segment" required></label> <p><label><input type="checkbox" name="original_rejected" value="1"> Reveal original rejected phones for this request only (private administrator review).</label> Originals are sensitive. Do not copy, screenshot or share them. Valid, missing and identity-unverified phones are excluded from this view.</p><button class="button" name="inspect_current" value="1">Inspect current batch</button></form>';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $nonce = $_POST['_wpnonce'] ?? null;
                if (!is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'azula_sms_diagnostics')) throw new \RuntimeException('Invalid diagnostic request.');
                $id = $_POST['segment'] ?? null;
                if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id)) throw new \RuntimeException('Invalid saved segment number.');
                $originalRejected = isset($_POST['original_rejected']);
                if ($originalRejected && ($_POST['original_rejected'] !== '1' || strtolower(rtrim((string) wp_parse_url(home_url('/'), PHP_URL_HOST), '.')) !== 'preview.azulasportswear.com')) throw new \RuntimeException('Original inspection is restricted to the approved preview host.');
                global $wpdb;
                $previous = $wpdb->suppress_errors(true);
                try {
                    $reader = new DiagnosticsReader(new CustomerSegmentsAdapterV1($wpdb), $diagnostics);
                    $result = $reader->inspect((int) $id, $originalRejected);
                } finally { $wpdb->suppress_errors($previous); }
                echo '<h2>Current masked inspection</h2><p>Inspected ' . (int) $result['inspected'] . ' current records. Valid phone does not imply dispatch eligibility: duplicate/suppression decisions are separate. Identity-unverified values are fully withheld.</p>';
                echo $originalRejected ? self::originalTable($result['rows']) : self::table($result['rows']);
            } catch (\Throwable $e) { echo '<div class="notice notice-error"><p>Inspection refused or unavailable. Check nonce, segment number, exact Customer Segments compatibility and source availability. No raw error or customer information is shown.</p></div>'; }
        }
        echo '</div>';
    }
    public static function table(array $rows): string
    {
        $html = '<table class="widefat striped"><thead><tr><th>Masked original</th><th>ASCII / decimal digits</th><th>Prefix category</th><th>Formatting flags</th><th>Normalization / masked result</th><th>Specific reason</th><th>M1 phone classification</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            // Fixed allowlist only; unknown keys and source metadata cannot become HTML/data attributes.
            $values = [$row['masked_original'], ($row['ascii_digits'] ?? 'unavailable') . ' / ' . ($row['decimal_digits'] ?? 'unavailable'), $row['prefix'], implode(', ', $row['flags']) ?: 'none', $row['normalization'] . ' / ' . $row['masked_normalized'], $row['reason'] . ' (normalizer: ' . $row['normalizer_reason'] . ')', $row['classification']];
            $html .= '<tr>';
            foreach ($values as $value) $html .= '<td>' . esc_html((string) $value) . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }
    public static function originalTable(array $rows): string
    {
        $html = '<h2>Original rejected phones — current request only</h2><p>These are current source values rejected by the unchanged validator, not recovered historical captures. Potential validity is a pattern assessment, not ownership or delivery verification. Invisible characters are also shown in an escaped representation.</p><table class="widefat striped"><thead><tr><th>Original phone</th><th>Escaped representation</th><th>ASCII / decimal digits</th><th>Validation failure</th><th>Potential Egyptian mobile</th><th>Assessment</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $original = $row['original'];
            $values = [$original, json_encode($original, JSON_INVALID_UTF8_SUBSTITUTE), ($row['ascii_digits'] ?? 'unavailable') . ' / ' . ($row['decimal_digits'] ?? 'unavailable'), $row['reason'] . ' (normalizer: ' . $row['normalizer_reason'] . '); flags: ' . (implode(', ', $row['flags']) ?: 'none'), $row['potential'], $row['assessment']];
            $html .= '<tr>';
            foreach ($values as $value) $html .= '<td><bdi style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html((string) $value) . '</bdi></td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }
}
