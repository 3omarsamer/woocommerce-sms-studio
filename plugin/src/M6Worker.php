<?php
namespace Azula\SmsStudio;

final class M6Worker
{
    public const HOOK='azula_sms_normal_campaign_tick';
    public const GROUP='azula-sms-normal-campaigns';
    public static function store():M6Store{if(!M11Environment::available()||!Schema::ready())throw new \RuntimeException('STAGING SMS schema unavailable.');global $wpdb;return new M6Store(new Database($wpdb),Crypto::deployment());}
    public static function register():void{add_action(self::HOOK,[self::class,'run'],10,2);add_action('action_scheduler_init',[self::class,'recover'],25);add_action('azula_sms_prepare_watchdog',[self::class,'recover'],25);}
    public static function enqueue(array $plan,bool $successor=false):void
    {
        if(!$plan['next_check']||!M2Scheduler::available())return;$args=[(int)$plan['id'],$plan['approval_hash']];if(!$successor&&as_has_scheduled_action(self::HOOK,$args,self::GROUP))return;as_schedule_single_action(max(time(),(int)$plan['next_check']),self::HOOK,$args,self::GROUP,false);
    }
    public static function recover():void
    {
        if(!M11Environment::available()||!Schema::ready())return;
        try{$s=self::store();foreach($s->rows('SELECT * FROM '.$s->table('sendplans')." WHERE next_check>0 AND next_check<=%d AND state IN ('preparing_queue','authorized_test') ORDER BY next_check ASC LIMIT 20",time()+60) as $p)self::enqueue($p);}catch(\Throwable $e){}
    }
    public static function run(int $id,string $approval):void
    {
        if(!M11Environment::available()||!Schema::ready())return;
        try{$s=self::store();self::execute($s,M9Source::wrap(new CustomerSegmentsAdapterV1($s->database->db),$s),$id,$approval);}catch(\Throwable $e){} // Never log source or provider exception text.
    }
    public static function execute(M6Store $s,AudienceSource $source,int $id,string $approval):void
    {
        $p=$s->row('SELECT * FROM '.$s->table('sendplans').' WHERE id=%d',$id);if(!$p||!hash_equals($p['approval_hash'],$approval)||(int)$p['next_check']>time())return;
        $service=new M6SendService($s,$source);
        try{if($p['state']==='preparing_queue')$service->prepareQueue($id);elseif($p['state']==='authorized_test'&&(int)$p['environment']===2&&$p['released_at']&&$p['auto_test'])$service->submit($id,true);$s->update('sendplans',['retry_count'=>0],['id'=>$id]);update_option(M11Environment::option('azula_sms_m6_worker_seen'),time(),false);}
        catch(\Throwable $e){
            $claimed=$s->row('SELECT * FROM '.$s->table('attempts')." WHERE plan_id=%d AND state='unknown' LIMIT 1",$id);$current=$s->row('SELECT * FROM '.$s->table('sendplans').' WHERE id=%d',$id);$retry=(int)$current['retry_count']+1;
            // Only pre-claim queue work or an explicitly released Test checkpoint can retry.
            // A committed unknown/submitting claim is never replayed, even on transport/storage failure.
            $safe=!$claimed&&in_array($current['state'],['preparing_queue','authorized_test'],true)&&$retry<=3&&time()+[1=>15,2=>45,3=>120][$retry]<(int)$current['expires_at'];
            $s->update('sendplans',['state'=>$claimed?'unknown':($safe?$current['state']:'blocked'),'retry_count'=>$retry,'next_check'=>$safe?time()+[1=>15,2=>45,3=>120][$retry]:0,'updated_at'=>time()],['id'=>$id]);$s->audit((int)$p['campaign_id'],$safe?'submission_worker_retry':'submission_worker_blocked',['plan_id'=>$id,'result'=>$claimed?'unknown_outcome_no_retry':($safe?'preclaim_retry_pending':'validation_or_worker_blocked')]);
        }
        // The current action still counts as running. Explicitly schedule its successor.
        $fresh=$s->row('SELECT * FROM '.$s->table('sendplans').' WHERE id=%d',$id);if($fresh)self::enqueue($fresh,true);
    }
}
