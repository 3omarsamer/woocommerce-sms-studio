<?php
namespace Azula\SmsStudio;
/** Separate default-OFF bulk authority. Legacy manual-one permission is never bulk authority. */
final class M9Policy
{
    public const OPTION='azula_sms_bulk_policy';
    public static function status():array
    {
        if(function_exists('wp_cache_delete')){wp_cache_delete(M11Environment::option(self::OPTION),'options');wp_cache_delete('notoptions','options');}
        $default=['enabled'=>false,'automatic'=>false,'emergency'=>false,'limit'=>M91Campaign::modern()?1:3,'batch'=>1,'interval'=>15,'pilot'=>0,'revision'=>'off','budget_units'=>null,'pilot_proof'=>null];
        try{$raw=get_option(M11Environment::option(self::OPTION),'');if(is_string($raw)&&$raw!==''){$p=Crypto::deployment()->open($raw);if(is_array($p))$default=array_intersect_key($p,$default)+$default;}}catch(\Throwable $e){}
        if(M91Campaign::modern()&&!$default['pilot'])$default['limit']=min(1,(int)$default['limit']);
        if(!M11Environment::available())$default['enabled']=$default['automatic']=false;return $default;
    }
    public static function save(M6Store $s,array $p):array
    {
        if(M92Policy::modern())return M92Policy::save($s,$p);
        M6SendPolicy::authorize($p);
        if(!M11Environment::available())throw new ControlledTestException('Bulk controls require an explicitly enabled Azula environment.');
        foreach(['enabled','automatic','emergency'] as $k)if(!in_array($p[$k]??null,['0','1'],true))throw new ControlledTestException('Choose explicit ON/OFF controls.');
        $old=self::status();$limit=self::integer($p['limit']??'',1,1000);$batch=self::integer($p['batch']??'',1,1);$interval=self::integer($p['interval']??'',1,300);
        $pilot=(int)$old['pilot'];if(($p['pilot_confirm']??'')==='RECEIPT CONFIRMED'){
            $id=self::integer($p['pilot_run']??'',1,PHP_INT_MAX);$run=$s->row('SELECT * FROM '.$s->table('bulk_runs').' WHERE id=%d',$id);$plan=$run?$s->row('SELECT * FROM '.$s->table('sendplans').' WHERE id=%d',$run['plan_id']):null;
            $accepted=$plan?$s->row('SELECT COUNT(*) n FROM '.$s->table('send_items')." WHERE plan_id=%d AND state='accepted'",$plan['id']):null;
            if(!$plan||$run['mode']!=='live'||$run['state']!=='complete'||(int)$plan['recipient_count']>(M91Campaign::modern()?1:3)||(int)($accepted['n']??0)!==(int)$plan['recipient_count'])throw new ControlledTestException('A completed limited Live pilot with accepted provider evidence is required.');$pilot=$id;
        }
        if(($limit>(M91Campaign::modern()?1:3)||$p['automatic']==='1')&&!$pilot)throw new ControlledTestException('Complete a manual pilot of the permitted initial size and confirm actual receipt before larger or automatic Live execution.');
        if($p['enabled']==='1'&&($p['ack']??'')!=='ENABLE CONTROLLED LIVE')throw new ControlledTestException('Explicit controlled Live activation acknowledgement required.');
        $budget=M8Costs::numeric($p['budget_units']??$old['budget_units']);if(M91Campaign::modern()&&$p['enabled']==='1'&&$p['emergency']!=='1'&&($budget===null||(float)$budget<=0||(float)$budget>1000000))throw new ControlledTestException('Set a positive conservative provider-unit ceiling in Settings.');
        $v=['budget_units'=>$budget,'enabled'=>$p['enabled']==='1','automatic'=>$p['automatic']==='1','emergency'=>$p['emergency']==='1','limit'=>$limit,'batch'=>$batch,'interval'=>$interval,'pilot'=>$pilot,'revision'=>bin2hex(random_bytes(16))];
        update_option(M11Environment::option(self::OPTION),$s->crypto->seal($v),false);$saved=self::status();if($saved['revision']!==$v['revision'])throw new ControlledTestException('Bulk policy save failed.');$s->audit(0,'bulk_policy_saved',['enabled'=>$v['enabled'],'automatic'=>$v['automatic'],'emergency'=>$v['emergency'],'limit'=>$limit]);return $saved;
    }
    public static function integer($value,int $min,int $max):int{if(!is_string($value)||!preg_match('/^[0-9]{1,15}$/D',$value)||(int)$value<$min||(int)$value>$max)throw new ControlledTestException('Invalid bounded numeric setting.');return (int)$value;}
}
