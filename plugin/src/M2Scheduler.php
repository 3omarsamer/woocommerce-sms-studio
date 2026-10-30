<?php
namespace Azula\SmsStudio;

/** Preparation/retention only. No sending hook or transport exists. */
final class M2Scheduler
{
    public const GROUP = 'azula-sms-preparation';
    public static function staging(): bool { return function_exists('wp_get_environment_type') && wp_get_environment_type()==='staging' && strtolower(rtrim((string)wp_parse_url(home_url('/'),PHP_URL_HOST),'.'))==='preview.azulasportswear.com'; }
    public static function available(): bool
    {
        if(class_exists('ActionScheduler') && method_exists('ActionScheduler','is_initialized') && !\ActionScheduler::is_initialized())return false;
        return function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action') && function_exists('as_enqueue_async_action');
    }
    public static function register(): void
    {
        add_action('azula_sms_prepare_batch',[self::class,'batch'],10,3);
        add_action('azula_sms_prepare_watchdog',[self::class,'watchdog']);
        add_action('azula_sms_prepare_probe',[self::class,'probe'],10,1);
    }
    public static function repository(): M2Repository
    {
        if(!M11Environment::available() || !Schema::ready())throw new \RuntimeException('Preparation requires the approved staging site and upgraded schema.');
        global $wpdb;return new M2Repository(new Database($wpdb),Crypto::deployment());
    }
    public static function enqueue(array $a): void
    {
        if(!self::available())throw new \RuntimeException('Action Scheduler is unavailable.');
        $args=[(int)$a['id'],(int)$a['cursor_id'],(int)($a['retry_count']??0)];
        if(!as_has_scheduled_action('azula_sms_prepare_batch',$args,self::GROUP)) {
            $when=max(time(),(int)($a['next_attempt_at']??0));
            // Exact-argument lookup avoids redundant jobs. Database locks/constraints,
            // rather than version-specific scheduler uniqueness semantics, prevent duplicate processing.
            $job=$when>time()?as_schedule_single_action($when,'azula_sms_prepare_batch',$args,self::GROUP,false):as_enqueue_async_action('azula_sms_prepare_batch',$args,self::GROUP,false);
            if(!$job && !as_has_scheduled_action('azula_sms_prepare_batch',$args,self::GROUP))throw new \RuntimeException('Preparation could not be queued.');
        }
    }
    public static function ensure(): void
    {
        if(!M11Environment::available() || !Schema::ready())return;
        if(!self::available())return;
        if(!as_has_scheduled_action('azula_sms_prepare_watchdog',[],self::GROUP))as_schedule_single_action(time()+60,'azula_sms_prepare_watchdog',[],self::GROUP,true);
    }
    public static function requestProbe(): void
    {
        if(!self::available())throw new \RuntimeException('Action Scheduler is unavailable.');
        $current=get_option(M11Environment::option('azula_sms_probe'),[]);
        // Preserve the token of a queued probe; AS uniqueness is hook/group-wide.
        if(AuthorizationBalance::modern()&&!empty($current['token'])&&as_has_scheduled_action('azula_sms_prepare_probe',[$current['token']],self::GROUP))return;
        if(!empty($current['requested']) && time()-(int)$current['requested']<120)return;
        $token=bin2hex(random_bytes(12));update_option(M11Environment::option('azula_sms_probe'),['token'=>$token,'requested'=>time(),'completed'=>0],false);
        $job=as_enqueue_async_action('azula_sms_prepare_probe',[$token],self::GROUP,!AuthorizationBalance::modern());
        if(AuthorizationBalance::modern()&&!$job&&!as_has_scheduled_action('azula_sms_prepare_probe',[$token],self::GROUP))throw new \RuntimeException('Background verification probe could not be queued.');
        self::ensure();
    }
    public static function probe(string $token): void
    {
        if(!M11Environment::available())return;
        $p=get_option(M11Environment::option('azula_sms_probe'),[]);
        if(!empty($p['token']) && hash_equals($p['token'],$token)){$p['completed']=time();update_option(M11Environment::option('azula_sms_probe'),$p,false);}
    }
    public static function health(): array
    {
        $p=get_option(M11Environment::option('azula_sms_probe'),[]);$recent=max((int)get_option(M11Environment::option('azula_sms_worker_seen'),0),(int)get_option(M11Environment::option('azula_sms_schedule_seen'),0));
        return ['available'=>self::available(),'wp_cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'probe_requested'=>(int)($p['requested']??0),'probe_completed'=>(int)($p['completed']??0),'last_worker'=>$recent,
            'schedule_worker'=>(int)get_option(M11Environment::option('azula_sms_schedule_seen'),0),
            'timezone'=>CairoTime::ZONE,'verified_recently'=>M92Policy::modern()?M92Runner::status()['verified_recently']:(self::available() && max((int)($p['completed']??0),$recent)>time()-300 && max((int)($p['completed']??0),$recent)>0)];
    }
    public static function batch(int $id, int $cursor=0, int $attempt=0): void
    {
        if(!M11Environment::available() || !Schema::ready())return;
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        try {
            $repo=self::repository();
            self::execute($repo,$id,$cursor,$attempt,static fn()=>M9Source::wrap(new CustomerSegmentsAdapterV1($wpdb),new M6Store(new Database($wpdb),Crypto::deployment())));
        } catch(\Throwable $e) { /* Watchdog recovers queued work. Do not expose/log exception strings. */ }
        finally{$wpdb->suppress_errors($previous);}
    }
    public static function execute(M2Repository $repo,int $id,int $cursor,int $attempt,callable $sourceFactory,?int $clock=null): void
    {
        $repo->lock($id,function()use($repo,$id,$cursor,$attempt,$sourceFactory,$clock):void{
                $a=$repo->store()->audience($id);$c=$repo->store()->campaign((int)$a['campaign_id']);$now=$clock??time();
                $schedule=$repo->scheduling()->get((int)$a['campaign_id']);
                if($schedule && in_array($schedule['state'],['paused','canceled'],true))return;
                if($c['state']==='trashed' || $c['state']==='canceled' || $a['state']!=='preparing' || (int)$c['audience_id']!==$id || $c['approval_hash']!==null || $c['transmission_intent'])return;
                if($now-(int)$a['started_at']>ApprovalPolicy::CAPTURE_SECONDS || (int)$a['normalizer_version']!==EgyptMobileNormalizer::VERSION){$repo->store()->fail($id);return;}
                if((int)$a['cursor_id']!==$cursor || (int)$a['retry_count']!==$attempt)return;
                if((int)$a['next_attempt_at']>$now)return;
                try {
                    $svc=$repo->preparation($sourceFactory());
                    $next=$svc->step($id,$now,true);
                    $repo->updateAudience($id,['retry_count'=>0,'last_progress_at'=>time(),'next_attempt_at'=>0,'error_code'=>null]);
                    update_option(M11Environment::option('azula_sms_worker_seen'),time(),false);
                } catch(\Throwable $e) {
                    $schedule=$repo->scheduling()->get((int)$a['campaign_id']);
                    if($schedule && in_array($schedule['state'],['paused','canceled'],true))return;
                    // The service has rolled back. Never propagate source/customer error text to scheduler logs.
                    $cause=$e->getPrevious()??$e;$retry=(int)$a['retry_count']+1;
                    if($cause instanceof \DomainException || $cause instanceof \LogicException || $retry>3 || time()-(int)$a['started_at']>ApprovalPolicy::CAPTURE_SECONDS){$repo->store()->fail($id);return;}
                    $repo->updateAudience($id,['retry_count'=>$retry,'next_attempt_at'=>time()+[1=>15,2=>45,3=>120][$retry],'error_code'=>'retry_pending']);
                    $next=$repo->store()->audience($id);
                }
                if($next['state']==='preparing')self::enqueue($repo->store()->audience($id));
        });
    }
    public static function recover(M2Repository $repo): void
    {
        foreach($repo->active() as $a){
            $schedule=$repo->scheduling()->get((int)$a['campaign_id']);
            if($schedule && in_array($schedule['state'],['paused','canceled'],true))continue;
            if(time()-(int)$a['started_at']>ApprovalPolicy::CAPTURE_SECONDS)$repo->store()->fail((int)$a['id']);
            else self::enqueue($a);
        }
    }
    public static function watchdog(): void
    {
        if(!M11Environment::available() || !Schema::ready())return;
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        try{
            $repo=self::repository();self::recover($repo);
            M3Scheduler::recover();
            if(time()-(int)get_option('azula_sms_retention_seen',0)>3600){$repo->store()->purgeExpired(time());update_option('azula_sms_retention_seen',time(),false);}
        }catch(\Throwable $e){}finally{$wpdb->suppress_errors($previous);}
        // unique=false: this callback is still running when its successor is scheduled.
        as_schedule_single_action(time()+60,'azula_sms_prepare_watchdog',[],self::GROUP,false);
    }
    public static function deactivate(): void
    {
        if(function_exists('as_unschedule_all_actions'))foreach(['azula_sms_prepare_batch','azula_sms_prepare_probe','azula_sms_prepare_watchdog'] as $hook)as_unschedule_all_actions($hook,[],self::GROUP);
        if(function_exists('as_unschedule_all_actions'))as_unschedule_all_actions(M9Worker::HOOK,[],M9Worker::GROUP);
        if(function_exists('as_unschedule_all_actions'))as_unschedule_all_actions('azula_sms_schedule_tick',[],M3Scheduler::GROUP);
    }
}
