<?php
namespace Azula\SmsStudio;

/** The ONLY M5 SMS path: manual POST + owner switch + fixed first-test allowlist + durable claim. */
final class ControlledTestService
{
    public const CAMPAIGN=5;
    public const SEGMENT='test {azula}';
    public const FRESH=600;
    private M3Repository $repo;
    public function __construct(private Database $database,private Crypto $crypto,private AudienceSource $source){$this->repo=new M3Repository($database,$crypto);}
    public static function enabled():bool{return (!defined('AZULA_SMS_STUDIO_VERSION')||version_compare(AZULA_SMS_STUDIO_VERSION,'0.6.0','<'))&&M2Scheduler::staging()&&defined('AZULA_SMS_STUDIO_CONTROLLED_TEST_ENABLED')&&constant('AZULA_SMS_STUDIO_CONTROLLED_TEST_ENABLED')===true;}
    private function deny(string $message):never{throw new ControlledTestException($message);}
    private function authorize(array $p):void
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$this->deny('Controlled Test actions require a manual POST.');
        if(!self::enabled())$this->deny('The owner controlled-Test switch is off. No SMS can be submitted.');
        foreach(['manage_azula_sms_settings','approve_azula_sms_campaigns','dispatch_azula_sms_campaigns'] as $cap)if(!current_user_can($cap))$this->deny('Controlled Test approval and dispatch permissions are required.');
        if(($p['campaign']??'')!=='5')$this->deny('Only Campaign #5 is authorized for the first controlled Test.');
        if(!Schema::ready())$this->deny('Complete the SMS-owned schema upgrade before controlled testing.');
        Schema::verify($this->database);
    }
    private function t(string $name):string{return $this->database->table($name);}
    private function ok($value){return $this->database->check($value);}
    private function control(bool $lock=false):?array{$db=$this->database->db;$r=$this->ok($db->get_row($db->prepare('SELECT * FROM '.$this->t('controlled').' WHERE campaign_id=%d'.($lock?' FOR UPDATE':''),5),ARRAY_A));return $r?:null;}
    private function change(array $data):void{$this->ok($this->database->db->update($this->t('controlled'),$data,['campaign_id'=>5]));}
    private function attempts():array{$db=$this->database->db;return $this->ok($db->get_results($db->prepare('SELECT * FROM '.$this->t('attempts').' WHERE campaign_id=%d ORDER BY id DESC LIMIT 25',5),ARRAY_A));}
    private function settings():array
    {
        $s=new SmsMisrSettings($this->crypto,$this->database->db);$c=$s->config();$h=$s->status();
        if((int)$c['environment']!==2)$this->deny('Live submission is disabled. Select Test (2) before a new preflight.');
        if(!$h['configured']||!$h['balance_verified']||$h['connection_status']!=='verified'||time()-(int)$h['last_balance_check']>900)$this->deny('Manually verify the Test account Balance within 15 minutes before controlled approval.');
        if(!preg_match('/[1-9]/',(string)$h['balance']))$this->deny('The verified account has zero points. No controlled request was submitted.');
        return $c;
    }
    private function base(bool $sending=false):array
    {
        $c=$this->repo->m2->store()->campaign(5);$segment=$this->source->segment((int)$c['segment_id']);
        if($segment['name']!==self::SEGMENT)$this->deny('Campaign #5 must use the exact saved segment test {azula}.');
        if($c['transmission_intent'])$this->deny('This campaign has a recorded submission claim. Refresh activity; do not resend.');
        $attempts=$this->attempts();if(count($attempts)>=3)$this->deny('Three controlled attempts have been recorded. Further testing requires owner review.');
        if($attempts&&!in_array($attempts[0]['state'],['rejected','aborted'],true))$this->deny('A prior accepted or unknown attempt blocks another request. No automatic retry is permitted.');
        $s=$this->repo->get(5);
        if($s&&in_array($s['state'],['paused','canceled','missed'],true))$this->deny('The campaign schedule is paused, canceled or missed. Controlled submission is blocked.');
        if($sending&&$s&&(time()<(int)$s['start_at']||time()>(int)$s['start_at']+900))$this->deny('A scheduled controlled Test must be confirmed at its Egypt execution time, within 15 minutes.');
        return [$c,$segment,$s];
    }
    private function language(array $p):int{if(!in_array($p['language']??null,['1','2','3'],true))$this->deny('Choose the exact provider language for this controlled Test.');return (int)$p['language'];}
    private function validateMessage(string $message,int $language):void
    {
        $m=MessageMetrics::calculate($message,1);if(!$m['valid'])$this->deny('Save a valid message before controlled preparation.');
        if($language===1&&($m['encoding']!=='GSM-7'||$m['segments']!==1))$this->deny('The first English controlled Test is limited to one standard GSM-7 segment.');
        if($language!==1&&strlen(SmsMisrAdapter::unicodeHex($message))/4>70)$this->deny('The first Arabic/Unicode controlled Test is limited to 70 BMP characters. Provider billing remains subject to verification.');
    }
    public function prepare(array $p):array
    {
        $this->authorize($p);$language=$this->language($p);
        $result=$this->repo->m2->lock(-5,function()use($language):array{return $this->database->transaction(function()use($language):array{
            $this->repo->campaignLock(5);[$c,$segment]=$this->base();$config=$this->settings();$message=$this->repo->m2->message($c);$this->validateMessage($message,$language);
            $this->repo->invalidate(5,'controlled_test_refresh');$this->repo->m2->supersede(5);$now=time();$a=$this->repo->m2->preparation($this->source)->begin(5,$now);
            $this->repo->m2->updateAudience((int)$a['id'],['normalizer_version'=>EgyptMobileNormalizer::VERSION,'review_version'=>1,'last_progress_at'=>$now]);$c=$this->repo->m2->store()->campaign(5);$s=$this->repo->get(5);
            $pre=['revision'=>(int)$c['revision'],'message_hash'=>$this->crypto->digest(['message'=>$message]),'source_hash'=>$segment['fingerprint'],'provider_revision'=>$config['revision'],'language'=>$language,'schedule_version'=>(int)($s['version']??0),'schedule_state'=>$s['state']??null];
            $row=['campaign_id'=>5,'audience_id'=>(int)$a['id'],'phase'=>'preparing','actor_id'=>get_current_user_id(),'requested_at'=>$now,'approved_at'=>0,'expires_at'=>$now+ApprovalPolicy::CAPTURE_SECONDS,'updated_at'=>$now,'provider_revision'=>$config['revision'],'approval_hash'=>null,'binding_hash'=>null,'payload'=>$this->crypto->seal(['preflight'=>$pre])];
            if($this->control(true))$this->change($row);else $this->ok($this->database->db->insert($this->t('controlled'),$row));
            $this->repo->m2->store()->audit(5,'controlled_validation_started',['audience_id'=>(int)$a['id'],'revision'=>(int)$c['revision'],'environment'=>2]);return $a;
        });});
        if(!$result)$this->deny('Campaign is busy. No controlled action was performed.');
        M2Scheduler::enqueue($result);M2Scheduler::ensure();return ['status'=>M2Api::status($this->repo->m2,5),'controlled'=>$this->summary(5)];
    }
    private function context(array $row,bool $sending):array
    {
        [$c,$segment,$schedule]=$this->base($sending);$config=$this->settings();$stored=$this->crypto->open($row['payload']);$pre=$stored['preflight'];$now=time();
        if((int)$row['actor_id']!==get_current_user_id()||$now>(int)$row['expires_at']||(int)$c['audience_id']!==(int)$row['audience_id']||(int)$c['revision']!==$pre['revision']||$config['revision']!==$pre['provider_revision']||!hash_equals($segment['fingerprint'],$pre['source_hash'])||(int)($schedule['version']??0)!==$pre['schedule_version']||($schedule['state']??null)!==$pre['schedule_state'])$this->deny('Controlled preflight changed or expired. Prepare a fresh Test audience and approve again.');
        $a=$this->repo->m2->store()->audience((int)$row['audience_id']);$counts=json_decode($a['counts_json'],true);
        if($a['state']!=='complete'||(int)$counts['prepared']!==1||!$a['manifest_hash']||(int)$a['started_at']<(int)$row['requested_at']||$now-(int)$a['completed_at']>self::FRESH||(int)$a['completed_at']>$now||(int)$a['normalizer_version']!==EgyptMobileNormalizer::VERSION||(int)$a['review_version']!==1)$this->deny('A fresh, complete audience with exactly one unique ready recipient is required.');
        $message=$this->repo->m2->message($c);$this->validateMessage($message,$pre['language']);if(!hash_equals($pre['message_hash'],$this->crypto->digest(['message'=>$message])))$this->deny('The message changed. Prepare a fresh controlled Test.');
        if(!$c['review_hash']||!hash_equals($c['review_hash'],hash('sha256',json_encode($this->repo->m2->reviewBinding($c,$a),JSON_THROW_ON_ERROR))))$this->deny('Save and review the exact current campaign package before controlled approval.');
        $db=$this->database->db;$recipients=$this->ok($db->get_results($db->prepare('SELECT * FROM '.$this->t('recipients').' WHERE audience_id=%d AND id>0 ORDER BY id ASC LIMIT 2',$a['id']),ARRAY_A));if(count($recipients)!==1)$this->deny('Recipient evidence does not match the one-recipient audience.');$recipient=$recipients[0];$payload=$this->crypto->open($recipient['payload']);
        if($recipient['state']!=='prepared'||!hash_equals($recipient['payload_hash'],$this->crypto->digest($payload))||!is_string($payload['phone']??null)||(new EgyptMobileNormalizer())->normalize($payload['phone'])['phone']!==$payload['phone']||!hash_equals($recipient['phone_hash'],$this->crypto->correlate($payload['phone']))||$this->repo->m2->store()->suppressed($recipient['phone_hash']))$this->deny('Recipient evidence changed or an SMS suppression is active.');
        $members=$this->ok($db->get_results($db->prepare('SELECT * FROM '.$this->t('members').' WHERE audience_id=%d AND id>0 ORDER BY id ASC LIMIT 101',$a['id']),ARRAY_A));$retained=array_values(array_filter($members,fn($m)=>$m['disposition']==='prepared'&&$m['phone_hash']===$recipient['phone_hash']));if(count($retained)!==1)$this->deny('The retained source identity cannot be verified.');
        $page=$this->source->scan($segment,0,(int)$a['ceiling']);if(!$page['complete'])$this->deny('The first controlled Test requires a source segment fitting one bounded scan page.');$current=array_values(array_filter($page['members'],fn($m)=>(int)$m['customer_id']===(int)$retained[0]['source_id']));
        if(count($current)!==1||!empty($current[0]['identity_warning'])||(new EgyptMobileNormalizer())->normalize((string)($current[0]['phone']??''))['phone']!==$payload['phone'])$this->deny('Current source identity or phone no longer matches the approved destination. No substitution is permitted.');
        $binding=['campaign'=>5,'revision'=>(int)$c['revision'],'audience_id'=>(int)$a['id'],'manifest'=>$a['manifest_hash'],'source_hash'=>$segment['fingerprint'],'source_id'=>(int)$retained[0]['source_id'],'recipient_id'=>(int)$recipient['id'],'phone_hash'=>$recipient['phone_hash'],'message_hash'=>$pre['message_hash'],'review_hash'=>$c['review_hash'],'provider_revision'=>$config['revision'],'sender_hash'=>$this->crypto->digest(['sender'=>$config['sender']]),'environment'=>2,'language'=>$pre['language'],'schedule_version'=>(int)($schedule['version']??0),'schedule_state'=>$schedule['state']??null,'start_utc'=>$schedule['start_at']??null,'count'=>1,'expires'=>min((int)$row['expires_at'],(int)$a['completed_at']+self::FRESH)];
        return [$binding,$config,$message,$payload['phone'],$recipient,$a];
    }
    public function approve(array $p):array
    {
        $this->authorize($p);if(($p['approve']??'')!=='one_test_recipient')$this->deny('Explicit one-recipient Test approval is required.');
        $token=bin2hex(random_bytes(32));$result=$this->repo->m2->lock(-5,function()use($p,$token):array{return $this->database->transaction(function()use($p,$token):array{
            $this->repo->campaignLock(5);$row=$this->control(true);if(!$row||!in_array($row['phase'],['preparing','approved'],true))$this->deny('Start a new controlled preflight before approval.');[$binding]=$this->context($row,false);
            if((string)$binding['language']!==($p['language']??''))$this->deny('Language changed. Prepare a fresh controlled audience before approval.');
            if((string)$binding['revision']!==($p['revision']??''))$this->deny('Campaign revision changed before approval.');$stored=$this->crypto->open($row['payload']);$stored['binding']=$binding;$stored['token_hash']=hash('sha256',$token);$hash=$this->crypto->digest(['binding'=>$binding,'token_hash'=>$stored['token_hash']]);$now=time();
            $this->ok($this->database->db->insert($this->t('approvals'),['campaign_id'=>5,'audience_id'=>$row['audience_id'],'binding_hash'=>$hash,'evidence_json'=>json_encode(['scope'=>'one_controlled_test','binding'=>$binding],JSON_THROW_ON_ERROR),'actor_id'=>get_current_user_id(),'approved_at'=>$now,'expires_at'=>$binding['expires'],'operational'=>1]));
            $this->change(['phase'=>'approved','approval_hash'=>$hash,'binding_hash'=>$this->crypto->digest($binding),'payload'=>$this->crypto->seal($stored),'approved_at'=>$now,'expires_at'=>$binding['expires'],'updated_at'=>$now]);$this->repo->m2->store()->audit(5,'controlled_test_approved',['revision'=>$binding['revision'],'audience_id'=>$binding['audience_id'],'recipient_count'=>1,'environment'=>2]);return ['confirmation_token'=>$token];
        });});if(!$result)$this->deny('Campaign is busy. Approval was not recorded.');return $result+['controlled'=>$this->summary(5)];
    }
    private function receipt(array $attempt):array{return ['id'=>(int)$attempt['id'],'state'=>$attempt['state'],'code'=>$attempt['provider_code'],'smsid'=>$attempt['smsid'],'cost'=>$attempt['cost'],'http_status'=>(int)$attempt['http_status'],'retry_class'=>$attempt['retry_class'],'delivered'=>false,'automatic_retry'=>false];}
    public function confirm(array $p):array
    {
        $this->authorize($p);if(($p['confirm_text']??'')!=='SEND TEST CAMPAIGN 5'||($p['ack']??'')!=='1'||!is_string($p['token']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['token']))$this->deny('Type SEND TEST CAMPAIGN 5 and explicitly authorize the single Test request.');
        $db=$this->database->db;$providerLock='azula_sms_provider_'.substr(hash('sha256',$db->prefix),0,32);if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,0)',$providerLock))!=='1')$this->deny('Provider settings are busy. No Test request was made.');
        try{$result=$this->repo->m2->lock(-5,function()use($p,$db):array{
            $row=$this->control();if(!$row||(int)$row['actor_id']!==get_current_user_id())$this->deny('No controlled approval belongs to this administrator.');$stored=$this->crypto->open($row['payload']);if(!isset($stored['token_hash'])||!hash_equals($stored['token_hash'],hash('sha256',$p['token'])))$this->deny('Controlled confirmation expired or changed.');
            foreach($this->attempts() as $old)if($old['approval_hash']===$row['approval_hash'])return ['attempt'=>$this->receipt($old),'replayed'=>true];
            if($row['phase']!=='approved')$this->deny('A current explicit controlled approval is required.');
            $claim=$this->database->transaction(function()use($row,$stored,$db):array{
                $this->repo->campaignLock(5);$fresh=$this->control(true);if($fresh['approval_hash']!==$row['approval_hash']||$fresh['phase']!=='approved')$this->deny('Approval changed before the submission claim.');[$binding,$config,$message,$phone,$recipient,$audience]=$this->context($fresh,true);
                if(!hash_equals($fresh['approval_hash'],$this->crypto->digest(['binding'=>$stored['binding'],'token_hash'=>$stored['token_hash']]))||!hash_equals($fresh['binding_hash'],$this->crypto->digest($binding))||!hash_equals($fresh['binding_hash'],$this->crypto->digest($stored['binding'])))$this->deny('The approved campaign, audience or provider binding changed.');
                $approval=$this->ok($db->get_row($db->prepare('SELECT * FROM '.$this->t('approvals').' WHERE binding_hash=%s',$fresh['approval_hash']),ARRAY_A));if(!$approval||(int)$approval['operational']!==1||(int)$approval['actor_id']!==get_current_user_id()||time()>(int)$approval['expires_at'])$this->deny('Controlled approval evidence is unavailable or expired.');
                $request=(new SmsMisrAdapter($config))->submissionRequest([$phone],$message,$binding['language']);$now=time();$this->ok($db->insert($this->t('attempts'),['campaign_id'=>5,'audience_id'=>$audience['id'],'recipient_id'=>$recipient['id'],'phone_hash'=>$recipient['phone_hash'],'approval_hash'=>$fresh['approval_hash'],'request_hash'=>$this->crypto->digest($binding),'state'=>'unknown','provider_code'=>null,'smsid'=>null,'cost'=>null,'retry_class'=>'unknown_submission_outcome','http_status'=>0,'claimed_at'=>$now,'finished_at'=>0,'receipt'=>$this->crypto->seal(['status'=>'claim_recorded_outcome_unknown','delivered'=>false])]));$id=(int)$db->insert_id;
                $this->ok($db->update($this->t('campaigns'),['transmission_intent'=>1],['id'=>5]));$this->ok($db->update($this->t('recipients'),['state'=>'claimed'],['id'=>$recipient['id']]));$this->change(['phase'=>'submitting','updated_at'=>$now]);$this->repo->m2->store()->audit(5,'controlled_request_claimed',['attempt_id'=>$id,'recipient_count'=>1,'environment'=>2]);return [$id,$binding,$request,$recipient];
            });
            [$id,$binding,$request,$recipient]=$claim;$http=0;$response=['accepted'=>false,'delivered'=>false,'code'=>null,'smsid'=>null,'cost'=>null,'status'=>'transport_unknown','retry_class'=>'unknown_submission_outcome'];$state='unknown';
            if(!self::enabled()||time()>$binding['expires']||($binding['start_utc']!==null&&(time()<(int)$binding['start_utc']||time()>(int)$binding['start_utc']+900))||$this->repo->m2->store()->suppressed($recipient['phone_hash'])){$response['status']='aborted_before_submission';$response['retry_class']='safe_not_sent';$state='aborted';}
            else{
                try{
                    // Sole SMS network call. Claim is already durably committed. No worker calls this method.
                    $r=wp_remote_post(SmsMisrAdapter::SMS_URL,['timeout'=>15,'redirection'=>0,'sslverify'=>true,'blocking'=>true,'limit_response_size'=>16384,'headers'=>$request['headers'],'body'=>$request['body'],'data_format'=>'body']);
                    if(!is_wp_error($r)){$http=(int)wp_remote_retrieve_response_code($r);$raw=wp_remote_retrieve_body($r);$response=SmsMisrAdapter::response($http,is_string($raw)?$raw:'');}
                }catch(\Throwable $error){} // Never record raw transport/provider errors or credentials.
                if($response['accepted'])$state='accepted';elseif($http===200&&in_array($response['retry_class'],['permanent_rejection','transient_rejection'],true))$state='rejected';
            }
            $this->database->transaction(function()use($id,$state,$response,$http,$recipient,$db):void{
                $this->ok($db->update($this->t('attempts'),['state'=>$state,'provider_code'=>$response['code'],'smsid'=>$response['smsid'],'cost'=>$response['cost'],'retry_class'=>$response['retry_class'],'http_status'=>$http,'finished_at'=>time(),'receipt'=>$this->crypto->seal($response)],['id'=>$id]));
                $retry=in_array($state,['rejected','aborted'],true);$this->ok($db->update($this->t('campaigns'),['transmission_intent'=>$retry?0:1,'state'=>'controlled_'.$state],['id'=>5]));$this->ok($db->update($this->t('recipients'),['state'=>$retry?'prepared':($state==='accepted'?'submitted_test':'submission_unknown')],['id'=>$recipient['id']]));$this->change(['phase'=>$state,'updated_at'=>time()]);$this->repo->m2->store()->audit(5,'controlled_'.$state,['attempt_id'=>$id,'recipient_count'=>1,'environment'=>2,'http_status'=>$http,'provider_code'=>$response['code'],'smsid'=>$response['smsid'],'cost'=>$response['cost'],'result'=>$response['status'],'retry_class'=>$response['retry_class']]);
            });
            foreach($this->attempts() as $a)if((int)$a['id']===$id)return ['attempt'=>$this->receipt($a),'replayed'=>false];throw new \RuntimeException('Recorded attempt unavailable.');
        });if(!$result)$this->deny('Campaign submission is busy. Refresh activity instead of retrying.');return $result;}finally{$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$providerLock));}
    }
    public function summary(int $campaign):array
    {
        $default=['owner_enabled'=>self::enabled(),'automatic_dispatch'=>false,'live_enabled'=>false,'campaign_allowed'=>$campaign===5,'required_campaign'=>5,'required_segment'=>self::SEGMENT,'phase'=>'not_started','can_prepare'=>false,'can_approve'=>false,'can_confirm'=>false,'reason'=>'Only Campaign #5 on test {azula} can use the first controlled Test.','recipient_count'=>0];
        if($campaign!==5)return $default;
        if(!self::enabled())return array_replace($default,['reason'=>'Owner opt-in is off. Installation does not enable sending.']);
        try{[$c]=$this->base();$this->settings();$row=$this->control();$default['can_prepare']=true;$default['reason']='Prepare a fresh controlled audience. No SMS is sent by validation or approval.';
            if($row){$default['phase']=$row['phase'];$default['expires']=(int)$row['expires_at'];$default['audience_id']=(int)$row['audience_id'];$default['reason']='Review the fresh one-recipient audience and save its current package before approval.';
                try{[$binding,,,$phone]=$this->context($row,false);$default['recipient_count']=1;$default['masked_recipient']='20••••••'.substr($phone,-3);$default['can_approve']=in_array($row['phase'],['preparing','approved'],true);$default['can_confirm']=$row['phase']==='approved'&&hash_equals((string)$row['binding_hash'],$this->crypto->digest($binding));$default['reason']=$default['can_confirm']?'Exact one-recipient Test approval is ready for explicit confirmation. Acceptance will not prove delivery.':'Save/review and explicitly approve this exact one-recipient Test package.';}catch(ControlledTestException $e){$default['reason']=$e->getMessage();}
            }
        }catch(ControlledTestException $e){$default['reason']=$e->getMessage();$row=$this->control();if($row)$default['phase']=$row['phase'];}
        return $default;
    }
}
