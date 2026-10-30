<?php
namespace Azula\SmsStudio;

final class M6Timing
{
    public function __construct(private M6Store $s){}
    public function save(int $id,int $revision,string $mode,string $local='',string $offset=''):void
    {
        $result=$this->s->campaigns->m2->lock(-$id,fn()=>$this->s->database->transaction(function()use($id,$revision,$mode,$local,$offset){$c=$this->s->campaign($id,true);if($c['transmission_intent']||CampaignLifecycle::cancelled($this->s->database,$c)||(int)$c['revision']!==$revision)throw new ControlledTestException('Submitted campaigns are frozen; otherwise reload the newest revision before scheduling.');$previous=$this->s->campaigns->get($id);if($mode==='now'&&!$c['start_at']&&(!$previous||in_array($previous['state'],['canceled','missed'],true)))return true;if($mode==='later'&&$previous&&!in_array($previous['state'],['canceled','missed','paused'],true)&&(int)$previous['start_at']===CairoTime::resolve($local,$offset))return true;
            $this->s->invalidate($id,'timing_changed');if($mode==='later')$this->s->campaigns->plan($id,$revision,$local,$offset,time());elseif($mode==='now'){$this->s->campaigns->invalidate($id,'send_now_selected');$this->s->update('schedules',['state'=>'canceled','next_check'=>0,'approval_hash'=>null,'approval_payload'=>null],['campaign_id'=>$id]);$this->s->update('campaigns',['revision'=>$revision+1,'start_at'=>null,'timezone'=>CairoTime::ZONE,'review_hash'=>null,'approval_hash'=>null,'state'=>'review_ready'],['id'=>$id]);$this->s->insert('revisions',['campaign_id'=>$id,'revision'=>$revision+1,'binding_json'=>json_encode(['mode'=>'now','operational'=>false]),'created_at'=>time()]);}else throw new ControlledTestException('Select Send now or Schedule for later.');$this->s->audit($id,'campaign_timing_saved',['revision'=>$revision+1,'result'=>$mode]);return true;}));if(!$result)throw new ControlledTestException('Campaign is busy.');
    }
}
