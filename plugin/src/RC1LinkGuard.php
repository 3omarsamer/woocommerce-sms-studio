<?php
namespace Azula\SmsStudio;

/** Fixed 256 slots, atomic shared-DB counters, no raw IP or forwarded-header trust. */
final class RC1LinkGuard
{
    public const LIMIT=120;
    public static function slot(string $ip,Crypto $crypto):int{return hexdec(substr($crypto->digest(['short_link_network'=>$ip]),0,2));}
    public static function check(M6Store $s):void
    {
        $ip=$_SERVER['REMOTE_ADDR']??'';if(!filter_var($ip,FILTER_VALIDATE_IP))throw new ControlledTestException('Link unavailable.');
        // IPv6 privacy addresses in a /64 share a budget. IPv4 uses the direct peer address.
        if(str_contains($ip,':'))$ip=bin2hex(substr(inet_pton($ip),0,8));
        $slot=self::slot($ip,$s->crypto);$bucket=intdiv(time(),60);$name='azula_sms_short_guard_'.$slot;$db=$s->database->db;
        $options=$db->options??($db->prefix.'options');$value=$bucket.':1';
        $sql=$db->prepare("INSERT INTO $options (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=IF(SUBSTRING_INDEX(option_value,':',1)=%s,CONCAT(%s,':',CAST(SUBSTRING_INDEX(option_value,':',-1) AS UNSIGNED)+1),%s)",$name,$value,(string)$bucket,(string)$bucket,$value);
        if($db->query($sql)===false)throw new ControlledTestException('Link unavailable.');
        $v=$db->get_var($db->prepare("SELECT option_value FROM $options WHERE option_name=%s",$name));$parts=explode(':',(string)$v);
        if(count($parts)!==2||(int)$parts[0]!==$bucket||(int)$parts[1]>self::LIMIT)throw new ControlledTestException('Link unavailable.');
    }
}
