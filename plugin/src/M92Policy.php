<?php
namespace Azula\SmsStudio;
/** Owner controls discover genuine one-destination pilot evidence; receipt is still human-attested. */
final class M92Policy
{
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'0.9.2','>=');}
    public static function candidate(M6Store $s,?int $planId=null):?array
    {
        $where=$planId===null?'':' AND p.id='.(int)$planId;
        $rows=$s->rows('SELECT p.id,p.campaign_id,p.recipient_count,p.state,p.environment,r.id run_id,r.state run_state,c.name FROM '.$s->table('sendplans').' p LEFT JOIN '.$s->table('bulk_runs').' r ON r.plan_id=p.id INNER JOIN '.$s->table('campaigns')." c ON c.id=p.campaign_id WHERE p.environment=1 AND p.recipient_count=1 AND p.released_at>0$where ORDER BY p.id DESC LIMIT 100");
        foreach($rows as $p){if(M11Environment::modern()&&M11Environment::production()){if(!$p['run_id'])continue;$run=$s->row('SELECT payload FROM '.$s->table('bulk_runs').' WHERE id=%d',$p['run_id']);try{$bound=$run?$s->crypto->open($run['payload']):[];}catch(\Throwable $e){continue;}if(($bound['deployment']??'')!=='https://azulasportswear.com')continue;}if($p['run_id']!==null?$p['run_state']!=='complete':$p['state']!=='submitted')continue;
            $attempts=$s->rows('SELECT id,state,provider_code,smsid,http_status,finished_at,recipient_count,environment FROM '.$s->table('attempts').' WHERE plan_id=%d ORDER BY id LIMIT 3',$p['id']);
            if(count($attempts)!==1)continue;$a=$attempts[0];if($a['state']!=='accepted'||$a['provider_code']!=='1901'||!is_string($a['smsid'])||$a['smsid']===''||(int)$a['http_status']!==200||(int)$a['finished_at']<1||(int)$a['recipient_count']!==1||(int)$a['environment']!==1)continue;
            return ['plan'=>(int)$p['id'],'run'=>(int)($p['run_id']??0),'attempt'=>(int)$a['id'],'finished'=>(int)$a['finished_at'],'campaign'=>$p['name'],'evidence_hash'=>$s->crypto->digest(['plan'=>$p['id'],'attempt'=>$a])];
        }return null;
    }
    public static function review(M6Store $s):array
    {
        $v=self::candidate($s);if(!$v)return ['available'=>false,'key'=>'','label'=>'No completed one-recipient Live pilot with an accepted provider receipt was found.','ticket'=>''];
        return ['available'=>true,'key'=>$v['evidence_hash'],'label'=>$v['campaign'].' · '.CairoTime::label($v['finished']).' · one SMS accepted; phone receipt requires your confirmation.','ticket'=>$s->crypto->seal(['kind'=>'m92_pilot','actor'=>get_current_user_id(),'plan'=>$v['plan'],'hash'=>$v['evidence_hash'],'expires'=>time()+900])];
    }
    private static function integer(array $p,string $key,int $default,int $min,int $max):int
    {
        try{return M9Policy::integer($p[$key]??(string)$default,$min,$max);}catch(ControlledTestException $e){throw new ControlledTestException(['limit'=>'Maximum recipients must be a whole number from 1 to 1000.','interval'=>'Seconds between requests must be a whole number from 1 to 300.','batch'=>'Internal request batch must remain one.'][$key]??'Invalid numeric setting.');}
    }
    public static function save(M6Store $s,array $p):array
    {
        M6SendPolicy::authorize($p);if(!M11Environment::available())throw new ControlledTestException('Execution policy is restricted to an explicitly enabled Azula environment.');
        foreach(['enabled','automatic','emergency'] as $k)if(!in_array($p[$k]??null,['0','1'],true))throw new ControlledTestException('Choose an explicit ON/OFF value for '.$k.'.');
        $lock='azula_sms_policy_'.substr(hash('sha256',$s->database->db->prefix),0,32);$db=$s->database->db;if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new ControlledTestException('Settings are being saved by another administrator. Reload and retry.');
        try{$old=M9Policy::status();if(isset($p['policy_revision'])&&!hash_equals($old['revision'],(string)$p['policy_revision']))throw new ControlledTestException('Settings changed in another window. Reload Settings & Health before saving.');
            $limit=self::integer($p,'limit',(int)$old['limit'],1,1000);$interval=self::integer($p,'interval',(int)$old['interval'],1,300);self::integer($p,'batch',1,1,1);
            $pilot=(int)$old['pilot'];$proof=$old['pilot_proof']??null;
            if(($p['pilot_confirm']??'')==='RECEIPT CONFIRMED'){
                if(!is_string($p['pilot_ticket']??null)||$p['pilot_ticket']==='')throw new ControlledTestException('Receipt confirmation needs the verified pilot shown in Settings. Refresh Settings & Health; no pilot ID is required.');
                try{$t=$s->crypto->open($p['pilot_ticket']);}catch(\Throwable $e){throw new ControlledTestException('Pilot evidence could not be verified. Refresh Settings & Health.');}
                $v=self::candidate($s,(int)($t['plan']??0));if(!$v||($t['kind']??'')!=='m92_pilot'||($t['actor']??0)!==get_current_user_id()||($t['expires']??0)<time()||!hash_equals($t['hash']??'',$v['evidence_hash']))throw new ControlledTestException('Pilot evidence changed or expired. Refresh Settings & Health and confirm receipt of the displayed pilot.');
                $pilot=$v['run']?:$v['plan'];$prior=$proof;$proof=['plan'=>$v['plan'],'hash'=>$v['evidence_hash'],'confirmed_by'=>get_current_user_id(),'confirmed_at'=>time()];if(M10::modern()&&is_array($prior)&&($prior['plan']??0)===$v['plan']&&hash_equals($prior['hash']??'',$v['evidence_hash']))$proof=$prior;
            }
            if($proof){$v=self::candidate($s,(int)($proof['plan']??0));if(!$v||!hash_equals($proof['hash']??'',$v['evidence_hash'])){$pilot=0;$proof=null;}}
            // Prior explicitly attested M9 pilot remains valid if its durable evidence still exists.
            if($pilot&&!$proof){$run=$s->row('SELECT plan_id FROM '.$s->table('bulk_runs').' WHERE id=%d',$pilot);if(!$run||!self::candidate($s,(int)$run['plan_id']))$pilot=0;}
            if($p['enabled']==='1'&&$p['emergency']==='0'&&($limit>1||$p['automatic']==='1')&&!$pilot)throw new ControlledTestException('First complete a one-recipient Live pilot, then confirm its actual phone receipt using the pilot shown here. Scheduling and larger limits remain disabled until then.');
            if($p['enabled']==='1'&&($p['ack']??'')!=='ENABLE CONTROLLED LIVE')throw new ControlledTestException('Explicit controlled Live opt-in is required.');
            $budget=$old['budget_units'];if(isset($p['budget_units'])&&$p['budget_units']!==''){$budget=M8Costs::numeric($p['budget_units']);if($budget===null||(float)$budget<=0||(float)$budget>1000000)throw new ControlledTestException('Optional advanced provider-unit ceiling must be a positive number up to 1000000.');}
            $v=['enabled'=>$p['enabled']==='1','automatic'=>$p['automatic']==='1','emergency'=>$p['emergency']==='1','limit'=>$limit,'batch'=>1,'interval'=>$interval,'pilot'=>$pilot,'pilot_proof'=>$proof,'revision'=>bin2hex(random_bytes(16)),'budget_units'=>$budget];
            if(M10::modern()){ $same=true;foreach($v as $k=>$value)if($k!=='revision'&&($old[$k]??null)!==$value)$same=false;if($same)return $old;}
            update_option(M11Environment::option(M9Policy::OPTION),$s->crypto->seal($v),false);$saved=M9Policy::status();if(!hash_equals($saved['revision'],$v['revision']))throw new ControlledTestException('Execution settings did not persist. No policy change was confirmed.');$s->audit(0,'bulk_policy_saved',['enabled'=>$v['enabled'],'automatic'=>$v['automatic'],'emergency'=>$v['emergency'],'limit'=>$limit]);return $saved;
        }finally{$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
