<?php
namespace Azula\SmsStudio;

/** Immutable allowlisted redirects; no external shortener, recipient tokens or PII in public URLs. */
final class M6Links
{
    public function __construct(private M6Store $s){}
    public static function allowed(string $url):bool
    {
        if(strlen($url)>2000||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||preg_match('/%(?:00|0a|0d)/i',$url))return false;
        $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['port']))return false;
        if(!in_array(strtolower($p['host']??''),['azulasportswear.com','www.azulasportswear.com','preview.azulasportswear.com'],true))return false;
        foreach(explode('&',$p['query']??'') as $part){$key=rawurldecode(explode('=',$part,2)[0]);if(preg_match('/(?:password|token|secret|email|phone|customer|signature|^sig$|x-amz)/i',$key))return false;}
        return true;
    }
    public static function destination(string $url,string $campaign,string $link):string
    {
        if(!self::allowed($url))throw new ControlledTestException('Tracking supports only approved HTTPS store URLs without credentials, sensitive parameters or signed query strings.');
        $parts=explode('#',$url,2);$fragment=count($parts)===2?'#'.$parts[1]:'';$query=explode('?',$parts[0],2);$keep=[];
        foreach(explode('&',$query[1]??'') as $value)if($value!==''&&!in_array(rawurldecode(explode('=',$value,2)[0]),['utm_source','utm_medium','utm_campaign','utm_id','utm_content'],true))$keep[]=$value;
        $keep[]='utm_source=sms';$keep[]='utm_medium=sms';$keep[]='utm_campaign=azs_'.$campaign;$keep[]='utm_id=azs_'.$campaign;$keep[]='utm_content='.$link;
        return $query[0].'?'.implode('&',$keep).$fragment;
    }
    public static function publicUrl(string $token):string{return M7Settings::modern()?M7Links::url($token):rtrim(home_url('/'),'/').'/?azula_sms_link='.$token;}
    public function expand(int $campaign,string $text):string
    {
        if(M7Settings::modern())$text=preg_replace_callback('~https://[^\s<>"\x27]+~u',function($m)use($campaign){$u=rtrim($m[0],'.,!;');$token=M7Links::token($u);if(!$token)return $m[0];$row=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE public_token=%s AND campaign_id=%d',$token,$campaign);if(!$row||!hash_equals($row['public_token'],$token))throw new ControlledTestException('Unknown campaign short link.');return $this->s->crypto->open($row['destination'])['original'].substr($m[0],strlen($u));},$text);
        $count=0;$result=preg_replace_callback('~'.preg_quote(rtrim(home_url('/'),'/').'/?azula_sms_link=','~').'([a-f0-9]{32})(?![a-f0-9])~',function($m)use($campaign,&$count){
            if(++$count>20)throw new ControlledTestException('Use at most twenty tracking links per message.');$row=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE public_token=%s',$m[1]);if(!$row||(int)$row['campaign_id']!==$campaign)throw new ControlledTestException('Tracking evidence belongs to another campaign. Use Duplicate to create fresh campaign links.');return $this->s->crypto->open($row['destination'])['original'];
        },$text);if(!is_string($result))throw new ControlledTestException('Message encoding is unavailable.');return $result;
    }
    public function rewrite(int $campaign,string $message):array
    {
        $meta=$this->s->meta($campaign,true);$links=[];$count=0;if(M7Settings::modern())$message=$this->expand($campaign,$message);
        $text=preg_replace_callback('~https?://[^\s<>"\x27]+~u',function($m)use($campaign,$meta,&$links,&$count){
            $candidate=$m[0];$suffix='';while($candidate!==''&&str_contains('.,!;',substr($candidate,-1))){$suffix=substr($candidate,-1).$suffix;$candidate=substr($candidate,0,-1);}
            if(preg_match('/[?&]azula_sms_link=([a-f0-9]{32})(?:$|[&#])/',$candidate,$existing)){$row=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE public_token=%s',$existing[1]);if(!$row||(int)$row['campaign_id']!==$campaign)throw new ControlledTestException('A tracking URL belongs to another campaign. Duplicate through the campaign action to create fresh tracking.');$links[]=['url'=>$candidate,'destination'=>$this->s->crypto->open($row['destination'])['target']];return $m[0];}
            if(!self::allowed($candidate))return $m[0];if(M7Settings::modern()&&!M7Links::allowed($candidate))throw new ControlledTestException('Use approved store destinations in this environment; short-link loops and cross-environment destinations are blocked.');if(++$count>20)throw new ControlledTestException('Use at most 20 store links per campaign message.');
            $hash=$this->s->crypto->digest(['destination'=>$candidate]);$row=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE campaign_id=%d AND destination_hash=%s',$campaign,$hash);
            if(!$row){$token=M8Tokens::modern()?M8Tokens::allocate($this->s):bin2hex(random_bytes(16));$target=self::destination($candidate,$meta['public_id'],$token);$this->s->insert('links',['campaign_id'=>$campaign,'public_token'=>$token,'destination'=>$this->s->crypto->seal(['original'=>$candidate,'target'=>$target]),'destination_hash'=>$hash,'created_at'=>time()]);$row=['public_token'=>$token];}else $target=$this->s->crypto->open($row['destination'])['target'];
            $tracked=self::publicUrl($row['public_token']);$links[]=['url'=>$tracked,'destination'=>$target];return $tracked.$suffix;
        },$message);if(!is_string($text))throw new ControlledTestException('Invalid message encoding.');return ['message'=>$text,'links'=>$links];
    }
    public function save(int $id,int $revision,string $message,bool $tracking):array
    {
        $result=$this->s->campaigns->m2->lock(-$id,fn()=>$this->s->database->transaction(function()use($id,$revision,$message,$tracking){$c=$this->s->campaign($id,true);if($c['transmission_intent'])throw new ControlledTestException('This campaign is frozen by submission evidence.');
            if(AuthorizationBalance::modern()&&((int)$c['revision']!==$revision||$c['state']==='trashed'||CampaignLifecycle::cancelled($this->s->database,$c)))throw new ControlledTestException('Campaign revision changed or this campaign is cancelled. Reload the campaign; cancelled campaigns cannot be reactivated.');
            $final=$tracking?$this->rewrite($id,$message):['message'=>$this->expand($id,$message),'links'=>[]];
            if(AuthorizationBalance::modern()&&$final['message']===$this->s->campaigns->m2->message($c))return $final;
            $this->s->invalidate($id,'message_or_tracking_changed');$this->s->campaigns->m2->saveMessage($id,$final['message'],$revision);$this->s->audit($id,'message_edited',['revision'=>$revision+1,'tracking_links'=>count($final['links'])]);return $final;}));if(!$result)throw new ControlledTestException('Campaign is busy.');return $result;
    }
}
