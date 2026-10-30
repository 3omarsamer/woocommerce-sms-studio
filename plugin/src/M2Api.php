<?php
namespace Azula\SmsStudio;

final class M2Api
{
    public static function authorize(string $nonce): void
    {
        // Validate the same predicates; disclose deployment reasons only after session/role checks.
        if(!wp_verify_nonce($nonce,'azula_sms_m2'))throw new AdminRequestError('sms_nonce_invalid');
        if(!current_user_can('manage_options') || !current_user_can('manage_azula_sms_campaigns') || !current_user_can('manage_woocommerce') || !current_user_can('edit_shop_orders'))throw new AdminRequestError('sms_permissions_required');
        if(!M11Environment::available()){
            if(!M11Environment::modern() || !function_exists('wp_get_environment_type') || wp_get_environment_type()!=='production')throw new AdminRequestError('sms_environment_unsupported');
            if(!M11Environment::production())throw new AdminRequestError('sms_production_home_mismatch');
            throw new AdminRequestError('sms_production_optin_required');
        }
    }
    public static function ajax(): void
    {
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');
        global $wpdb;$previous=$wpdb->suppress_errors(true);
        try{
            if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new \RuntimeException('POST required.');
            $p=wp_unslash($_POST);$nonce=$p['nonce']??null;if(!is_string($nonce))throw new \RuntimeException('Invalid request.');self::authorize($nonce);
            if(($p['op']??'')==='upgrade'){
                if(!current_user_can('manage_azula_sms_settings') || !current_user_can('activate_plugins'))throw new \RuntimeException('Schema permission required.');
                Schema::migrate(new Database($wpdb));$schema=Schema::status(new Database($wpdb));
                if(!$schema['ready'])throw new \RuntimeException('Schema verification failed.');
                try{M2Scheduler::ensure();}catch(\Throwable $runnerUnavailable){}$result=['upgraded'=>true,'needs_upgrade'=>false,'schema'=>$schema];
            }else $result=self::handle($p);
            $wpdb->suppress_errors($previous);wp_send_json_success($result);
        }catch(\Throwable $e){
            $error=AdminRequestError::response($e);
            if(($_POST['op']??'')==='upgrade'){
                try{self::authorize(is_string($_POST['nonce']??null)?$_POST['nonce']:'');$error['schema']=Schema::status(new Database($wpdb));$error['code']='sms_upgrade_failed';$error['message']='SMS schema upgrade did not complete. Existing data were not reset. Review schema status and retry after checking database permissions and SMS table health.';}catch(\Throwable $denied){}
            }
            $wpdb->suppress_errors($previous);wp_send_json_error($error,400);
        }
    }
    private static function integer(array $p,string $key,int $min=1): int
    {
        $v=$p[$key]??null;if(!is_string($v) || !preg_match('/^[0-9]{1,10}$/D',$v) || (int)$v<$min)throw new \InvalidArgumentException('Invalid identifier.');return (int)$v;
    }
    public static function handle(array $p): array
    {
        global $wpdb;
        $op=$p['op']??'';
        if(!is_string($op))throw new \InvalidArgumentException('Invalid operation.');
        if(str_starts_with($op,'provider_')){
            SmsMisrSettings::authorize(is_string($p['nonce']??null)?$p['nonce']:'');
            $settings=new SmsMisrSettings(Crypto::deployment(),$wpdb);
            if($op==='provider_get')return ['provider'=>$settings->status()];
            if($op==='provider_save')return ['provider'=>$settings->saveCredentials($p)];
            if($op==='provider_probe')return ['provider'=>$settings->probe()];
            if($op==='provider_diagnostic')return ['balance_diagnostic'=>$settings->diagnostic()];
            if($op==='provider_queue_preview')return ['provider_preview'=>(new HeldQueueProviderBridge(new Database($wpdb),Crypto::deployment()))->preview(self::integer($p,'campaign'),new CustomerSegmentsAdapterV1($wpdb))];
            throw new \InvalidArgumentException('Unknown provider operation.');
        }
        $schema=Schema::status(new Database($wpdb));
        if($op==='schema_status')return ['needs_upgrade'=>!$schema['ready'],'schema'=>$schema,'health'=>M2Scheduler::health()];
        if(!Schema::ready() || ($schema && !$schema['ready'])){
            $result=['needs_upgrade'=>true,'schema'=>$schema??Schema::status(new Database($wpdb)),'health'=>M2Scheduler::health()];
            // The original segment catalog/count service does not depend on SMS tables or its key.
            // Keep campaign/history reads and all mutations behind the migration gate.
            if(in_array($op,['init','segments'],true)){
                try{$source=new CustomerSegmentsAdapterV1($wpdb);$result['segments']=$source->catalog($op==='init'?0:self::integer($p,'after',0));}
                catch(\Throwable $e){$result['segments']=[];$result['catalog_error']='source_catalog_unavailable';}
                $result+=['historical'=>[],'campaigns'=>[],'rate_minor'=>null,'coverage'=>CustomerSegmentsAdapterV1::COVERAGE];
            }
            return $result;
        }
        if(str_starts_with($op,'m9_'))return M9Api::handle($p);
        if(str_starts_with($op,'flow_'))return CampaignFlow::handle($p);
        if(str_starts_with($op,'m6_'))return M6Api::handle($p);
        if(str_starts_with($op,'m5_'))return M5Api::handle($p);
        $repo=M2Scheduler::repository();$source=M9Source::wrap(new CustomerSegmentsAdapterV1($wpdb),new M6Store(new Database($wpdb),Crypto::deployment()));
        if($op==='init'){$segments=$source->catalog(0);return ['segments'=>$segments,'historical'=>$repo->histories($segments),'campaigns'=>$repo->recent(),'health'=>M2Scheduler::health(),'rate_minor'=>M2Repository::rate(),'coverage'=>CustomerSegmentsAdapterV1::COVERAGE,'needs_upgrade'=>false,'schema'=>$schema];}
        if($op==='segments'){$segments=$source->catalog(self::integer($p,'after',0));return ['segments'=>$segments,'historical'=>$repo->histories($segments)];}
        if($op==='history')return ['history'=>$repo->history(self::integer($p,'segment'))];
        if($op==='schedule_choices'){
            if(!is_string($p['local']??null))throw new \InvalidArgumentException('Invalid Egypt time.');
            return ['choices'=>CairoTime::choices($p['local']),'timezone'=>CairoTime::ZONE];
        }
        if(in_array($op,['schedule','schedule_pause','schedule_resume','schedule_cancel','schedule_approve','schedule_refresh'],true)){
            $id=self::integer($p,'campaign');$m3=M3Scheduler::repository();
            $result=$repo->lock(-$id,function()use($m3,$repo,$id,$op,$p,$source):bool{
                if($op==='schedule'){
                    if(!is_string($p['local']??null) || !is_string($p['offset']??null))throw new \InvalidArgumentException('Invalid Egypt time.');
                    $m3->plan($id,self::integer($p,'revision'),$p['local'],$p['offset'],time());
                }elseif($op==='schedule_approve'){
                    if(!current_user_can('approve_azula_sms_campaigns') || ($p['confirm']??'')!=='prepare_queue_only')throw new \DomainException('Explicit queue approval required.');
                    $m3->approve($id,self::integer($p,'revision'),self::integer($p,'version'),get_current_user_id(),time(),$source);
                }elseif($op==='schedule_refresh'){
                    $s=$m3->get($id);if(!$s || (int)$s['version']!==self::integer($p,'version') || (int)$s['start_at']<=time() || (int)$s['start_at']-time()>3600 || in_array($s['state'],['paused','canceled'],true))throw new \DomainException('Final refresh is available within one hour of a future schedule.');
                    $m3->invalidate($id,'explicit_final_refresh');$m3->update($id,['state'=>'planned','version'=>(int)$s['version']+1,'final_audience_id'=>null,'refresh_started'=>0,'next_check'=>time(),'retry_count'=>0]);
                }else $m3->control($id,self::integer($p,'version'),substr($op,9),time());
                return true;
            });
            if(!$result)throw new \RuntimeException('Campaign busy.');
            try{M3Scheduler::enqueue($m3->get($id));M2Scheduler::requestProbe();}catch(\Throwable $e){}
            return self::status($repo,$id);
        }
        if($op==='select'){
            $segment=$source->segment(self::integer($p,'segment'));$id=isset($p['campaign'])?self::integer($p,'campaign',0):0;
            if(!$id)$id=$repo->draft(is_string($p['draft_token']??null)?$p['draft_token']:'',(int)$segment['id'],'Campaign · '.$segment['name']);
            $existing=$repo->store()->campaign($id); if((int)$existing['segment_id']===(int)$segment['id'] && $existing['audience_id'] && ($p['fresh']??'')!=='1')return self::status($repo,$id);
            $fresh=($p['fresh']??'')==='1';
            $a=$repo->lock(-$id,function()use($repo,$source,$segment,$id,$wpdb,$fresh):array{
                $current=$repo->store()->campaign($id);
                if(!$fresh && (int)$current['segment_id']===(int)$segment['id'] && $current['audience_id'])return $repo->store()->audience((int)$current['audience_id']);
                M3Scheduler::repository()->invalidate($id,'audience_or_segment_changed');$repo->supersede($id);
                $wpdb->update((new Database($wpdb))->table('campaigns'),['segment_id'=>(int)$segment['id']],['id'=>$id]);(new Database($wpdb))->check(true);
                $svc=new AudiencePreparationService($source,$repo->store(),Crypto::deployment());$a=$svc->begin($id,time());
                $repo->updateAudience((int)$a['id'],['normalizer_version'=>EgyptMobileNormalizer::VERSION,'review_version'=>1,'last_progress_at'=>time()]);
                return $repo->store()->audience((int)$a['id']);
            });
            if(!$a)throw new \RuntimeException('Campaign is busy.');
            try{M2Scheduler::enqueue($a);M2Scheduler::ensure();M2Scheduler::requestProbe();}catch(\Throwable $e){/* Persisted generation remains visibly waiting for scheduler recovery. */}
            return self::status($repo,$id);
        }
        if($op==='probe'){M2Scheduler::requestProbe();return ['health'=>M2Scheduler::health()];}
        if($op==='pricing'){
            if(!current_user_can('manage_azula_sms_settings'))throw new \RuntimeException('Settings permission required.');
            $rate=$p['rate_minor']??null;if(!is_string($rate) || ($rate!=='' && !preg_match('/^[0-9]{1,7}$/D',$rate)) || ($rate!=='' && (int)$rate>1000000))throw new \InvalidArgumentException('Invalid rate.');
            update_option('azula_sms_rate_minor',$rate===''?null:(int)$rate,false);
            $db=new Database($wpdb);
            $db->check($wpdb->query('UPDATE '.$db->table('schedules')." SET state='needs_review',approval_hash=NULL,approval_payload=NULL,next_check=0,error_code='pricing_changed' WHERE approval_hash IS NOT NULL"));
            $db->check($wpdb->query('UPDATE '.$db->table('queue')." SET state='canceled' WHERE state='held_disabled'"));
            $db->check($wpdb->query('UPDATE '.$db->table('campaigns')." SET approval_hash=NULL WHERE approval_hash IS NOT NULL"));
            // Pricing changes invalidate saved review hashes; customer data is untouched.
            $wpdb->query('UPDATE '.(new Database($wpdb))->table('campaigns')." SET review_hash=NULL,state='review_ready' WHERE state='pending_review'");(new Database($wpdb))->check(true);
            return ['rate_minor'=>M2Repository::rate()];
        }
        $id=self::integer($p,'campaign');
        if($op==='status'){
            try{M2Scheduler::ensure();M2Scheduler::recover($repo);}catch(\Throwable $e){}
            return self::status($repo,$id);
        }
        if($op==='message'){
            $message=$p['message']??null;if(!is_string($message))throw new \InvalidArgumentException('Invalid message.');
            $repo->saveMessage($id,$message,self::integer($p,'revision'));return self::status($repo,$id);
        }
        if($op==='review'){$hash=$repo->saveReview($id,self::integer($p,'revision'),time());return ['review_hash'=>$hash]+self::status($repo,$id);}
        if($op==='details')return isset($p['category'])?$repo->review($id,self::integer($p,'audience'),$p):$repo->details($id,self::integer($p,'audience'),is_string($p['tab']??null)?$p['tab']:'',self::integer($p,'after',0));
        throw new \InvalidArgumentException('Unsupported operation.');
    }
    public static function status(M2Repository $repo,int $id): array
    {
        $c=$repo->store()->campaign($id);$a=$c['audience_id']?$repo->store()->audience((int)$c['audience_id']):null;$message=$repo->message($c);
        $counts=$a?json_decode($a['counts_json'],true):AudiencePreparationService::COUNTS;
        return ['campaign'=>['id'=>(int)$c['id'],'name'=>$c['name'],'segment_id'=>(int)$c['segment_id'],'revision'=>(int)$c['revision'],'state'=>$c['state'],'cancelled'=>CampaignLifecycle::cancelled($repo->scheduling()->database,$c),'display_status'=>$repo->displayStatus($id),'review_hash'=>$c['review_hash']??null,'transmission_intent'=>(bool)$c['transmission_intent']],
            'audience'=>$a?['id'=>(int)$a['id'],'generation'=>(int)$a['generation'],'state'=>$a['state'],'counts'=>$counts,'started'=>(int)$a['started_at'],'completed'=>(int)($a['completed_at']??0),'last_progress'=>(int)$a['last_progress_at'],'retries'=>(int)$a['retry_count'],'next_attempt'=>(int)$a['next_attempt_at'],'evidence_available'=>(int)$a['review_version']===1,'stale'=>$a['state']==='complete' && (time()-(int)$a['completed_at']>ApprovalPolicy::FRESH_SECONDS || (int)$a['normalizer_version']!==EgyptMobileNormalizer::VERSION),'manifest'=>$a['manifest_hash']]:null,
            'message'=>$message,'metrics'=>MessageMetrics::calculate($message,(int)$counts['prepared'],M2Repository::rate()),'health'=>M2Scheduler::health(),'rate_minor'=>M2Repository::rate(),'sending_enabled'=>false,'schedule'=>M3Scheduler::repository()->status($id)];
    }
}
