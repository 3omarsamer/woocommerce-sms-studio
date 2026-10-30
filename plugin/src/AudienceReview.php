<?php
namespace Azula\SmsStudio;

/** Read only: decrypt captured SMS evidence, never query current customer records. */
final class AudienceReview
{
    public const LIMIT = 25000;
    public const CATEGORIES = ['all','ready','excluded','missing_phone','invalid_phone','duplicate','suppressed','identity_unverified'];
    public function __construct(private Database $database,private Crypto $crypto){}
    public static function spec(array $input): array
    {
        $category=$input['category']??'all';$sort=$input['sort']??'captured';$direction=$input['direction']??'asc';$q=$input['search']??'';$page=$input['page']??'1';$reason=$input['reason']??'';
        if(!is_string($reason)||strlen($reason)>512||preg_match('//u',$reason)!==1||preg_match('/[\x00-\x1F\x7F]/',$reason))throw new \InvalidArgumentException('Invalid reason filter.');
        if(!is_string($category)||!in_array($category,self::CATEGORIES,true)||!is_string($sort)||!in_array($sort,['captured','name','phone','classification','reason'],true)||!in_array($direction,['asc','desc'],true)||!is_string($q)||strlen($q)>200||preg_match('//u',$q)!==1||preg_match('/[\x00-\x1F\x7F]/',$q)||!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page))throw new \InvalidArgumentException('Invalid audience filter.');
        return compact('category','sort','direction','q','reason')+['page'=>(int)$page];
    }
    /** Make control characters visible, without changing the stored source value. */
    public static function visible(string $value): string
    {
        return preg_replace_callback('/[\x00-\x1F\x7F\x{0085}\x{061C}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u',static fn($m)=>json_encode($m[0],JSON_UNESCAPED_SLASHES),$value);
    }
    private function rows(string $name,int $audience): \Generator
    {
        $db=$this->database->db;$table=$this->database->table($name);$after=0;$seen=0;$bytes=0;
        do{
            $rows=$this->database->check($db->get_results($db->prepare("SELECT * FROM $table WHERE audience_id=%d AND id>%d ORDER BY id ASC LIMIT 250",$audience,$after),ARRAY_A));
            foreach($rows as $row){
                if((int)$row['id']<=$after)throw new \RuntimeException('Nonmonotonic review rows.');
                $after=(int)$row['id'];$seen++;$bytes+=strlen($row['payload']);
                if($seen>self::LIMIT || $bytes>16*1024*1024)throw new \LengthException('Review exceeds memory bound.');
                $payload=$this->crypto->open($row['payload']);
                if(!hash_equals($row['payload_hash'],$this->crypto->digest($payload)))throw new \RuntimeException('Review evidence mismatch.');
                yield [$row,$payload];
            }
        }while(count($rows)===250);
    }
    public function query(int $campaign,int $audience,array $input,bool $export=false): array
    {
        $spec=self::spec($input);$store=new WordPressStore($this->database);$c=$store->campaign($campaign);$a=$store->audience($audience);
        if((int)$a['campaign_id']!==$campaign || (int)$c['audience_id']!==$audience || $a['state']==='purged' || ($export && ($a['state']!=='complete'||!$a['manifest_hash'])))throw new \DomainException('Select the current completed audience for export.');
        $expected=json_decode($a['counts_json'],true,512,JSON_THROW_ON_ERROR);
        if($expected['captured']>self::LIMIT)throw new \LengthException('Review exceeds record bound.');
        $ready=[];
        foreach($this->rows('recipients',$audience) as [$r,$p]){
            if(!hash_equals($r['phone_hash'],$this->crypto->correlate($p['phone'])))throw new \RuntimeException('Recipient correlation mismatch.');
            $ready[$r['phone_hash']]=['cursor'=>(int)$r['id'],'name'=>$p['first_name']?:'Neutral greeting','phone'=>$p['canonical_e164']??$p['phone'],'classification'=>'prepared','reason'=>'Identity checked; one unique normalized recipient','retained_name'=>'','retained_phone'=>''];
        }
        $members=[];$actual=AudiencePreparationService::COUNTS;
        foreach($this->rows('members',$audience) as [$r,$p]){
            $classification=$r['disposition'];if(!array_key_exists($classification,$actual)||$classification==='captured')throw new \RuntimeException('Unknown classification.');
            $actual['captured']++;$actual[$classification]++;
            $unverified=$classification==='identity_unverified';
            $phone=$classification==='missing_phone'?'Missing':($p['original_phone']??($p['phone']?:'Unavailable in historical capture'));
            $retained=$classification==='duplicate'?($ready[$r['phone_hash']]??null):null;
            if($classification==='duplicate' && $retained===null && $a['state']==='complete')throw new \RuntimeException('Retained duplicate reference unavailable.');
            $members[]=['cursor'=>(int)$r['id'],'name'=>$unverified?'Identity withheld':($p['first_name']?:'Not supplied'),'phone'=>$unverified?'Withheld':self::visible($phone?:'Unavailable'),
                'classification'=>$classification,'reason'=>$unverified?'Identity verification failed; personal values withheld':self::visible($p['exclusion_detail']??($p['validation_reason']??'Specific evidence unavailable in historical capture')),
                'retained_name'=>$unverified?'':($retained['name']??''),'retained_phone'=>$unverified?'':($retained['phone']??'')];
        }
        if($a['state']==='complete' && ($actual!==$expected || count($ready)!==(int)$expected['prepared']))throw new \RuntimeException('Completed audience counts no longer match stored evidence.');
        $rows=$spec['category']==='ready'?array_values($ready):$members;
        $rows=array_values(array_filter($rows,static function($r)use($spec):bool{
            $class=$r['classification'];
            if($spec['category']==='excluded' && $class==='prepared')return false;
            if(!in_array($spec['category'],['all','ready','excluded'],true) && $class!==$spec['category'])return false;
            if($spec['q']==='')return true;
            $pattern='/'.preg_quote($spec['q'],'/').'/iu';
            if(preg_match($pattern,$r['name'])||preg_match($pattern,$r['phone']))return true;
            if($class==='identity_unverified')return false;
            if(preg_match('/^[0-9+ ().\-]+$/D',$spec['q'])){
                $digits=preg_replace('/[^0-9]/','',$spec['q']);
                return $digits!=='' && str_contains(preg_replace('/[^0-9]/','',$r['phone']),$digits);
            }
            return false;
        }));
        $reasons=array_values(array_unique(array_column($rows,'reason')));sort($reasons,SORT_NATURAL|SORT_FLAG_CASE);
        if($spec['reason']!=='')$rows=array_values(array_filter($rows,static fn($r)=>$r['reason']===$spec['reason']));
        $field=['captured'=>'cursor','name'=>'name','phone'=>'phone','classification'=>'classification','reason'=>'reason'][$spec['sort']];
        usort($rows,static function($x,$y)use($field,$spec):int{
            $compare=$field==='cursor'?($x[$field]<=>$y[$field]):strnatcasecmp($x[$field],$y[$field]);
            $compare=$compare?:($x['cursor']<=>$y['cursor']);return $spec['direction']==='desc'?-$compare:$compare;
        });
        $total=count($rows);$pages=max(1,(int)ceil($total/50));$page=min($spec['page'],$pages);
        // Recheck after decryption: a refresh/purge must not silently rebind this request.
        $current=$store->campaign($campaign);$end=$store->audience($audience);
        if((int)$current['audience_id']!==$audience || $end['state']!==$a['state'] || $end['manifest_hash']!==$a['manifest_hash'])throw new \DomainException('Audience changed during review.');
        return ['rows'=>$export?$rows:array_slice($rows,($page-1)*50,50),'total'=>$total,'page'=>$page,'pages'=>$pages,'more'=>$page<$pages,'next'=>($page)*50,
            'reason_options'=>$reasons,'category'=>$spec['category'],'complete'=>$a['state']==='complete','campaign'=>$campaign,'audience'=>$audience,'generation'=>(int)$a['generation'],'normalizer_version'=>(int)$a['normalizer_version'],'manifest'=>$a['manifest_hash']];
    }
}
