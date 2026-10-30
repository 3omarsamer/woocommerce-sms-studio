<?php
namespace Azula\SmsStudio;

/** Durable scheduling and held queue only. No submission or provider operation. */
final class M3Repository
{
    public M2Repository $m2;
    public function __construct(public Database $database, private Crypto $crypto) { $this->m2=new M2Repository($database,$crypto); }
    private function t(string $name): string { return $this->database->table($name); }
    private function ok($value) { return $this->database->check($value); }
    public function get(int $id, bool $lock=false): ?array
    {
        $row=$this->database->db->get_row($this->database->db->prepare('SELECT * FROM '.$this->t('schedules').' WHERE campaign_id=%d'.($lock?' FOR UPDATE':''),$id),ARRAY_A);$this->ok(true);return $row?:null;
    }
    public function update(int $id,array $data): void { $this->ok($this->database->db->update($this->t('schedules'),$data,['campaign_id'=>$id])); }
    public function campaignLock(int $id): array
    {
        $c=$this->database->db->get_row($this->database->db->prepare('SELECT * FROM '.$this->t('campaigns').' WHERE id=%d FOR UPDATE',$id),ARRAY_A);$this->ok(true);
        if (!$c || $c['transmission_intent'] || ($c['state']==='trashed'||CampaignLifecycle::cancelled($this->database,$c))) throw new \DomainException('Campaign unavailable or frozen.');return $c;
    }
    public function invalidate(int $id,string $reason='campaign_changed'): void
    {
        $this->database->transaction(function()use($id,$reason):void{
        $this->campaignLock($id);(new M6Store($this->database,$this->crypto))->invalidate($id,$reason);$s=$this->get($id,true);if(!$s)return;
        if(in_array($s['state'],['canceled','missed'],true)){$this->cancelQueue($id);return;}
        if($reason==='message_changed' && !$s['approval_hash'])return;
        $this->update($id,['state'=>'needs_review','approval_hash'=>null,'approval_payload'=>null,'error_code'=>$reason,'next_check'=>0,'queue_cursor'=>0,'verify_cursor'=>0]);
        $this->cancelQueue($id);
        $this->ok($this->database->db->update($this->t('campaigns'),['approval_hash'=>null],['id'=>$id]));
        });
    }
    public function cancelQueue(int $id): void { $this->ok($this->database->db->update($this->t('queue'),['state'=>'canceled'],['campaign_id'=>$id])); }
    public function plan(int $id,int $revision,string $local,string $offset,int $now): array
    {
        $start=CairoTime::resolve($local,$offset);
        if($start<=$now || $start>$now+366*86400)throw new \DomainException('Schedule must be in the future, within one year.');
        return $this->database->transaction(function()use($id,$revision,$start,$now):array{
            $c=$this->campaignLock($id);if((int)$c['revision']!==$revision)throw new \DomainException('Campaign changed. Reload.');(new M6Store($this->database,$this->crypto))->invalidate($id,'schedule_changed');
            $s=$this->get($id,true);$this->cancelQueue($id);
            $data=['state'=>'planned','version'=>(int)($s['version']??0)+1,'start_at'=>$start,'prepare_at'=>max($now,$start-3600),'next_check'=>max($now,$start-3600),'final_audience_id'=>null,'base_audience_id'=>$c['audience_id'],'refresh_started'=>0,'approval_hash'=>null,'approval_payload'=>null,'queue_cursor'=>0,'verify_cursor'=>0,'retry_count'=>0,'last_progress'=>$now,'error_code'=>null];
            if($s)$this->update($id,$data);else $this->ok($this->database->db->insert($this->t('schedules'),$data+['campaign_id'=>$id]));
            $this->ok($this->database->db->update($this->t('campaigns'),['revision'=>$revision+1,'start_at'=>$start,'timezone'=>CairoTime::ZONE,'review_hash'=>null,'approval_hash'=>null],['id'=>$id]));
            $this->revision($id,$revision+1,['start'=>$start,'timezone'=>CairoTime::ZONE,'operational'=>false],$now);
            $this->m2->store()->audit($id,'schedule_planned',['start_utc'=>$start,'version'=>$data['version'],'operational'=>false]);return $this->get($id);
        });
    }
    private function revision(int $id,int $revision,array $binding,int $now): void { $this->ok($this->database->db->insert($this->t('revisions'),['campaign_id'=>$id,'revision'=>$revision,'binding_json'=>json_encode($binding,JSON_THROW_ON_ERROR),'created_at'=>$now])); }
    public function control(int $id,int $version,string $op,int $now): array
    {
        return $this->database->transaction(function()use($id,$version,$op,$now):array{
            $c=$this->campaignLock($id);$s=$this->get($id,true);
            if(!$s || (int)$s['version']!==$version)throw new \DomainException('Schedule changed. Reload.');
            if(!in_array($op,['pause','resume','cancel'],true))throw new \InvalidArgumentException('Invalid schedule action.');
            if($op==='resume' && ($s['state']!=='paused' || (int)$s['start_at']<=$now))throw new \DomainException('Resume needs a paused future schedule.');
            if($op!=='resume' && in_array($s['state'],['canceled','missed'],true))throw new \DomainException('Schedule is terminal; set a new future schedule.');
            (new M6Store($this->database,$this->crypto))->invalidate($id,'schedule_'.$op);
            // Pausing revokes authority. Resume never silently restores it or replaces recipients.
            $state=$op==='pause'?'paused':($op==='cancel'?'canceled':($s['final_audience_id']?'awaiting_approval':'planned'));
            $this->cancelQueue($id);
            $this->update($id,['state'=>$state,'version'=>$version+1,'approval_hash'=>null,'approval_payload'=>null,'queue_cursor'=>0,'verify_cursor'=>0,'next_check'=>$op==='resume'?max($now,(int)$s['prepare_at']):0,'retry_count'=>0,'error_code'=>null]);
            $this->ok($this->database->db->update($this->t('campaigns'),['approval_hash'=>null],['id'=>$id]));
            $this->m2->store()->audit($id,'schedule_'.$op,['version'=>$version+1,'operational'=>false]);return $this->get($id);
        });
    }
    public function binding(array $c,array $a,array $s): array
    {
        $message=$this->m2->message($c);$e=ApprovalPolicy::audienceEvidence($a);
        return ['revision'=>(int)$c['revision'],'audience_id'=>$e['id'],'manifest'=>$e['manifest'],'segment_id'=>(int)$c['segment_id'],'rules_hash'=>$e['rules_hash'],'capture_started'=>$e['started'],'capture_completed'=>$e['completed'],'adapter_version'=>$e['adapter_version'],'key_version'=>$e['key_version'],'counts_hash'=>$e['counts_hash'],'message_hash'=>$this->crypto->digest(['message'=>$message]),'links_hash'=>hash('sha256',$message),'sender'=>'M3_UNCONFIGURED','language'=>'auto','environment'=>'staging:preview.azulasportswear.com','start'=>(int)$s['start_at'],'dispatch_end'=>(int)$s['start_at']+ApprovalPolicy::WINDOW_SECONDS,'timezone'=>CairoTime::ZONE,'normalizer_version'=>EgyptMobileNormalizer::VERSION,'renderer_version'=>1,'policy_version'=>3];
    }
    private function hash(array $binding,array $s): string { return hash('sha256',json_encode([(new ApprovalPolicy())->fingerprint($binding),(int)$s['version'],M2Repository::rate()],JSON_THROW_ON_ERROR)); }
    public function approve(int $id,int $revision,int $version,int $actor,int $now,AudienceSource $source): array
    {
        return $this->database->transaction(function()use($id,$revision,$version,$actor,$now,$source):array{
            $c=$this->campaignLock($id);$s=$this->get($id,true);
            if(!$s || (int)$s['version']!==$version || (int)$c['revision']!==$revision || !in_array($s['state'],['awaiting_approval','queue_preparing','scheduled_approved'],true) || !$s['final_audience_id'] || (int)$c['audience_id']!==(int)$s['final_audience_id'] || !$c['review_hash'])throw new \DomainException('Final scheduled audience and saved review required.');
            $a=$this->m2->store()->audience((int)$c['audience_id']);$b=$this->binding($c,$a,$s);
            if(!hash_equals($c['review_hash'],hash('sha256',json_encode($this->m2->reviewBinding($c,$a),JSON_THROW_ON_ERROR))))throw new \DomainException('Saved review no longer matches the final audience.');
            $metrics=MessageMetrics::calculate($this->m2->message($c),(int)json_decode($a['counts_json'],true)['prepared']);
            if(!$metrics['valid'] || !$metrics['recipients'] || (int)$a['normalizer_version']!==EgyptMobileNormalizer::VERSION || (int)$a['review_version']!==1 || !$a['manifest_hash'] || (int)$a['completed_at']<(int)$s['start_at']-ApprovalPolicy::FRESH_SECONDS || !hash_equals($a['rules_hash'],$source->segment((int)$c['segment_id'])['fingerprint']))throw new \DomainException('Audience or segment is stale. Explicitly prepare a new final audience.');
            $record=(new ApprovalPolicy())->record($b,ApprovalPolicy::audienceEvidence($a),$actor,$now);$hash=$this->hash($b,$s);
            if($s['approval_hash'] && hash_equals($s['approval_hash'],$hash))return $s; // replay-safe
            $record['preparation_hash']=$hash;$record['schedule_version']=(int)$s['version'];$record['rate_minor']=M2Repository::rate();
            $this->ok($this->database->db->insert($this->t('approvals'),['campaign_id'=>$id,'audience_id'=>$a['id'],'binding_hash'=>$hash,'evidence_json'=>json_encode($record,JSON_THROW_ON_ERROR),'actor_id'=>$actor,'approved_at'=>$now,'expires_at'=>$record['expires_at'],'operational'=>0]));
            $this->ok($this->database->db->update($this->t('campaigns'),['approval_hash'=>$hash],['id'=>$id]));
            $this->update($id,['state'=>'queue_preparing','approval_hash'=>$hash,'approval_payload'=>$this->crypto->seal($record),'queue_cursor'=>0,'verify_cursor'=>0,'retry_count'=>0,'error_code'=>null,'next_check'=>$now]);
            $this->m2->store()->audit($id,'queue_preparation_approved',['binding_hash'=>$hash,'recipient_count'=>$metrics['recipients'],'operational'=>false]);return $this->get($id);
        });
    }
    public function valid(array $c,array $a,array $s,int $now,AudienceSource $source): bool
    {
        if(!$s['approval_hash'] || $c['approval_hash']!==$s['approval_hash'] || (int)$c['audience_id']!==(int)$s['final_audience_id'] || $a['state']!=='complete' || (int)$a['normalizer_version']!==EgyptMobileNormalizer::VERSION || $now-(int)$a['completed_at']>ApprovalPolicy::FRESH_SECONDS || $now>(int)$s['start_at']+900)return false;
        $record=$this->crypto->open($s['approval_payload']);
        return ($record['operational']??null)===false && hash_equals($s['approval_hash'],$this->hash($this->binding($c,$a,$s),$s)) && hash_equals($record['preparation_hash'],$s['approval_hash']) && hash_equals($a['rules_hash'],$source->segment((int)$c['segment_id'])['fingerprint']);
    }
    public function finalCapture(int $id,int $now,AudienceSource $source): void
    {
        $adopt=$this->database->transaction(function()use($id,$now,$source):?array{
        // Called under campaign advisory lock. Checkpoint permits crash-safe adoption of an
        // unapproved capture committed between begin() and schedule checkpoint.
        $c=$this->campaignLock($id);$s=$this->get($id,true);
        if(!$s || !in_array($s['state'],['planned','final_preparing'],true) || $s['approval_hash'])return null;
        if(!$s['refresh_started']){$this->update($id,['state'=>'final_preparing','refresh_started'=>$now,'base_audience_id'=>$c['audience_id']]);$s=$this->get($id);}
        $adopt=null;
        if($c['audience_id'] && (int)$c['audience_id']!==(int)$s['base_audience_id']){
            $candidate=$this->m2->store()->audience((int)$c['audience_id']);if((int)$candidate['started_at']>=(int)$s['refresh_started'])$adopt=$candidate;
        }
        if(!$adopt){
            $this->m2->supersede($id);$adopt=$this->m2->preparation($source)->begin($id,$now);
        }
        $this->m2->updateAudience((int)$adopt['id'],['normalizer_version'=>EgyptMobileNormalizer::VERSION,'review_version'=>1,'last_progress_at'=>$now]);
        $this->update($id,['state'=>'final_preparing','final_audience_id'=>$adopt['id'],'next_check'=>$now+30,'last_progress'=>$now]);
        return $adopt;
        });
        if($adopt && $adopt['state']==='preparing')M2Scheduler::enqueue($this->m2->store()->audience((int)$adopt['id']));
    }
    public function queueBatch(int $id,int $now,AudienceSource $source): void
    {
        $this->database->transaction(function()use($id,$now,$source):void{
            $c=$this->campaignLock($id);$s=$this->get($id,true);$a=$this->m2->store()->audience((int)$s['final_audience_id']);
            if(!$this->valid($c,$a,$s,$now,$source)){$this->invalidate($id,'approval_invalidated');return;}
            $db=$this->database->db;$verify=$s['state']==='due_verifying';$cursor=(int)$s[$verify?'verify_cursor':'queue_cursor'];
            $sql=$verify?$db->prepare('SELECT * FROM '.$this->t('queue').' WHERE campaign_id=%d AND approval_hash=%s AND id>%d ORDER BY id ASC LIMIT 100',$id,$s['approval_hash'],$cursor):$db->prepare('SELECT * FROM '.$this->t('recipients').' WHERE audience_id=%d AND id>%d ORDER BY id ASC LIMIT 100',$a['id'],$cursor);
            $rows=$this->ok($db->get_results($sql,ARRAY_A));
            foreach($rows as $row){
                $cursor=(int)$row['id'];$suppressed=$this->m2->store()->suppressed($row['phone_hash']);
                $recipient=$verify?$db->get_row($db->prepare('SELECT * FROM '.$this->t('recipients').' WHERE id=%d',(int)$row['recipient_id']),ARRAY_A):$row;$this->ok(true);
                if(!$recipient || $recipient['state']!=='prepared' || (int)$recipient['audience_id']!==(int)$a['id'] || (int)$recipient['campaign_id']!==$id || !hash_equals($row['phone_hash'],$recipient['phone_hash']))throw new \DomainException('Recipient reference invalid.');
                $payload=$this->crypto->open($recipient['payload']);$phone=$payload['phone']??'';
                if(!hash_equals($recipient['payload_hash'],$this->crypto->digest($payload)) || (new EgyptMobileNormalizer())->normalize($phone)['phone']!==$phone || !hash_equals($recipient['phone_hash'],$this->crypto->correlate($phone)))throw new \DomainException('Recipient evidence invalid.');
                if($verify){if($suppressed)$this->ok($db->update($this->t('queue'),['state'=>'suppressed'],['id'=>$row['id']]));continue;}
                $existing=$db->get_row($db->prepare('SELECT * FROM '.$this->t('queue').' WHERE campaign_id=%d AND phone_hash=%s',$id,$row['phone_hash']),ARRAY_A);$this->ok(true);
                $data=['audience_id'=>$a['id'],'recipient_id'=>$row['id'],'approval_hash'=>$s['approval_hash'],'state'=>$suppressed?'suppressed':'held_disabled'];
                if($existing)$this->ok($db->update($this->t('queue'),$data,['id'=>$existing['id']]));
                else $this->ok($db->insert($this->t('queue'),$data+['campaign_id'=>$id,'phone_hash'=>$row['phone_hash'],'created_at'=>$now]));
            }
            $data=[$verify?'verify_cursor':'queue_cursor'=>$cursor,'last_progress'=>$now,'next_check'=>$now,'retry_count'=>0,'error_code'=>null];
            if(count($rows)<100){
                $total=$db->get_var($db->prepare('SELECT COUNT(*) FROM '.$this->t('queue').' WHERE campaign_id=%d AND approval_hash=%s',$id,$s['approval_hash']));$this->ok($total);
                if((int)$total!==(int)json_decode($a['counts_json'],true)['prepared'])throw new \DomainException('Queue count does not match approved unique recipients.');
                $data['state']=$verify?'ready_disabled':'scheduled_approved';$data['next_check']=$verify?0:max($now,(int)$s['start_at']);
            }
            $this->update($id,$data);
        });
    }
    public function tick(int $id,int $now,AudienceSource $source): void
    {
        $s=$this->get($id);if(!$s || (int)$s['next_check']>$now || in_array($s['state'],['paused','canceled','missed','needs_review','ready_disabled'],true))return;
        if($now>(int)$s['start_at']+900 || ($now>=(int)$s['start_at'] && !$s['approval_hash'])){$this->invalidate($id,'approval_deadline_missed');$this->update($id,['state'=>'missed']);return;}
        if($s['state']==='planned' || ($s['state']==='final_preparing' && !$s['final_audience_id'])){$this->finalCapture($id,$now,$source);return;}
        if(in_array($s['state'],['final_preparing','awaiting_approval'],true)){
            $c=$this->m2->store()->campaign($id);$a=$this->m2->store()->audience((int)$s['final_audience_id']);
            if((int)$c['audience_id']!==(int)$a['id'] || in_array($a['state'],['blocked','purged'],true)){$this->invalidate($id,'final_capture_blocked');return;}
            $this->update($id,['state'=>$a['state']==='complete'?'awaiting_approval':'final_preparing','next_check'=>$a['state']==='complete'?(int)$s['start_at']:$now+30,'last_progress'=>$now,'retry_count'=>0]);return;
        }
        if($s['state']==='scheduled_approved'){
            if($now<(int)$s['start_at']){$this->update($id,['next_check'=>(int)$s['start_at']]);return;}
            $this->update($id,['state'=>'due_verifying','next_check'=>$now]);
        }
        $this->queueBatch($id,$now,$source);
    }
    public function due(int $now): array { return $this->ok($this->database->db->get_results($this->database->db->prepare('SELECT * FROM '.$this->t('schedules')." WHERE next_check>0 AND next_check<=%d ORDER BY next_check ASC,campaign_id ASC LIMIT 20",$now),ARRAY_A)); }
    public function status(int $id): ?array
    {
        $s=$this->get($id);if(!$s)return null;
        $counts=['held_disabled'=>0,'suppressed'=>0,'canceled'=>0];
        $rows=$this->ok($this->database->db->get_results($this->database->db->prepare('SELECT state,COUNT(*) total FROM '.$this->t('queue').' WHERE campaign_id=%d AND approval_hash=%s GROUP BY state',$id,$s['approval_hash']??''),ARRAY_A));foreach($rows as $row)$counts[$row['state']]=(int)$row['total'];
        return ['version'=>(int)$s['version'],'state'=>$s['state'],'start_utc'=>(int)$s['start_at'],'egypt_time'=>CairoTime::label((int)$s['start_at']),'prepare_egypt'=>CairoTime::label((int)$s['prepare_at']),'next_check'=>(int)$s['next_check'],'last_progress'=>(int)$s['last_progress'],'retry_count'=>(int)$s['retry_count'],'error_code'=>$s['error_code'],'final_audience_id'=>(int)$s['final_audience_id'],'approved'=>(bool)$s['approval_hash'],'queue'=>$counts,'operational'=>false];
    }
}
