<?php
namespace Azula\SmsStudio;

final class Security
{
    public static function authorize(string $capability, string $nonce): void
    {
        if (!current_user_can($capability) || !current_user_can('manage_woocommerce') || !current_user_can('edit_shop_orders') || !wp_verify_nonce($nonce, 'azula_sms_m1')) throw new \RuntimeException('SMS administration authorization failed.');
    }
}
