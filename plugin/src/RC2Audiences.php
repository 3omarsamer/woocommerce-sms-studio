<?php
namespace Azula\SmsStudio;

/** SMS-owned audiences only. No provider, external-plugin or campaign writes. */
final class RC2Audiences
{
    public const MAX_SAVED=1000;
    private array $verified=[];
    private array $verifiedIdentity=[];
    public function __construct(private M6Store $s,private AudienceSource $source){}
    public static function modern():bool{return defined('AZULA_SMS_STUDIO_VERSION')&&version_compare(AZULA_SMS_STUDIO_VERSION,'1.0.0-rc.2','>=');}
    private function authorize(array $p):void
    {
        M2Api::authorize(is_string($p['nonce']??null)?$p['nonce']:'');
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!current_user_can('view_azula_sms_reports'))throw new ControlledTestException('An authorized administrator POST and SMS report permission are required.');
    }
    public function execute(array $p):array
    {
        $this->authorize($p);
        $this->verified=$this->verifiedIdentity=[];
        return match($p['op']??''){
            'm9_target_save'=>$this->create($p), 'm9_target_report'=>$this->report($p),
            'm9_target_list'=>$this->listing($p), 'm9_target_detail'=>$this->detail($p),
            'm9_target_rename'=>$this->change($p,false), 'm9_target_remove'=>$this->change($p,true),
            default=>throw new ControlledTestException('Unknown SMS audience operation.')
        };
    }
    private function locked(callable $fn):array
    {
        $db=$this->s->database->db;$lock='azula_sms_targets_'.substr(hash('sha256',$db->prefix),0,32);
        if((string)$db->get_var($db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new ControlledTestException('Another SMS audience operation is in progress. Retry with the same request; do not create another audience.');
        try{return $this->s->database->transaction($fn);}finally{$db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private function name($name):string
    {
        if(!is_string($name)||preg_match('//u',$name)!==1||trim($name)===''||strlen(trim($name))>190||preg_match('/[\x00-\x1f\x7f]/',$name))throw new ControlledTestException('Enter an audience name of 1–190 UTF-8 bytes without control characters.');return trim($name);
    }
    private function definition(array $row):array
    {
        if(!hash_equals($row['fingerprint'],hash('sha256',$row['definition'])))throw new ControlledTestException('Saved SMS audience integrity check failed. No membership was inferred.');return json_decode($row['definition'],true,512,JSON_THROW_ON_ERROR);
    }
    private function criteria(array $d):array{return (new M9Retargeting($this->s))->definition(array_map(static fn($v)=>is_bool($v)?($v?'1':'0'):$v,$d));}
    private function row(int $id,bool $lock=false):array
    {
        if($id<=M9Source::OFFSET)throw new ControlledTestException('Only SMS-owned audiences can be managed here.');$row=$this->s->row('SELECT * FROM '.$this->s->table('target_segments').' WHERE id=%d'.($lock?' FOR UPDATE':''),$id-M9Source::OFFSET);if(!$row)throw new ControlledTestException('SMS audience not found.');$this->definition($row);return $row;
    }
    private function intent(string $name,string $mode,array $d,int $actor):string
    {
        unset($d['_sms_owned']);if(($d['range']??'30')!=='custom')unset($d['from'],$d['to']);return $this->s->crypto->digest(['owner'=>$actor,'name'=>$name,'mode'=>$mode,'criteria'=>$d]);
    }
    private function publicRow(array $r):array
    {
        return ['id'=>M9Source::OFFSET+(int)$r['id'],'name'=>$r['name'],'mode'=>str_replace('removed_','',$r['mode']),'archived'=>str_starts_with($r['mode'],'removed_'),'revision'=>hash('sha256',$r['id'].'|'.$r['name'].'|'.$r['mode'].'|'.$r['fingerprint'])];
    }
    public function create(array $p):array
    {
        $this->authorize($p);$name=$this->name($p['name']??null);$mode=$p['membership']??'';if(!in_array($mode,['snapshot','dynamic'],true))throw new ControlledTestException('Choose Snapshot or Dynamic membership.');
        $key=$p['request_key']??'';if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new ControlledTestException('A valid creation request key is required. Reload the audience page and retry.');
        $d=$this->criteria($p);$intent=$this->intent($name,$mode,$d,get_current_user_id());$request=$this->s->crypto->digest(['actor'=>get_current_user_id(),'request'=>$key]);
        return $this->locked(function()use($name,$mode,$d,$intent,$request){
            $saved=$this->s->rows('SELECT * FROM '.$this->s->table('target_segments').' ORDER BY id LIMIT 1001');if(count($saved)>self::MAX_SAVED)throw new ControlledTestException('Saved audience review limit exceeded. No partial creation was attempted.');
            foreach($saved as $old){$def=$this->definition($old);$meta=$def['_sms_owned']??[];$sameRequest=isset($meta['request'])&&hash_equals($meta['request'],$request);$sameIntent=(isset($meta['intent'])&&hash_equals($meta['intent'],$intent))||hash_equals($this->intent($old['name'],str_replace('removed_','',$old['mode']),$this->criteria($def),(int)$old['actor_id']),$intent);
                if($sameRequest&&!hash_equals($meta['intent'],$intent))throw new ControlledTestException('This request key belongs to different creation details. Start a new creation after reviewing the form.');
                if($sameRequest||$sameIntent){if(str_starts_with($old['mode'],'removed_'))throw new ControlledTestException('This audience was already removed. It was not recreated. Use a different name for an intentional new audience.');$receipt=isset($meta['receipt'])?$this->s->crypto->open($meta['receipt']):null;$summary=$receipt['summary']??$this->evaluate($old)['summary'];return $this->publicRow($old)+['count'=>$summary['eligible_customers'],'breakdown'=>$summary,'creation_receipt'=>$receipt!==null,'replayed'=>true];}
            }
            if(count($saved)>=self::MAX_SAVED)throw new ControlledTestException('The 1,000 saved-audience limit has been reached. No audience was created.');
            $review=$this->evaluate(['mode'=>'dynamic','definition'=>json_encode($d,JSON_THROW_ON_ERROR),'fingerprint'=>hash('sha256',json_encode($d,JSON_THROW_ON_ERROR))]);
            $refs=[];foreach($review['rows'] as $v)$refs[]=['campaign'=>$v['campaign_id'],'recipient'=>$v['recipient_id'],'reason'=>$v['exclusion_code']];
            $d['_sms_owned']=['v'=>1,'intent'=>$intent,'request'=>$request,'receipt'=>$this->s->crypto->seal(['summary'=>$review['summary'],'refs'=>$refs,'created_at'=>time()])];$encoded=json_encode($d,JSON_THROW_ON_ERROR);
            $id=$this->s->insert('target_segments',['name'=>$name,'mode'=>$mode,'definition'=>$encoded,'fingerprint'=>hash('sha256',$encoded),'actor_id'=>get_current_user_id(),'created_at'=>time()]);
            foreach($review['members'] as $ref)$this->s->insert('target_members',['segment_id'=>$id,'source_id'=>$ref['source_id'],'payload'=>$this->s->crypto->seal($ref)]);
            $this->s->audit(0,'sms_audience_created',['segment'=>$id,'count'=>count($review['members']),'mode'=>$mode]);
            return ['id'=>M9Source::OFFSET+$id,'name'=>$name,'mode'=>$mode,'count'=>count($review['members']),'breakdown'=>$review['summary'],'creation_receipt'=>true,'replayed'=>false];
        });
    }
    private function match(array $r,array $d):bool
    {
        $f=$r['matched_flags'];foreach(['clicked','product'] as $k)if($d[$k]&&!$f[$k])return false;
        if(!empty($d['not_cart'])&&(!$f['clicked']||$f['cart']||$f['checkout']||$f['purchased']||!empty($f['any_cart'])))return false;
        if($d['cart']&&($r['stage']!=='cart'||!$f['cart'])||$d['checkout']&&($r['stage']!=='checkout'||!$f['checkout'])||$d['purchased']&&!$f['purchased']||$d['not_purchased']&&($f['purchased']||$f['uncertain'])||$d['abandoned']&&$r['checkout_state']!=='potential')return false;
        foreach(['min_cart'=>'cart_minor','min_checkout'=>'checkout_minor'] as $key=>$field)if($d[$key]!==''&&($r[$field]===null||(int)$r[$field]<(int)round((float)$d[$key]*100)))return false;return true;
    }
    private function current(array $r,array $criteria=[]):?string
    {
        $key=$r['source_segment'].':'.$r['source_id'];if(SmsNotice::modern())$key.=':'.($r['recovery_since']??0).':'.hash('sha256',json_encode([$criteria,$r['purchase_order_ids']??[]]));if(array_key_exists($key,$this->verified))return $this->verified[$key];
        try{$segment=$this->source->segment((int)$r['source_segment']);$scan=$this->source->scan($segment,(int)$r['source_id']-1,(int)$r['source_id']);}catch(\Throwable $e){throw new ControlledTestException('Current Customer Segments membership could not be verified. No count was guessed. Retry after checking source availability.');}
        foreach($scan['members'] as $m)if((int)$m['customer_id']===(int)$r['source_id']){if(!empty($m['identity_warning']))return $this->verified[$key]='identity_unverified';$this->verifiedIdentity[$r['source_segment'].':'.$r['source_id']]=true;$purchase=SmsNotice::modern()?(!empty($criteria['purchased'])?RC18Retargeting::purchaser($m,$r['purchase_order_ids']??[]):(RC18Retargeting::negative($criteria)?M11Purchases::check($m,(int)($r['recovery_since']??0)):null)):(M11Environment::modern()&&in_array($r['stage'],['cart','checkout'],true)?M11Purchases::check($m,(int)($r['recovery_since']??0)):null);$phone=(new EgyptMobileNormalizer())->normalize((string)($m['phone']??''));return $this->verified[$key]=($phone['phone']??null)===$r['phone']?$purchase:'source_contact_changed';}
        return $this->verified[$key]='source_member_unavailable';
    }
    public static function reason(string $code):string
    {
        return ['activity_unknown'=>'Activity is UNKNOWN. A recorded click with no recorded cart is not proof of inactivity; automatic negative targeting is withheld.','identity_unverified'=>'Source customer identity/contact could not be verified; personal values are withheld.','source_unavailable'=>'No safe original Customer Segments record reference is available.','source_member_unavailable'=>'The source record no longer belongs to its original saved segment or is outside source coverage.','source_contact_changed'=>'The current identity-checked source phone no longer matches the captured contact. Refresh through a new campaign; no customer record was changed.','suppressed'=>'Explicit SMS suppression or opt-out is active.','purchase_unverified'=>'Cross-channel purchase or customer identity evidence is unavailable or ambiguous; recovery is withheld.','purchased'=>'The completed shopping journey is excluded from recovery targeting.','collection_disabled'=>'Optional tracking collection is disabled; recovery membership is withheld.','observing'=>'Waiting for the shopping inactivity deadline; current purchase and suppression checks also apply.','uncertain'=>'Shopping-session or checkout evidence is insufficient or ambiguous.','filter_mismatch'=>'This record does not meet every saved activity/value condition.','duplicate_source'=>'The same source customer is already represented by a newer campaign-recipient record.','not_in_snapshot'=>'This source customer was not in the fixed snapshot; it cannot be added later.','activity_unavailable'=>'The original activity is no longer available in this date scope or retained evidence.','newer_activity'=>'Newer shopping activity exists outside the fixed snapshot references; review it separately.'][$code]??'Eligible source customer; fresh phone validation, deduplication and suppression checks still apply.';
    }
    private function summary(array $rows):array
    {
        $identified=[];$eligible=[];$reasons=[];$withheld=0;foreach($rows as $r){if(($r['source_identified']??$r['identity_checked'])&&$r['source_id'])$identified[$r['source_id']]=true;if($r['eligible'])$eligible[$r['source_id']]=true;else{$withheld++;$reasons[$r['exclusion_code']]=($reasons[$r['exclusion_code']]??0)+1;}}
        $text=[];foreach($reasons as $code=>$n)$text[]=$n.' × '.self::reason($code);
        return ['tracked_records'=>count($rows),'unknown_records'=>count(array_filter($rows,static fn($r)=>($r['activity_status']??'observed')==='unknown')),'identified_customers'=>count($identified),'eligible_customers'=>count($eligible),'withheld_records'=>$withheld,'reasons'=>$reasons,'explanation'=>$eligible?'Fresh SMS validation is still required.':($text?implode(' ',$text):'No tracked recipient records match this campaign/date scope. No customers were inferred.')];
    }
    public function evaluate(array $saved,bool $verifySource=true,?int $sourceId=null):array
    {
        $started=hrtime(true);$d=$this->definition($saved);$meta=$d['_sms_owned']??[];$criteria=$this->criteria($d);$scope=$criteria;foreach(['clicked','product','cart','checkout','purchased','not_purchased','abandoned'] as $k)$scope[$k]='0';$scope['min_cart']=$scope['min_checkout']='';if(SmsNotice::modern())$scope['not_cart']='0';
        $rows=(new M9Retargeting($this->s))->all($scope);usort($rows,static fn($a,$b)=>[$b['last_activity'],$b['recipient_id']]<=>[$a['last_activity'],$a['recipient_id']]);$snapshot=$saved['mode']==='snapshot';$fixed=[];$receipt=null;
        if($snapshot){foreach($this->s->rows('SELECT * FROM '.$this->s->table('target_members').' WHERE segment_id=%d ORDER BY source_id LIMIT 10001',$saved['id']) as $v){$ref=$this->s->crypto->open($v['payload']);$fixed[(int)$v['source_id']]=$ref;}if(count($fixed)>10000)throw new ControlledTestException('Snapshot exceeds the safe review limit.');$receipt=isset($meta['receipt'])?$this->s->crypto->open($meta['receipt']):null;}
        $allowed=null;if($snapshot&&$receipt!==null){$allowed=[];foreach($receipt['refs'] as $ref)$allowed[$ref['campaign'].':'.$ref['recipient']]=true;}
        $out=[];$members=[];$seen=[];$byKey=[];$latest=[];foreach($rows as $r){$byKey[$r['campaign_id'].':'.$r['recipient_id']]=$r;if($r['source_id']&&!isset($latest[$r['source_id']]))$latest[$r['source_id']]=$r['campaign_id'].':'.$r['recipient_id'];}
        if($allowed!==null){$scoped=[];foreach($allowed as $key=>$unused)if(isset($byKey[$key]))$scoped[]=$byKey[$key];else{$refs=explode(':',$key);$scoped[]=['campaign_id'=>(int)$refs[0],'recipient_id'=>(int)$refs[1],'source_id'=>0,'source_segment'=>0,'name'=>'','phone'=>'','campaign'=>'Historical campaign #'.$refs[0],'last_activity'=>0,'stage'=>'unavailable','cart_minor'=>null,'checkout_minor'=>null,'purchase_minor'=>null,'checkout_state'=>'uncertain','reason'=>self::reason('activity_unavailable'),'eligible'=>false,'identity_checked'=>false,'sms_suppressed'=>false,'matched_flags'=>[],'unavailable'=>true];}$rows=$scoped;}
        foreach($rows as $r){if($sourceId!==null&&(int)$r['source_id']!==$sourceId)continue;if(hrtime(true)-$started>20000000000)throw new ControlledTestException('Identity review exceeded its safe request time. Narrow the campaign/date scope; no partial membership count was returned.');$codes=[];$r['source_identified']=false;$r['filter_match']=empty($r['unavailable'])&&$this->match($r,$criteria);
            if(!empty($r['unavailable']))$codes[]='activity_unavailable';elseif(!$r['identity_checked'])$codes[]='identity_unverified';elseif(!$r['source_id']||$r['source_segment']<1||$r['source_segment']>=M9Source::OFFSET)$codes[]='source_unavailable';elseif($verifySource){if($code=$this->current($r,$criteria)){$codes[]=$code;$r['identity_checked']=false;}$r['source_identified']=!empty($this->verifiedIdentity[$r['source_segment'].':'.$r['source_id']]);}else $r['source_identified']=true;
            if($r['sms_suppressed'])$codes[]='suppressed';if($r['stage']==='purchase'&&(!SmsNotice::modern()||RC18Retargeting::negative($criteria)))$codes[]='purchased';if(SmsNotice::modern()&&(($r['activity_status']??'unknown')==='unknown'||!empty($criteria['not_cart'])))$codes[]='activity_unknown';
            if(!M6Tracking::enabled()&&in_array($r['stage'],['cart','checkout'],true))$codes[]='collection_disabled';
            if((!SmsNotice::modern()||RC18Retargeting::negative($criteria))&&in_array($r['stage'],['cart','checkout'],true)&&$r['checkout_state']!=='potential')$codes[]=$r['checkout_state']==='observing'?'observing':'uncertain';elseif(!empty($r['matched_flags']['uncertain'])&&(!SmsNotice::modern()||RC18Retargeting::negative($criteria)))$codes[]='uncertain';
            if(!$r['filter_match'])$codes[]='filter_mismatch';
            if($snapshot&&$r['source_id']&&!isset($fixed[$r['source_id']]))$codes[]='not_in_snapshot';
            if($snapshot&&$allowed!==null&&$r['source_id']&&isset($latest[$r['source_id']])&&!isset($allowed[$latest[$r['source_id']]]))$codes[]='newer_activity';
            if($r['source_id']){if(isset($seen[$r['source_id']]))$codes[]='duplicate_source';$seen[$r['source_id']]=true;}
            if(!$r['eligible']&&!$codes)$codes[]='uncertain';$codes=array_values(array_unique($codes));$r['eligible']=!$codes;$r['exclusion_code']=$codes[0]??'eligible';$r['exclusion_reasons']=array_map([self::class,'reason'],$codes);$r['reason']=$codes?implode(' ',$r['exclusion_reasons']).' '.$r['reason']:self::reason('eligible');if($codes&&!empty($r['evidence_details']))$r['reason'].=' '.implode(' ',$r['evidence_details']);
            if(!$r['identity_checked']){$r['name']='';$r['phone']='';}
            if($r['eligible'])$members[(int)$r['source_id']]=['source_id'=>(int)$r['source_id'],'source_segment'=>(int)$r['source_segment'],'campaign'=>(int)$r['campaign_id'],'recipient'=>(int)$r['recipient_id']];$out[]=$r;
        }
        return ['rows'=>$out,'members'=>$members,'summary'=>$this->summary($out),'creation_summary'=>$receipt['summary']??null,'snapshot_evidence'=>$snapshot?($receipt?'Captured included and excluded references; current safety is rechecked.':'Legacy snapshot: only included references were stored. Original exclusion totals are unavailable.'):'Dynamic membership is recomputed from current evidence.'];
    }
    public function membership(array $saved,bool $verifySource=true,?int $sourceId=null):array{return $this->evaluate($saved,$verifySource,$sourceId)['members'];}
    public function reviewRows(array $p):array
    {
        $this->authorize($p);$d=$this->criteria($p);$encoded=json_encode($d,JSON_THROW_ON_ERROR);$r=$this->evaluate(['mode'=>'dynamic','definition'=>$encoded,'fingerprint'=>hash('sha256',$encoded)]);return (new M9Retargeting($this->s))->filtered(array_values(array_filter($r['rows'],static fn($v)=>$v['filter_match'])),$p);
    }
    private function report(array $p):array
    {
        $d=(new M9Retargeting($this->s))->definition($p);$encoded=json_encode($d,JSON_THROW_ON_ERROR);$r=$this->evaluate(['mode'=>'dynamic','definition'=>$encoded,'fingerprint'=>hash('sha256',$encoded)]);$matched=array_values(array_filter($r['rows'],static fn($v)=>$v['filter_match']));$rows=(new M9Retargeting($this->s))->filtered($matched,$p);$page=M9Policy::integer((string)($p['page']??'1'),1,10000);
        $summary=$this->summary($matched);$summary['scope_records']=count($r['rows']);$summary['scope_unknown_records']=count(array_filter($r['rows'],static fn($v)=>($v['activity_status']??'observed')==='unknown'));if(!$matched&&$r['rows'])$summary['explanation']=count($r['rows']).' tracked records exist in this campaign/date scope, but none match every selected activity/value condition. Review the filters; no identities or eligibility were inferred.';
        return ['definition'=>$d,'total'=>count($rows),'page'=>$page,'more'=>count($rows)>$page*50,'rows'=>array_slice($rows,($page-1)*50,50),'breakdown'=>$summary,'identity'=>'Tracked campaign-recipient associations, not verified people. Identity-checked source records and eligible membership are separate counts. No email-subscription filter is added.'];
    }
    private function listing(array $p):array
    {
        $page=M9Policy::integer((string)($p['page']??'1'),1,1000);$rows=$this->s->rows('SELECT * FROM '.$this->s->table('target_segments')." WHERE mode IN ('dynamic','snapshot') ORDER BY id DESC LIMIT 11 OFFSET %d",($page-1)*10);$more=count($rows)>10;$out=[];
        foreach(array_slice($rows,0,10) as $r){try{$review=$this->evaluate($r);$out[]=$this->publicRow($r)+['count'=>count($review['members']),'breakdown'=>$review['summary'],'explanation'=>$review['summary']['explanation']];}catch(\Throwable $e){$out[]=$this->publicRow($r)+['count'=>null,'explanation'=>'Membership unavailable: '.$e->getMessage()];}}
        return ['rows'=>$out,'page'=>$page,'more'=>$more];
    }
    private function detail(array $p):array
    {
        $saved=$this->row(M9Policy::integer((string)($p['segment']??'0'),M9Source::OFFSET+1,PHP_INT_MAX));if(str_starts_with($saved['mode'],'removed_'))throw new ControlledTestException('This audience was removed from selection; its definition and history remain retained.');$review=$this->evaluate($saved);$tab=$p['tab']??'all';if(!in_array($tab,['all','included','excluded'],true))throw new ControlledTestException('Choose All, Included or Excluded.');$rows=array_values(array_filter($review['rows'],static fn($r)=>$tab==='all'||($tab==='included'?$r['eligible']:!$r['eligible'])));$page=M9Policy::integer((string)($p['page']??'1'),1,10000);
        return ['segment'=>$this->publicRow($saved),'breakdown'=>$review['summary'],'creation_breakdown'=>$review['creation_summary'],'evidence'=>$review['snapshot_evidence'],'total'=>count($rows),'page'=>$page,'more'=>count($rows)>$page*50,'rows'=>array_slice($rows,($page-1)*50,50)];
    }
    private function change(array $p,bool $remove):array
    {
        $id=M9Policy::integer((string)($p['segment']??'0'),M9Source::OFFSET+1,PHP_INT_MAX);$new=$remove?'':$this->name($p['name']??null);
        if($remove&&($p['confirm']??'')!=='REMOVE SMS AUDIENCE')throw new ControlledTestException('Explicit removal confirmation is required. No audience was changed.');
        return $this->locked(function()use($p,$id,$new,$remove){$row=$this->row($id,true);$public=$this->publicRow($row);if(!is_string($p['revision']??null)||!hash_equals($public['revision'],$p['revision']))throw new ControlledTestException('This audience changed in another window. Refresh the saved-audience list before retrying.');
            if($public['archived'])throw new ControlledTestException('This audience has already been removed.');
            // Current locking reads serialize with the existing campaign/worker row locks.
            // Do not check child execution state through an older repeatable-read snapshot.
            if($remove){$refs=$this->s->rows('SELECT c.id,c.state,c.transmission_intent FROM '.$this->s->table('campaigns').' c WHERE c.segment_id=%d OR EXISTS (SELECT a.id FROM '.$this->s->table('audiences').' a WHERE a.campaign_id=c.id AND a.segment_id=%d) LIMIT 1001 FOR UPDATE',$id,$id);if(count($refs)>1000)throw new ControlledTestException('Too many historical references to verify safely. No audience was removed.');
                foreach($refs as $c){$schedule=$this->s->row('SELECT state FROM '.$this->s->table('schedules').' WHERE campaign_id=%d FOR UPDATE',$c['id']);$plan=$this->s->latestPlan((int)$c['id'],true);$unknown=$this->s->row('SELECT id FROM '.$this->s->table('attempts')." WHERE campaign_id=%d AND state IN ('unknown','submitting','claimed') LIMIT 1 FOR UPDATE",$c['id']);$preparing=$this->s->row('SELECT id FROM '.$this->s->table('audiences')." WHERE campaign_id=%d AND state='preparing' LIMIT 1 FOR UPDATE",$c['id']);
                    $activePlan=$this->s->row('SELECT id FROM '.$this->s->table('sendplans')." WHERE campaign_id=%d AND state NOT IN ('m9_complete','m9_cancelled','submitted','canceled','invalidated') LIMIT 1 FOR UPDATE",$c['id']);$activeRun=$this->s->row('SELECT r.id FROM '.$this->s->table('bulk_runs').' r INNER JOIN '.$this->s->table('sendplans')." p ON p.id=r.plan_id WHERE p.campaign_id=%d AND r.state NOT IN ('complete','cancelled') LIMIT 1 FOR UPDATE",$c['id']);
                    $terminal=$plan?in_array($plan['state'],['m9_complete','m9_cancelled','submitted','canceled','invalidated'],true):in_array($c['state'],['canceled','trashed'],true);
                    if(!$terminal||$activePlan||$activeRun||$unknown||$preparing||($schedule&&!in_array($schedule['state'],['canceled','missed','completed'],true)))throw new ControlledTestException('Campaign #'.$c['id'].' still uses this audience or has unresolved/scheduled execution. Finish or cancel it explicitly before removal. Frozen recipient lists were not changed.');
                }
                $this->s->update('target_segments',['mode'=>'removed_'.$row['mode']],['id'=>$row['id']]);$this->s->audit(0,'sms_audience_removed',['segment'=>$row['id'],'historical_references'=>count($refs)]);
            }else{$this->s->update('target_segments',['name'=>$new],['id'=>$row['id']]);$this->s->audit(0,'sms_audience_renamed',['segment'=>$row['id']]);}
            return ['segment'=>$this->publicRow($this->row($id)),'message'=>$remove?'Removed from selection. Original definition, members and campaign history are retained.':'Audience renamed. Its ID, rules and frozen campaign recipients are unchanged.'];
        });
    }
}
