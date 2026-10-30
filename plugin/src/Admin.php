<?php
namespace Azula\SmsStudio;

final class Admin
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_azula_sms_m1', [$this, 'post']);
    }
    public function menu(): void
    {
        add_menu_page('Azula SMS Studio', 'Azula SMS Studio', 'manage_azula_sms_campaigns', 'azula-sms-studio', [$this, 'screen'], 'dashicons-format-chat', 59);
    }
    private function store(): WordPressStore
    {
        global $wpdb;
        if (!Schema::ready()) throw new \RuntimeException('SMS schema requires an explicit administrator upgrade.');
        Schema::verify(new Database($wpdb));
        return new WordPressStore(new Database($wpdb));
    }
    public static function coverageHtml(): string
    {
        return '<div class="notice notice-info inline"><p>' . esc_html(CustomerSegmentsAdapterV1::COVERAGE) . '</p><p>Outside-source customers without email: <strong>not measured by this preparation</strong>. No additional email-subscription eligibility filter is applied.</p></div>';
    }
    public function post(): void
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        $id = 0;
        try {
            $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
            $cap = $operation === 'suppress' ? 'manage_azula_sms_suppressions' : (in_array($operation, ['upgrade', 'purge'], true) ? 'manage_azula_sms_settings' : 'manage_azula_sms_campaigns');
            Security::authorize($cap, (string) wp_unslash($_POST['_wpnonce'] ?? ''));
            if ($operation === 'upgrade') { Schema::migrate(new Database($wpdb)); }
            elseif ($operation === 'purge') { $this->store()->purgeExpired(time()); }
            elseif ($operation === 'suppress') {
                $crypto = Crypto::deployment();
                $n = (new EgyptMobileNormalizer())->normalize((string) wp_unslash($_POST['phone'] ?? ''));
                if (!$n['phone']) throw new \InvalidArgumentException('Invalid suppression phone.');
                $active = ($_POST['suppression_state'] ?? '') === 'active';
                if (!in_array($_POST['suppression_state'] ?? '', ['active', 'revoked'], true)) throw new \InvalidArgumentException('Invalid suppression action.');
                $this->store()->suppress($crypto->correlate($n['phone']), $active, $active ? 'explicit_sms_optout' : 'owner_revocation');
            } else {
                $store = $this->store(); $source = new CustomerSegmentsAdapterV1($wpdb);
                $id = absint($_POST['campaign_id'] ?? 0);
                if ($operation === 'create') {
                    $segment = absint($_POST['segment_id'] ?? 0); $source->segment($segment);
                    $local = (string) wp_unslash($_POST['local_start'] ?? '');
                    $timezone = (string) wp_unslash($_POST['timezone'] ?? 'UTC');
                    $start = $local === '' ? null : ApprovalPolicy::schedule($local, $timezone, (string) wp_unslash($_POST['utc_offset'] ?? ''));
                    if ($start !== null && $start <= time()) throw new \InvalidArgumentException('Planned time must be in the future.');
                    $id = $store->createCampaign(sanitize_text_field(wp_unslash($_POST['name'] ?? '')), $segment, $start, $timezone);
                } elseif ($operation === 'prepare') {
                    $service = new AudiencePreparationService($source, $store, Crypto::deployment());
                    $campaign = $store->campaign($id);
                    if (!$campaign['audience_id'] || !empty($_POST['refresh'])) $service->begin($id, time());
                    else $service->step((int) $campaign['audience_id'], time());
                } elseif ($operation === 'abandon') {
                    $campaign = $store->campaign($id);
                    if ($campaign['audience_id']) $store->fail((int) $campaign['audience_id']);
                } else throw new \InvalidArgumentException('Unsupported M1 operation.');
            }
            $url = add_query_arg(['page' => 'azula-sms-studio', 'campaign' => $id]+(M10::modern()?['sms_success'=>'1']:[]), admin_url('admin.php'));
        } catch (\Throwable $e) {
            $url = add_query_arg(['page' => 'azula-sms-studio', 'campaign' => $id, 'sms_error' => '1'], admin_url('admin.php'));
        } finally { $wpdb->suppress_errors($previous); }
        wp_safe_redirect($url); exit;
    }
    private function formStart(string $operation, int $campaign = 0): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('azula_sms_m1');
        echo '<input type="hidden" name="action" value="azula_sms_m1"><input type="hidden" name="operation" value="' . esc_attr($operation) . '"><input type="hidden" name="campaign_id" value="' . (int) $campaign . '">';
    }
    public function screen(): void
    {
        if (!current_user_can('manage_azula_sms_campaigns') || !current_user_can('manage_woocommerce') || !current_user_can('edit_shop_orders')) { wp_die('Not authorized.'); return; }
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        echo '<div class="wrap"><h1>Azula SMS Studio <small>M1.3 · 0.1.3</small></h1><p><strong>Dispatch unavailable.</strong> Planning and audience preparation only. No composer, final approval, provider connection, tracking redirect or sending job exists.</p>';
        echo self::coverageHtml();
        echo '<p>Audiences are captured over a disclosed interval, not an atomic database snapshot. Reviewed recipients never expand automatically. Approval policy is foundation-only in M1.</p>';
        if (!empty($_GET['sms_error'])) echo '<div class="notice notice-error"><p>Operation blocked. Check source compatibility, permissions, data-key configuration, schema and capture age. No dispatch occurred.</p></div>';
        try {
            if (!Schema::ready()) {
                echo '<p>Schema upgrade required. No automatic migration runs on page load.</p>';
                if (current_user_can('manage_azula_sms_settings') && current_user_can('activate_plugins')) { $this->formStart('upgrade'); echo '<button class="button">Upgrade SMS-owned schema</button></form>'; }
            } else {
                $store = $this->store();
                $source = new CustomerSegmentsAdapterV1($wpdb);
                $after = absint($_GET['segment_after'] ?? 0); $catalog = $source->catalog($after);
                echo '<h2>Create draft or campaign plan</h2>';
                $this->formStart('create');
                echo '<p><label>Name <input name="name" maxlength="190" required></label></p><p><label>Saved segment <select name="segment_id" required>';
                foreach ($catalog as $segment) echo '<option value="' . (int) $segment['id'] . '">' . esc_html($segment['name']) . ' (#' . (int) $segment['id'] . ')</option>';
                echo '</select></label></p><p>Optional intended time (planning only): <input name="local_start" placeholder="2026-11-20 10:00"> Timezone: <input name="timezone" value="' . esc_attr(wp_timezone_string() ?: 'UTC') . '"> Explicit UTC offset: <input name="utc_offset" placeholder="+02:00"></p><button class="button button-primary">Save draft / plan</button></form>';
                if (count($catalog) === 100) echo '<p><a href="' . esc_url(add_query_arg(['page' => 'azula-sms-studio', 'segment_after' => end($catalog)['id']], admin_url('admin.php'))) . '">Next segment catalog page</a></p>';
                if ($after) echo '<p><a href="' . esc_url(admin_url('admin.php?page=azula-sms-studio')) . '">First segment catalog page</a></p>';
                echo '<h2>Campaign drafts and plans</h2>';
                $db = new Database($wpdb); $rows = $wpdb->get_results('SELECT id,name,state FROM ' . $db->table('campaigns') . ' ORDER BY id DESC LIMIT 100', ARRAY_A); $db->check($rows);
                echo '<ul>';
                foreach ($rows as $row) echo '<li><a href="' . esc_url(add_query_arg(['page' => 'azula-sms-studio', 'campaign' => $row['id']], admin_url('admin.php'))) . '">' . esc_html($row['name']) . '</a> — ' . esc_html($row['state']) . '</li>';
                echo '</ul><p>Showing the most recent 100 plans.</p>';
                $id = absint($_GET['campaign'] ?? 0);
                if ($id) $this->preview($store, $id);
            }
        } catch (\Throwable $e) { echo '<div class="notice notice-warning"><p>Preparation unavailable. Matching Customer Segments 0.1.6.8 source, WooCommerce and verified SMS schema are required.</p></div>'; }
        if (current_user_can('manage_azula_sms_suppressions')) {
            echo '<h2>Explicit SMS suppression</h2><p>Independent of email preferences. Enter a phone only to manage an explicit SMS suppression. The plaintext number is not retained in this ledger.</p>';
            $this->formStart('suppress'); echo '<input name="phone" autocomplete="off" required aria-label="SMS suppression phone"><select name="suppression_state"><option value="active">Record explicit SMS opt-out</option><option value="revoked">Revoke suppression under owner policy</option></select><button class="button">Save SMS suppression</button></form>';
        }
        if (Schema::ready() && current_user_can('manage_azula_sms_settings')) {
            echo '<h2>Retention maintenance</h2><p>Remove private rows from blocked captures older than 7 days and completed captures older than 90 days, up to 100 generations. Aggregate counts/manifests and SMS suppressions remain. No automatic cleanup job runs in M1.</p>';
            $this->formStart('purge'); echo '<button class="button">Purge expired capture PII</button></form>';
        }
        echo '</div>';
        $wpdb->suppress_errors($previous);
    }
    private function preview(WordPressStore $store, int $id): void
    {
        $c = $store->campaign($id);
        echo '<h2>' . esc_html($c['name']) . '</h2><p>Revision ' . (int) $c['revision'] . '. Intended UTC: ' . ($c['start_at'] ? esc_html(gmdate('Y-m-d H:i', (int) $c['start_at'])) : 'not planned') . '; timezone ' . esc_html($c['timezone']) . '. This is not a dispatch schedule.</p>';
        if (!$c['audience_id']) { $this->formStart('prepare', $id); echo '<button class="button">Start audience preparation</button></form>'; return; }
        $a = $store->audience((int) $c['audience_id']);
        echo '<p>Generation ' . (int) $a['generation'] . ' — ' . esc_html($a['state']) . '. Captured from ' . esc_html(gmdate('c', (int) $a['started_at'])) . ' to ' . ($a['completed_at'] ? esc_html(gmdate('c', (int) $a['completed_at'])) : 'in progress') . '.</p><p>Manifest: <code>' . esc_html($a['manifest_hash'] ?: 'incomplete — not approval eligible') . '</code></p>';
        echo '<table class="widefat"><tbody>';
        foreach (json_decode($a['counts_json'], true) as $reason => $count) echo '<tr><th>' . esc_html($reason) . '</th><td>' . (int) $count . '</td></tr>';
        echo '</tbody></table>';
        if ($a['state'] === 'preparing') {
            $this->formStart('prepare', $id); echo '<p><button class="button">Process next bounded batch (up to 100)</button></p></form>';
            $this->formStart('abandon', $id); echo '<button class="button">Abandon incomplete preparation</button></form>';
        } else {
            $this->formStart('prepare', $id); echo '<input type="hidden" name="refresh" value="1"><p><button class="button">Prepare replacement audience for review</button></p></form>';
        }
        global $wpdb;
        if ($a['state'] === 'complete') {
            $db = new Database($wpdb);
            $previousId = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $db->table('audiences') . ' WHERE campaign_id=%d AND generation<%d AND state=%s ORDER BY generation DESC LIMIT 1', $id, $a['generation'], 'complete')); $db->check(true);
            if ($previousId) { echo '<h3>Refresh comparison with previous complete generation</h3><ul>'; foreach ($store->differences($previousId, (int) $a['id']) as $label => $count) echo '<li>' . esc_html($label) . ': ' . (int) $count . '</li>'; echo '</ul>'; }
            echo '<p>Recipient PII is encrypted. This M1 preview exposes counts only. Operational composition and final approval belong to later milestones.</p>';
        }
    }
}
