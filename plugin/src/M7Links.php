<?php
namespace Azula\SmsStudio;

/** 128-bit random bearer tokens. Recipient association is never human identity. */
final class M7Links
{
    public function __construct(private M6Store $s){}
    public static function url(string $token):string{$path=M7Settings::config()['path'];if(M7Settings::conflict($path))throw new ControlledTestException('Short-link path conflicts with an existing WordPress route. Choose another path in Settings & Health before creating links.');return M7Settings::domain().'/'.$path.'/'.$token;}
    public static function token(string $url):?string
    {
        $base=preg_quote(M7Settings::domain().'/'.M7Settings::config()['path'].'/','~');return preg_match('~^'.$base.'('.M8Tokens::pattern().')$~D',$url,$m)?$m[1]:null;
    }
    public static function allowed(string $url):bool
    {
        if(!M6Links::allowed($url))return false;$p=parse_url($url);$host=strtolower($p['host']);$domain=parse_url(M7Settings::domain(),PHP_URL_HOST);if($host!==$domain&&!($domain==='azulasportswear.com'&&$host==='www.azulasportswear.com'))return false;
        $path=rawurldecode($p['path']??'/');if(preg_match('~^/(?:'.preg_quote(M7Settings::config()['path'],'~').'|s)(?:/|$)~i',$path)||preg_match('/(?:^|&)azula_sms_link=/i',$p['query']??''))return false;
        return !preg_match('/%(?:25)*(?:2f|5c|0[0-9a-f]|7f)/i',$url)&&!str_contains($path,'..');
    }
    public function render(array $c,int $recipient,string $message):string
    {
        $r=$this->s->row('SELECT id FROM '.$this->s->table('recipients').' WHERE id=%d AND campaign_id=%d AND audience_id=%d',$recipient,$c['id'],$c['audience_id']);if(!$r)throw new ControlledTestException('Recipient does not belong to the captured campaign audience.');
        $n=0;return preg_replace_callback('~https://[^\s<>"\x27]+~u',function($m)use($c,$recipient,&$n){$url=rtrim($m[0],'.,!;');$suffix=substr($m[0],strlen($url));$token=self::token($url);if(!$token)return $m[0];if(++$n>20)throw new ControlledTestException('At most twenty tracked links.');
            $base=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE public_token=%s AND campaign_id=%d',$token,$c['id']);if(!$base||!hash_equals($base['public_token'],$token))throw new ControlledTestException('Short-link template is not bound to this campaign.');
            $row=$this->s->row('SELECT * FROM '.$this->s->table('recipient_links').' WHERE link_id=%d AND audience_id=%d AND recipient_id=%d ORDER BY id DESC LIMIT 1',$base['id'],$c['audience_id'],$recipient);
            if(!$row){$domain=M7Settings::domain();$route=M7Settings::config()['path'];for($i=0;$i<4;$i++){$opaque=M8Tokens::modern()?M8Tokens::allocate($this->s):bin2hex(random_bytes(16));if(!$this->s->row('SELECT id FROM '.$this->s->table('recipient_links').' WHERE public_token=%s',$opaque)&&!$this->s->row('SELECT id FROM '.$this->s->table('links').' WHERE public_token=%s',$opaque))break;if($i===3)throw new ControlledTestException('Token allocation collision.');}
                $this->s->insert('recipient_links',['link_id'=>$base['id'],'campaign_id'=>$c['id'],'revision'=>$c['revision'],'audience_id'=>$c['audience_id'],'recipient_id'=>$recipient,'public_token'=>$opaque,'domain'=>$domain,'route'=>$route,'created_at'=>time(),'expires_at'=>time()+90*86400,'state'=>'prepared']);$row=['public_token'=>$opaque,'domain'=>$domain,'route'=>$route];}
            if(isset($row['id'])&&$row['state']==='prepared'&&(int)$row['revision']!==(int)$c['revision'])$this->s->update('recipient_links',['revision'=>$c['revision']],['id'=>$row['id']]);
            return $row['domain'].'/'.$row['route'].'/'.$row['public_token'].$suffix;
        },$message);
    }
    public static function has(string $message):bool{return preg_match('~https://[^\s]+/'.preg_quote(M7Settings::config()['path'],'~').'/'.M8Tokens::pattern().'~',$message)===1;}
    public function resolve(string $token):array
    {
        if(RC1Status::modern()&&strlen($token)===10)RC1LinkGuard::check($this->s);if(!M8Tokens::valid($token))throw new ControlledTestException('Link unavailable.');$m=$this->s->row('SELECT * FROM '.$this->s->table('recipient_links').' WHERE public_token=%s',$token);if(!$m){
            // Composer templates are safe public previews, not recipient click evidence.
            $base=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE public_token=%s',$token);
            if(!$base||!hash_equals($base['public_token'],$token)||!$this->s->row('SELECT id FROM '.$this->s->table('campaigns').' WHERE id=%d',$base['campaign_id']))throw new ControlledTestException('Link unavailable.');
            $destination=$this->s->crypto->open($base['destination']);$original=$destination['original']??null;
            if(!is_string($original)||!self::allowed($original))throw new ControlledTestException('Unsafe link destination.');
            $base['active']=false;return ['link'=>$base,'target'=>$original,'preview'=>true];
        }if(!hash_equals($m['public_token'],$token)||$m['state']==='revoked'||(int)$m['expires_at']<time()||$m['domain']!==M7Settings::domain())throw new ControlledTestException('Link unavailable.');$link=$this->s->row('SELECT * FROM '.$this->s->table('links').' WHERE id=%d AND campaign_id=%d',$m['link_id'],$m['campaign_id']);if(!$link)throw new ControlledTestException('Link unavailable.');$target=$this->s->crypto->open($link['destination'])['target'];if(!self::allowed($target))throw new ControlledTestException('Unsafe link destination.');
        $link['recipient_mapping']=$m;$link['active']=false;
        // Resolution remains useful if analytics/attempt lookup fails after safe target loading.
        try{$item=$this->s->row('SELECT * FROM '.$this->s->table('send_items')." WHERE campaign_id=%d AND recipient_id=%d AND state='accepted'",$m['campaign_id'],$m['recipient_id']);$attempt=$item?$this->s->row('SELECT * FROM '.$this->s->table('attempts')." WHERE id=%d AND audience_id=%d AND state='accepted'",$item['attempt_id'],$m['audience_id']):null;$plan=$attempt?$this->s->row('SELECT id FROM '.$this->s->table('sendplans').' WHERE id=%d AND revision=%d',$attempt['plan_id'],$m['revision']):null;$c=$this->s->row('SELECT * FROM '.$this->s->table('campaigns').' WHERE id=%d',$m['campaign_id']);$link['active']=$plan&&$c&&str_contains($this->s->campaigns->m2->message($c),$m['domain'].'/'.$m['route'].'/'.$link['public_token']);}catch(\Throwable $e){M7Settings::error('submission_lookup_failed');}
        return ['link'=>$link,'target'=>$target];
    }
}
