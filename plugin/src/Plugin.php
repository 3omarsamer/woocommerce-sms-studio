<?php
namespace Azula\SmsStudio;

final class Plugin
{
    public const CAPS = ['view_azula_sms_reports', 'manage_azula_sms_campaigns', 'approve_azula_sms_campaigns', 'dispatch_azula_sms_campaigns', 'manage_azula_sms_settings', 'manage_azula_sms_suppressions'];
    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) wp_die('SMS Studio supports per-site activation only.');
        global $wpdb;
        try {
            Schema::migrate(new Database($wpdb));
            $role = get_role('administrator');
            if ($role) foreach (self::CAPS as $cap) $role->add_cap($cap);
        } catch (\Throwable $e) { wp_die('SMS Studio schema initialization failed. Campaign operations remain blocked until SMS schema verification passes.'); }
    }
    public static function deactivate(): void { M2Scheduler::deactivate(); }
    public static function boot(): void
    {
        (new M2Screen())->register();
        // Preserve isolated maintenance actions; the developer screen is not the campaign dashboard.
        add_action('admin_post_azula_sms_m1', [new Admin(), 'post']);
        (new DiagnosticsScreen())->register();
        M2Scheduler::register();
        M3Scheduler::register();
        M6Worker::register();
        if(M9Schema::modern())M9Api::register();
        M6Front::register();
        SmsConsent::register();
        M7Front::register();
        add_action('admin_post_azula_sms_analytics_export',[M6AnalyticsCsv::class,'download']);
    }
}
