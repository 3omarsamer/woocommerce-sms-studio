<?php
namespace Azula\SmsStudio;

final class M6Api
{
    private static function id(array $p,string $key,int $min=1):int{$v=$p[$key]??null;if(!is_string($v)||!preg_match('/^[0-9]{1,10}$/D',$v)||(int)$v<$min)throw new ControlledTestException('Choose a valid saved record.');return (int)$v;}
    public static function handle(array $p):array
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new ControlledTestException('Use the protected administrator workspace.');$s=M6Worker::store();Schema::verify($s->database);try{$source=M9Source::wrap(new CustomerSegmentsAdapterV1($s->database->db),$s);}catch(\Throwable $e){$source=null;}return self::execute($s,$source,$p);
    }
    /** Dependency-injected path used by synthetic SQL/API tests; production handle always uses the pinned adapter. */
    public static function execute(M6Store $s,?AudienceSource $source,array $p):array
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new ControlledTestException('Administrator POST required.');$op=$p['op']??'';if(defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'0.6.1','>=')&&in_array($op,['m6_approve','m6_confirm'],true))throw new ControlledTestException('Use the campaign final confirmation dialog.');
        if(!$source&&!in_array($op,['m6_campaigns','m6_activity','m6_analytics','m6_health','m6_send_status','m6_campaign_state'],true))throw new ControlledTestException('Customer Segments compatibility is unavailable. Historical SMS evidence remains readable; preparation and sending require the verified source.');
        if($op==='m6_campaigns')return (new M6Campaigns($s,$source))->listing(max(1,(int)($p['page']??1)),($p['trash']??'')==='1');
        if($op==='m6_audience')return (new M6Audience($s,$source))->snapshot(self::id($p,'segment'),isset($p['campaign'])?self::id($p,'campaign',0):0);
        if($op==='m6_audience_prepare')return (new M6Audience($s,$source))->prepare(self::id($p,'segment'),is_string($p['draft_token']??null)?$p['draft_token']:'');
        if($op==='m6_analytics'){if(!current_user_can('view_azula_sms_reports'))throw new ControlledTestException('SMS reporting permission required.');return (new M6Analytics($s,$source))->report($p);}
        if($op==='m6_health')return ['worker_seen'=>(int)get_option(M11Environment::option('azula_sms_m6_worker_seen'),0),'test_enabled'=>M6SendPolicy::enabled(2),'live_one_enabled'=>M6SendPolicy::enabled(1),'bulk_live'=>false,'automatic_live'=>false,'tracking_enabled'=>M6Tracking::enabled(),'attribution_contract'=>M6AttributionContracts::available(),'finance_contract'=>M6AttributionContracts::exclusions()!==null,'runtime_verified'=>false];
        $id=self::id($p,'campaign');
        if($op==='m6_action')return (new M6Campaigns($s,$source))->action($id,is_string($p['action_kind']??null)?$p['action_kind']:'',is_string($p['name']??null)?$p['name']:'');
        if($op==='m6_campaign_state')return M2Api::status($s->campaigns->m2,$id);
        if($op==='m6_activity')return (new M6Activity($s,$source))->read($id,isset($p['after'])?self::id($p,'after',0):0);
        if($op==='m6_message'){$s->campaign($id);$message=is_string($p['message']??null)?$p['message']:throw new ControlledTestException('Enter a valid message.');$final=(new M6Links($s))->save($id,self::id($p,'revision'),$message,($p['tracking']??'')==='1');return M2Api::status($s->campaigns->m2,$id)+['tracking'=>$final['links']];}
        if($op==='m6_timing'){(new M6Timing($s))->save($id,self::id($p,'revision'),is_string($p['mode']??null)?$p['mode']:'',is_string($p['local']??null)?$p['local']:'',is_string($p['offset']??null)?$p['offset']:'');$c=$s->campaigns->m2->store()->campaign($id);if($c['audience_id']){try{$s->campaigns->m2->saveReview($id,(int)$c['revision'],time());}catch(\DomainException $e){}}$schedule=$s->campaigns->get($id);if($schedule)try{M3Scheduler::enqueue($schedule);}catch(\Throwable $e){}return M2Api::status($s->campaigns->m2,$id);}
        $sending=new M6SendService($s,$source);
        if($op==='m6_send_status')return $sending->status($id);
        if($op==='m6_approve'){$r=$sending->approve($p);$plan=$s->row('SELECT * FROM '.$s->table('sendplans').' WHERE id=%d',$r['plan_id']);M6Worker::enqueue($plan);return $r+['sending'=>$sending->status($id)];}
        if($op==='m6_confirm'){$r=$sending->confirm($p);$plan=$s->latestPlan($id);if($plan)M6Worker::enqueue($plan);return $r;}
        if($op==='m6_ready_destination'){$c=$s->campaign($id);$a=$c['audience_id']?$s->campaigns->m2->store()->audience((int)$c['audience_id']):null;if(!$a||$a['state']!=='complete')return ['destination'=>null,'count'=>0];$count=(int)json_decode($a['counts_json'],true)['prepared'];if($count!==1)return ['destination'=>null,'count'=>$count];$r=$s->row('SELECT * FROM '.$s->table('recipients').' WHERE audience_id=%d LIMIT 2',$a['id']);$payload=$r?$s->crypto->open($r['payload']):null;if(!$payload||!hash_equals($r['payload_hash'],$s->crypto->digest($payload)))throw new ControlledTestException('Recipient evidence is unavailable.');return ['destination'=>['id'=>(int)$r['id'],'phone'=>$payload['phone']],'count'=>1];}
        throw new ControlledTestException('Unsupported workspace action.');
    }
}
