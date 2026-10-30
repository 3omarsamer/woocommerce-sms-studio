<?php
namespace Azula\SmsStudio;

final class M6Audience
{
    public function __construct(private M6Store $s,private AudienceSource $source){}
    public function snapshot(int $segment,int $preferred=0):array
    {
        $definition=$this->source->segment($segment);$catalog=$this->source->catalog(max(0,$segment-1),1);$original=null;foreach($catalog as $entry)if((int)$entry['id']===$segment)$original=$entry['customer_count']??null;
        $a=$this->s->table('audiences');$c=$this->s->table('campaigns');$m=$this->s->table('campaign_meta');$row=null;
        if($preferred)$row=$this->s->row("SELECT a.* FROM $a a INNER JOIN $c c ON c.audience_id=a.id LEFT JOIN $m m ON m.campaign_id=c.id WHERE c.id=%d AND a.segment_id=%d AND a.rules_hash=%s AND a.normalizer_version=%d AND a.review_version=1 AND COALESCE(m.trashed_at,0)=0",$preferred,$segment,$definition['fingerprint'],EgyptMobileNormalizer::VERSION);
        if(!$row)$row=$this->s->row("SELECT a.* FROM $a a INNER JOIN $c c ON c.audience_id=a.id LEFT JOIN $m m ON m.campaign_id=c.id WHERE a.segment_id=%d AND a.rules_hash=%s AND a.normalizer_version=%d AND a.review_version=1 AND COALESCE(m.trashed_at,0)=0 ORDER BY a.id DESC LIMIT 1",$segment,$definition['fingerprint'],EgyptMobileNormalizer::VERSION);
        return ['segment'=>['id'=>$segment,'name'=>$definition['name'],'customer_count'=>$original],'snapshot'=>$row?M2Api::status($this->s->campaigns->m2,(int)$row['campaign_id']):null,'sending_audience_reused'=>false];
    }
    public function prepare(int $segment,string $token):array
    {
        $definition=$this->source->segment($segment);$id=$this->s->campaigns->m2->draft($token,$segment,'Audience review · '.$definition['name']);$this->s->meta($id,true);$this->s->update('campaign_meta',['purpose'=>'audience_review','updated_at'=>time()],['campaign_id'=>$id]);
        $r=$this->s->campaigns->m2->lock(-$id,function()use($id){$c=$this->s->campaign($id);if($c['audience_id'])return $this->s->campaigns->m2->store()->audience((int)$c['audience_id']);$a=$this->s->campaigns->m2->preparation($this->source)->begin($id,time());$this->s->campaigns->m2->updateAudience((int)$a['id'],['normalizer_version'=>EgyptMobileNormalizer::VERSION,'review_version'=>1,'last_progress_at'=>time()]);return $a;});if(!$r)throw new ControlledTestException('Audience preparation is busy.');try{M2Scheduler::enqueue($r);M2Scheduler::ensure();}catch(\Throwable $e){}return $this->snapshot($segment,$id);
    }
}
