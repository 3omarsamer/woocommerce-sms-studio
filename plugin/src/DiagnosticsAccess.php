<?php
namespace Azula\SmsStudio;

final class DiagnosticsAccess
{
    public static function allowed(string $environment, string $host, bool $enabled, bool $admin, bool $sms, bool $woo, bool $orders): bool
    {
        $host = strtolower(rtrim($host, '.'));
        return $enabled && $environment === 'staging' && $host !== ''
            && !in_array($host, ['azulasportswear.com', 'www.azulasportswear.com'], true)
            && $admin && $sms && $woo && $orders;
    }
    public static function current(): bool
    {
        return self::allowed(wp_get_environment_type(), (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            defined('AZULA_SMS_STUDIO_DIAGNOSTICS_ENABLED') && constant('AZULA_SMS_STUDIO_DIAGNOSTICS_ENABLED') === true,
            current_user_can('manage_options'), current_user_can('manage_azula_sms_settings'),
            current_user_can('manage_woocommerce'), current_user_can('edit_shop_orders'));
    }
}
