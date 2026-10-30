<?php
namespace Azula\SmsStudio;

/** Explicit deployment boundary; installing this release never enables dispatch. */
final class M11Environment
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.6','>=');}
    public static function production():bool
    {
        return function_exists('wp_get_environment_type')&&wp_get_environment_type()==='production'&&home_url('/')==='https://azulasportswear.com/';
    }
    public static function available():bool
    {
        return M2Scheduler::staging()||(self::modern()&&self::production()&&defined('AZULA_SMS_STUDIO_PRODUCTION_ENABLED')&&AZULA_SMS_STUDIO_PRODUCTION_ENABLED===true);
    }
    public static function option(string $name):string
    {
        // No fallback to copied STAGING credentials, permissions, pilot or runner evidence.
        return self::modern()&&!M2Scheduler::staging()?$name.'_production_v1':$name;
    }
    public static function dispatchReady():bool
    {
        if(!self::available())return false;
        if(!self::production())return true;
        return defined('AZULA_SMS_STUDIO_PRODUCTION_TRACKING_VERIFIED')&&AZULA_SMS_STUDIO_PRODUCTION_TRACKING_VERIFIED===true&&M7Settings::enabled()&&!M7Settings::conflict(M7Settings::config()['path'])&&M92Runner::status()['verified_recently'];
    }
    /** Fixed operator guidance only; dispatchReady remains the execution authority. */
    public static function dispatchProblem():string
    {
        if(!self::available())return 'Production dispatch requires the explicitly enabled exact Azula environment. Review environment type, canonical Home and production access configuration.';
        if(!self::production())return '';
        if(!defined('AZULA_SMS_STUDIO_PRODUCTION_TRACKING_VERIFIED')||AZULA_SMS_STUDIO_PRODUCTION_TRACKING_VERIFIED!==true)return 'Production dispatch requires owner tracking attestation. First verify a normal production link and consent behavior, then configure AZULA_SMS_STUDIO_PRODUCTION_TRACKING_VERIFIED as boolean true in LIVE wp-config.php. A homepage diagnostic probe alone is not sufficient.';
        if(!M7Settings::enabled())return 'Production dispatch requires tracking collection enabled under the current production policy. Valid links can still redirect with collection OFF; disabling collection does not authorize sending.';
        if(M7Settings::conflict(M7Settings::config()['path']))return 'Production dispatch requires a conflict-free short-link path. Review Branded links & tracking collection; do not change issued paths or bypass the conflict check.';
        if(!M92Runner::status()['verified_recently'])return 'Production dispatch requires a fresh production runner probe. The harmless probe runs automatically; check Action Scheduler and the existing every-minute server cron if it stays stale. A manual page request does not prove unattended execution.';
        return '';
    }
}
