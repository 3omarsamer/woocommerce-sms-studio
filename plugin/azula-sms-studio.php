<?php
/**
 * Plugin Name: Azula SMS Studio
 * Description: Independent campaign approvals, explicit environment-controlled SMS Misr execution, protected retargeting and snapshot-constrained analytics. Controlled Live defaults OFF.
 * Version: 1.0.0-rc.18
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Azula Sportswear
 * Text Domain: azula-sms-studio
 */
defined('ABSPATH') || exit;
define('AZULA_SMS_STUDIO_VERSION', '1.0.0-rc.18');
define('AZULA_SMS_STUDIO_FILE', __FILE__);
spl_autoload_register(static function (string $class): void {
    $prefix = 'Azula\\SmsStudio\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) return;
    $file = __DIR__ . '/src/' . $name . '.php';
    if (is_file($file)) require_once $file;
});
register_activation_hook(__FILE__, [\Azula\SmsStudio\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\Azula\SmsStudio\Plugin::class, 'deactivate']);
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
add_action('plugins_loaded', [\Azula\SmsStudio\Plugin::class, 'boot'], 40);
