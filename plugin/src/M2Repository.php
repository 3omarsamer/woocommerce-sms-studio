<?php
namespace Azula\SmsStudio;

/** Only SMS-owned storage. No source writes or provider behavior. */
final class M2Repository
{
    private $db;
    private static array $ownedLocks=[];
    public function __construct(private Database $database, private Crypto $crypto) { $this->db = $database->db; }
    private function table(string $name): string { return $this->database->table($name); }
    private function checked($result) { return $this->database->check($result); }
    public function store(): WordPressStore { return new WordPressStore($this->database); }
    public function displayStatus(int $id):?string{return RC1Status::modern()?(RC1Status::campaign(new M6Store($this->database,$this->crypto),$id)['label']??null):null;}
    public function scheduling(): M3Repository { return new M3Repository($this->database,$this->crypto); }
    public function review(int $campaign,int $audience,array $spec,bool $export=false): array { return (new AudienceReview($this->database,$this->crypto))->query($campaign,$audience,$spec,$export); }
    public function preparation(AudienceSource $source): AudiencePreparationService { return new AudiencePreparationService($source,$this->store(),$this->crypto); }
    public function draft(string $token,int $segment,string $name): int
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new \InvalidArgumentException('Invalid draft request.');
        $hash=hash('sha256',$token);$lock='azula_sms_draft_'.substr(hash('sha256',$this->db->prefix.$hash),0,32);
        if((string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new \RuntimeException('Draft creation busy.');
        try{$id=$this->db->get_var($this->db->prepare("SELECT id FROM {$this->table('campaigns')} WHERE draft_token=%s",$hash));$this->checked(true);return $id?(int)$id:$this->store()->createCampaign($name,$segment,null,CairoTime::ZONE,$hash);}
        finally{$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public function lock(int $id, callable $fn)
    {
        $name = 'azula_sms_worker_' . substr(hash('sha256', $this->db->prefix . ':' . $id), 0, 32);
        $key=spl_object_id($this->db).':'.$name;
        if(isset(self::$ownedLocks[$key]))return $fn(); // Nested services share this request's already-owned connection lock.
        if ((string) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)', $name)) !== '1') return null;
        self::$ownedLocks[$key]=true;
        try { return $fn(); } finally { unset(self::$ownedLocks[$key]);$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    }
    public function updateAudience(int $id, array $data): void
    {
        $allowed = ['retry_count','last_progress_at','next_attempt_at','error_code','normalizer_version','review_version'];
        if (array_diff(array_keys($data), $allowed)) throw new \InvalidArgumentException('Invalid preparation metadata.');
        $this->checked($this->db->update($this->table('audiences'), $data, ['id' => $id]));
    }
    public function active(): array
    {
        return $this->checked($this->db->get_results("SELECT a.* FROM {$this->table('audiences')} a INNER JOIN {$this->table('campaigns')} c ON c.audience_id=a.id WHERE a.state='preparing' ORDER BY a.started_at ASC LIMIT 20", ARRAY_A));
    }
    public function history(int $segment): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT id,state,counts_json,started_at,completed_at FROM {$this->table('audiences')} WHERE segment_id=%d AND state IN ('complete','preparing','blocked') ORDER BY id DESC LIMIT 1", $segment), ARRAY_A);
        $this->checked(true);
        return $row ? ['counts' => json_decode($row['counts_json'], true), 'at' => (int) ($row['completed_at'] ?: $row['started_at']), 'complete' => $row['state'] === 'complete', 'historical' => true] : null;
    }
    public function recent(): array
    {
        return $this->checked($this->db->get_results("SELECT c.id,c.name,c.state,c.segment_id,s.state schedule_state,s.start_at scheduled_at FROM {$this->table('campaigns')} c LEFT JOIN {$this->table('schedules')} s ON s.campaign_id=c.id ORDER BY c.id DESC LIMIT 30", ARRAY_A));
    }
    public function histories(array $segments): array
    {
        if(!$segments)return [];
        $ids=array_map(static fn($s)=>(int)$s['id'],$segments);$marks=implode(',',array_fill(0,count($ids),'%d'));$t=$this->table('audiences');
        $rows=$this->checked($this->db->get_results($this->db->prepare("SELECT a.* FROM $t a INNER JOIN (SELECT MAX(id) id FROM $t WHERE segment_id IN ($marks) AND state IN ('complete','preparing','blocked') GROUP BY segment_id) latest ON latest.id=a.id",...$ids),ARRAY_A));$result=[];
        foreach($rows as $a)$result[(int)$a['segment_id']]=['counts'=>json_decode($a['counts_json'],true),'at'=>(int)($a['completed_at']?:$a['started_at']),'complete'=>$a['state']==='complete','historical'=>true];
        return $result;
    }
    public function saveMessage(int $id, string $message, int $expectedRevision): array
    {
        MessageMetrics::calculate($message);
        return $this->database->transaction(function () use ($id,$message,$expectedRevision): array {
            $c = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d FOR UPDATE", $id), ARRAY_A); $this->checked(true);
            if (!$c || (int) $c['revision'] !== $expectedRevision || $c['transmission_intent'] || ($c['state']==='trashed'||CampaignLifecycle::cancelled($this->database,$c))) throw new \DomainException('Campaign changed. Reload before saving.');
            $revision = $expectedRevision + 1;
            $state = $c['audience_id'] ? ($this->store()->audience((int)$c['audience_id'])['state'] === 'complete' ? 'review_ready' : 'preparing') : 'draft';
            $this->checked($this->db->update($this->table('campaigns'), ['composer_payload' => $this->crypto->seal(['message' => $message]), 'revision' => $revision, 'review_hash' => null, 'approval_hash' => null, 'state' => $state], ['id' => $id]));
            $this->checked($this->db->insert($this->table('revisions'), ['campaign_id' => $id,'revision' => $revision,'binding_json' => json_encode(['message_hash' => $this->crypto->digest(['message'=>$message]),'message_payload'=>$this->crypto->seal(['message'=>$message]),'dispatch_available'=>false]),'created_at'=>time()]));
            (new M3Repository($this->database,$this->crypto))->invalidate($id,'message_changed');
            return $this->store()->campaign($id);
        });
    }
    public function message(array $campaign): string { return empty($campaign['composer_payload']) ? '' : (string) $this->crypto->open($campaign['composer_payload'])['message']; }
    public function reviewBinding(array $c,array $a): array
    {
        $metrics=MessageMetrics::calculate($this->message($c),(int)json_decode($a['counts_json'],true)['prepared'],self::rate());
        return ['revision'=>(int)$c['revision'],'audience_id'=>(int)$a['id'],'manifest'=>$a['manifest_hash'],'segment_id'=>(int)$c['segment_id'],'rules_hash'=>$a['rules_hash'],'capture_started'=>(int)$a['started_at'],'capture_completed'=>(int)$a['completed_at'],'message_hash'=>$this->crypto->digest(['message'=>$this->message($c)]),'normalizer_version'=>EgyptMobileNormalizer::VERSION,'renderer_version'=>1,'policy_version'=>1,'metrics'=>$metrics,'rate_minor'=>self::rate(),'operational'=>false];
    }
    public function saveReview(int $id, int $expectedRevision, int $now): string
    {
        return $this->database->transaction(function () use ($id,$expectedRevision,$now): string {
            $c = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table('campaigns')} WHERE id=%d FOR UPDATE", $id), ARRAY_A); $this->checked(true);
            if (!$c || (int)$c['revision'] !== $expectedRevision || !$c['audience_id'] || $c['transmission_intent'] || ($c['state']==='trashed'||CampaignLifecycle::cancelled($this->database,$c))) throw new \DomainException('Review no longer matches campaign.');
            $a = $this->store()->audience((int)$c['audience_id']);
            if(MessageValidation::modern()&&($problem=MessageValidation::problem($this->message($c)))!=='')throw new \DomainException($problem);
            $metrics = MessageMetrics::calculate($this->message($c), (int)json_decode($a['counts_json'],true)['prepared'], self::rate());
            if ($a['state'] !== 'complete' || (int)$a['review_version'] !== 1 || (int)$a['normalizer_version'] !== EgyptMobileNormalizer::VERSION || !$a['manifest_hash'] || (int)$a['completed_at']<(int)$a['started_at'] || $now < (int)$a['completed_at'] || $now-(int)$a['completed_at'] > ApprovalPolicy::FRESH_SECONDS || (int)$a['completed_at']-(int)$a['started_at'] > ApprovalPolicy::CAPTURE_SECONDS || !$metrics['valid'] || !$metrics['recipients']) throw new \DomainException('A fresh completed audience and valid message are required.');
            $binding = ['revision'=>(int)$c['revision'],'audience_id'=>(int)$a['id'],'manifest'=>$a['manifest_hash'],'segment_id'=>(int)$c['segment_id'],'rules_hash'=>$a['rules_hash'],'capture_started'=>(int)$a['started_at'],'capture_completed'=>(int)$a['completed_at'],'message_hash'=>$this->crypto->digest(['message'=>$this->message($c)]),'normalizer_version'=>EgyptMobileNormalizer::VERSION,'renderer_version'=>1,'policy_version'=>1,'metrics'=>$metrics,'rate_minor'=>self::rate(),'operational'=>false];
            $hash = hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR));
            $this->checked($this->db->update($this->table('campaigns'),['review_hash'=>$hash,'state'=>'pending_review'],['id'=>$id]));
            $this->store()->audit($id,'review_saved',['binding_hash'=>$hash,'binding'=>$binding,'operational'=>false]);
            return $hash;
        });
    }
    public static function rate(): ?int { $v=get_option('azula_sms_rate_minor',null); return is_numeric($v) && (int)$v >= 0 && (int)$v <= 1000000 ? (int)$v : null; }
    public function details(int $campaign, int $audience, string $tab, int $after = 0): array
    {
        $c=$this->store()->campaign($campaign); $a=$this->store()->audience($audience);
        if ((int)$a['campaign_id']!==$campaign || (int)$c['audience_id']!==$audience || !in_array($tab,['ready','excluded'],true) || $after<0) throw new \DomainException('Invalid audience page.');
        $table=$this->table($tab==='ready'?'recipients':'members');
        $filter=$tab==='ready'?'':" AND disposition<>'prepared'";
        $rows=$this->checked($this->db->get_results($this->db->prepare("SELECT * FROM $table WHERE audience_id=%d AND id>%d $filter ORDER BY id ASC LIMIT 51",$audience,$after),ARRAY_A));
        $more=count($rows)>50; $rows=array_slice($rows,0,50); $result=[];
        foreach($rows as $row){
            $p=$this->crypto->open($row['payload']);$unverified=($row['disposition']??'')==='identity_unverified';
            $phone=$tab==='ready'?($p['phone']?:'Unavailable'):($p['original_phone']??($p['phone']?:'Unavailable in historical capture'));
            if(($row['disposition']??'')==='missing_phone')$phone='Missing';
            $result[]=['cursor'=>(int)$row['id'],'name'=>$unverified?'Identity withheld':($p['first_name']?:'Neutral greeting'),
                'phone'=>$unverified?'Withheld':($phone?:'Unavailable'),'reason'=>$row['disposition']??'ready',
                'detail'=>$unverified?'Identity verification failed':($p['exclusion_detail']??($p['validation_reason']??'Detailed evidence unavailable in historical capture')),
                'withheld'=>!empty($p['source_value_withheld'])];
        }
        return ['rows'=>$result,'more'=>$more,'next'=>$rows?(int)end($rows)['id']:$after,'complete'=>$a['state']==='complete'];
    }
    public function supersede(int $campaign): void
    {
        $c=$this->store()->campaign($campaign);
        if($c['transmission_intent']||($c['state']==='trashed'||CampaignLifecycle::cancelled($this->database,$c)))throw new \DomainException('Campaign is frozen.');
        if($c['audience_id'])$this->store()->fail((int)$c['audience_id']);
        $this->checked($this->db->update($this->table('campaigns'),['review_hash'=>null,'approval_hash'=>null],['id'=>$campaign]));
    }
}
