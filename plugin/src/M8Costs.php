<?php
namespace Azula\SmsStudio;
/** Existing saved evidence only. No provider reporting endpoint is assumed. */
final class M8Costs
{
    public function __construct(private M6Store $s){}
    public static function numeric($value):?string{return (is_string($value)||is_int($value))&&preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D',(string)$value)?(string)$value:null;}
    public function evidence(array $r):?string
    {
        $cost=self::numeric($r['cost']??null);if($cost!==null)return $cost;
        try{$v=$this->s->crypto->open($r['receipt']??'');if(($r['state']??'')!=='accepted'||($r['provider_code']??'')!=='1901'||($v['accepted']??false)!==true||($v['code']??'')!=='1901'||!is_string($r['smsid']??null)||!hash_equals($r['smsid'],(string)($v['smsid']??'')))return null;return self::numeric($v['cost']??null);}catch(\Throwable $e){return null;}
    }
    public function total(array $rows):array
    {
        if(count($rows)>10000)return ['reported'=>null,'known_reported'=>null,'missing'=>null,'reason'=>'Bounded cost report limit exceeded.'];$sum='0';$missing=0;foreach($rows as $r){$v=$this->evidence($r);if($v===null)$missing++;else $sum=ExactPoints::add($sum,$v);}
        return ['reported'=>$missing?null:$sum,'known_reported'=>$rows?$sum:null,'missing'=>$missing,'reason'=>$missing?'Not reported by provider for every attempt; known subtotal is incomplete.':'Verified stored provider units; no EGP conversion.'];
    }
    public function reconcile(int $after=0):array
    {
        $rows=$this->s->rows('SELECT * FROM '.$this->s->table('attempts').' WHERE id>%d AND cost IS NULL ORDER BY id LIMIT 101',$after);$more=count($rows)>100;$rows=array_slice($rows,0,100);$changed=0;
        foreach($rows as $r){$after=(int)$r['id'];$value=$this->evidence($r);if($value!==null){$this->s->query('UPDATE '.$this->s->table('attempts').' SET cost=%s WHERE id=%d AND cost IS NULL',$value,$r['id']);$changed++;}}
        return ['checked'=>count($rows),'updated'=>$changed,'after'=>$after,'more'=>$more,'message'=>'Only authenticated saved accepted-response evidence was used. No provider request; unavailable costs remain NULL.'];
    }
}
