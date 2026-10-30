<?php
namespace Azula\SmsStudio;

final class SmsMisrSettings
{
    public const OPTION='azula_sms_provider_config';
    public const HEALTH='azula_sms_provider_health';
    public function __construct(private Crypto $crypto,private $db){}
    public static function authorize(string $nonce):void { M2Api::authorize($nonce);if(!current_user_can('manage_azula_sms_settings'))throw new \DomainException('Provider administration required.'); }
    public function config():array
    {
        if(function_exists('wp_cache_delete')){wp_cache_delete(M11Environment::option(self::OPTION),'options');wp_cache_delete('notoptions','options');}
        $value=get_option(M11Environment::option(self::OPTION),null);return is_string($value)?$this->crypto->open($value):['revision'=>0,'username'=>'','password'=>'','sender'=>'','environment'=>2];
    }
    public function status():array
    {
        $c=$this->config();if(function_exists('wp_cache_delete')){wp_cache_delete(M11Environment::option(self::HEALTH),'options');wp_cache_delete('notoptions','options');}$h=get_option(M11Environment::option(self::HEALTH),[]);
        if(($h['revision']??-1)!==$c['revision']||($h['response_contract']??'')!==SmsMisrHttp::BALANCE_RESPONSE_CONTRACT)$h=[];
        return ['revision'=>$c['revision'],'username'=>$c['username'],'sender'=>$c['sender'],'environment'=>$c['environment'],'password_saved'=>$c['password']!=='','configured'=>$c['username']!==''&&$c['password']!==''&&$c['sender']!=='','connection_status'=>$h['connection_status']??'not_checked','last_success'=>(int)($h['last_success']??0),'last_balance_check'=>(int)($h['last_balance_check']??0),'balance_candidate'=>$h['balance_candidate']??null,'balance'=>$h['balance']??null,'balance_verified'=>($h['balance_verified']??false)===true,'last_code'=>$h['code']??null,'last_status'=>$h['status']??'not_checked','sending_enabled'=>false,'last_http_status'=>(int)($h['http_status']??0),'balance_transport'=>'POST','balance_scope'=>'account_wide','contract'=>'owner-verified-balance-number-2026-10-17'];
    }
    public function saveCredentials(array $p):array
    {
        $lock='azula_sms_provider_'.substr(hash('sha256',$this->db->prefix),0,32);
        if((string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new \RuntimeException('Provider settings busy.');
        try{
            $old=$this->config();if(!is_string($p['revision']??null)||!ctype_digit($p['revision'])||(int)$p['revision']!==$old['revision'])throw new \DomainException('Provider settings changed. Reload.');
            foreach(['username','password','sender','environment'] as $f)if(!is_string($p[$f]??null)||strlen($p[$f])>1024||preg_match('/[\x00\r\n]/',$p[$f]))throw new \InvalidArgumentException('Invalid provider setting.');
            if(!in_array($p['environment'],['1','2'],true))throw new \InvalidArgumentException('Invalid environment.');
            $password=$p['password'];if($password===''){if($p['username']!==$old['username'])throw new \DomainException('A changed username needs a new password.');$password=$old['password'];}
            $next=['revision'=>$old['revision']+1,'username'=>$p['username'],'password'=>$password,'sender'=>$p['sender'],'environment'=>(int)$p['environment']];
            new SmsMisrAdapter($next);$sealed=$this->crypto->seal($next);
            update_option(M11Environment::option(self::OPTION),$sealed,false);if(get_option(M11Environment::option(self::OPTION))!==$sealed)throw new \RuntimeException('Credential save failed.');
            update_option(M11Environment::option(self::HEALTH),[],false);return $this->status();
        }finally{$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public function diagnostic():array
    {
        if(!M2Scheduler::staging())throw new \DomainException('STAGING diagnostic only.');
        $lock='azula_sms_probe_'.substr(hash('sha256',$this->db->prefix),0,32);
        if((string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new \RuntimeException('Provider check busy.');
        try{
            if(time()-(int)get_option(M11Environment::option('azula_sms_balance_diagnostic_at'),0)<5)throw new \DomainException('Wait five seconds before another diagnostic.');
            $c=$this->config();$request=(new SmsMisrAdapter($c))->balanceRequest();
            update_option(M11Environment::option('azula_sms_balance_diagnostic_at'),time(),false);
            $result=SmsMisrHttp::diagnostic($request['body']);
            if($this->config()['revision']!==$c['revision'])throw new \DomainException('Settings changed during diagnostic. Result discarded.');
            return $result; // No response body, actual balance or credential is stored.
        }finally{$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    public function probe():array
    {
        $lock='azula_sms_probe_'.substr(hash('sha256',$this->db->prefix),0,32);
        if((string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new \RuntimeException('Provider check busy.');
        try{
            $c=$this->config();$old=get_option(M11Environment::option(self::HEALTH),[]);
            if(($old['response_contract']??'')!==SmsMisrHttp::BALANCE_RESPONSE_CONTRACT)$old=[];
            if(($old['revision']??-1)===$c['revision'] && time()-(int)($old['attempted']??0)<5)return $this->status();
            $request=(new SmsMisrAdapter($c))->balanceRequest();
            $h=['revision'=>$c['revision'],'attempted'=>time(),'last_balance_check'=>time(),'last_success'=>(int)($old['last_success']??0),'connection_status'=>'unverified','status'=>'network_unavailable','code'=>null,'balance_candidate'=>null];
            update_option(M11Environment::option(self::HEALTH),$h,false);
            $response=SmsMisrHttp::balance($request['body']);
            $h=array_replace($h,$response);if($response['connection_status']==='verified')$h['last_success']=time();
            if($this->config()['revision']!==$c['revision'])throw new \DomainException('Settings changed during provider check. Result discarded.');
            update_option(M11Environment::option(self::HEALTH),$h,false);return $this->status();
        }finally{$this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
}
