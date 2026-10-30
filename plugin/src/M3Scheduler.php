<?php
namespace Azula\SmsStudio;

final class M3Scheduler
{
    public const GROUP='azula-sms-scheduling';
    public static function repository(): M3Repository { if(!M11Environment::available() || !Schema::ready())throw new \RuntimeException('STAGING scheduling unavailable.');global $wpdb;return new M3Repository(new Database($wpdb),Crypto::deployment()); }
    public static function register(): void { add_action('azula_sms_schedule_tick',[self::class,'run'],10,6);add_action('action_scheduler_init',[M2Scheduler::class,'ensure']); }
    public static function checkpoint(array $s): array { return [(int)$s['campaign_id'],(int)$s['next_check'],(int)$s['version'],(int)$s['queue_cursor'],(int)$s['verify_cursor'],$s['state']]; }
    public static function enqueue(array $s): void
    {
        if(!M2Scheduler::available())throw new \RuntimeException('Background runner unavailable.');
        if((int)$s['next_check']<=0)return;$args=self::checkpoint($s);
        if(!as_has_scheduled_action('azula_sms_schedule_tick',$args,self::GROUP)){
            $job=as_schedule_single_action(max(time(),(int)$s['next_check']),'azula_sms_schedule_tick',$args,self::GROUP,false);
            if(!$job && !as_has_scheduled_action('azula_sms_schedule_tick',$args,self::GROUP))throw new \RuntimeException('Schedule job unavailable.');
        }
        M2Scheduler::ensure();
    }
    public static function recover(): void { $repo=self::repository();foreach($repo->due(time()) as $s)self::enqueue($s); }
    public static function execute(M3Repository $repo,int $id,AudienceSource $source,?int $clock=null,?array $checkpoint=null): void
    {
        $repo->m2->lock(-$id,function()use($repo,$id,$source,$clock,$checkpoint):void{
            $now=$clock??time();$before=$repo->get($id);if(!$before)return;
            if($checkpoint!==null && self::checkpoint($before)!==$checkpoint)return;
            try{$repo->tick($id,$now,$source);update_option(M11Environment::option('azula_sms_schedule_seen'),$now,false);}
            catch(\Throwable $e){
                $repo->database->transaction(function()use($repo,$id,$before,$e,$now):void{
                    $repo->campaignLock($id);$s=$repo->get($id,true);if(!$s || (int)$s['version']!==(int)$before['version'] || in_array($s['state'],['paused','canceled','needs_review','missed','ready_disabled'],true))return;
                    $retry=(int)$s['retry_count']+1;
                    if($e instanceof \DomainException || $e instanceof \LogicException || $retry>3){$repo->invalidate($id,'schedule_processing_blocked');return;}
                    $repo->update($id,['retry_count'=>$retry,'error_code'=>'schedule_retry_pending','next_check'=>$now+[1=>15,2=>45,3=>120][$retry]]);
                });
            }
            $next=$repo->get($id);if($next && (int)$next['next_check']>0)self::enqueue($next);
        });
    }
    public static function run(int $id,int $due,int $version,int $queueCursor,int $verifyCursor,string $state): void
    {
        if(!M11Environment::available() || !Schema::ready())return;
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        try{self::execute(self::repository(),$id,M9Source::wrap(new CustomerSegmentsAdapterV1($wpdb),new M6Store(new Database($wpdb),Crypto::deployment())),null,[$id,$due,$version,$queueCursor,$verifyCursor,$state]);}catch(\Throwable $e){}finally{$wpdb->suppress_errors($previous);}
    }
}
