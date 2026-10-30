<?php
namespace Azula\SmsStudio;

/** Read-only pin-checked bridge. Never alters Stage A/B, Woo sessions or order metadata. */
final class M6AttributionContracts
{
    public static function pinned(string $class):bool
    {
        if(!class_exists($class))return false;$pins=require __DIR__.'/m6-attribution-pins.php';if(!isset($pins[$class]))return false;
        $file=(new \ReflectionClass($class))->getFileName();if(!$file||!is_file($file))return false;$raw=@file_get_contents($file);if(!is_string($raw))return false;$raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);$normalized=rtrim(str_replace("\r\n","\n",$raw),"\n")."\n";return in_array(hash('sha256',$normalized),$pins[$class],true);
    }
    public static function available():bool
    {
        return self::pinned('Azula_Attribution_Stage_A')&&self::pinned('Azula_Stage_B_Order_Attribution')&&\Azula_Attribution_Stage_A::VERSION==='0.1.3'&&\Azula_Stage_B_Order_Attribution::VERSION==='0.1.0'&&\Azula_Attribution_Stage_A::is_staging();
    }
    public static function state(Crypto $crypto,string $campaign):?array
    {
        if(!self::available()||!function_exists('WC')||!WC()||!WC()->session)return null;$state=WC()->session->get(\Azula_Attribution_Stage_A::WC_SESSION_KEY);if(!is_array($state))return null;
        foreach(['schema_version'=>1,'attribution_version'=>1,'policy_version'=>1,'mapping_version'=>2] as $key=>$expected)if((int)($state[$key]??0)!==$expected)return null;
        if(empty($state['attribution_session_id'])||strlen((string)$state['attribution_session_id'])>100||(int)($state['expires_at']??0)<time()||(int)($state['captured_at']??0)<=0||(int)($state['captured_at']??0)>time()+300||(int)($state['last_seen_at']??0)<(int)$state['captured_at']||(int)($state['last_seen_at']??0)>time()+300||(int)($state['expires_at']??0)<=(int)($state['last_seen_at']??0))return null;
        return ['matches'=>strtolower(trim((string)($state['source_raw']??'')))==='sms'&&strtolower(trim((string)($state['medium_raw']??'')))==='sms'&&($state['campaign_raw']??'')==='azs_'.$campaign,'session_hash'=>$crypto->digest(['stage_session'=>(string)$state['attribution_session_id']])];
    }
    public static function snapshot($order,Crypto $crypto):?array
    {
        if(!self::available()||!is_object($order)||!method_exists($order,'get_meta'))return null;
        foreach(['snapshot_version'=>1,'schema_version'=>1,'version'=>1,'policy_version'=>1,'mapping_version'=>2] as $key=>$expected)if((int)$order->get_meta('_azula_attr_'.$key,true)!==$expected)return null;
        $source=strtolower(trim((string)$order->get_meta('_azula_attr_source_raw',true)));$medium=strtolower(trim((string)$order->get_meta('_azula_attr_medium_raw',true)));$campaign=(string)$order->get_meta('_azula_attr_campaign_raw',true);$session=(string)$order->get_meta('_azula_attr_session_id',true);$snapshot=(int)$order->get_meta('_azula_attr_snapshot_at',true);
        if($source!=='sms'||$medium!=='sms'||!preg_match('/^azs_([a-f0-9]{32})$/D',$campaign,$m)||$session===''||strlen($session)>100||$snapshot<=0||$snapshot>time()+300)return null;
        return ['public_campaign'=>$m[1],'session_hash'=>$crypto->digest(['stage_session'=>$session]),'snapshot_at'=>$snapshot];
    }
    public static function exclusions():?array{return self::pinned('Azula_Analytics_Order_Eligibility')?\Azula_Analytics_Order_Eligibility::excluded_statuses():null;}
}
