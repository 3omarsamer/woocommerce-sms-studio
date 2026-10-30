<?php
namespace Azula\SmsStudio;

/** Contract supplied by the owner from SMS Misr account documentation, 2026-10-17. */
final class SmsMisrAdapter
{
    public const SMS_URL='https://smsmisr.com/api/SMS/';
    public const BALANCE_URL='https://smsmisr.com/api/Balance/';
    public const MAX_RECIPIENTS=5000;
    private array $simulations=[];
    public function __construct(private array $config) {
        if(!in_array($config['environment']??null,[1,2],true))throw new \InvalidArgumentException('Invalid provider environment.');
        foreach(['username','password','sender'] as $field)if(!is_string($config[$field]??null)||$config[$field]===''||strlen($config[$field])>1024||preg_match('/[\x00\r\n]/',$config[$field]))throw new \InvalidArgumentException('Incomplete provider configuration.');
    }
    public function balanceRequest():array { return ['url'=>self::BALANCE_URL,'method'=>'POST','body'=>['username'=>$this->config['username'],'password'=>$this->config['password']]]; }
    /** Internal server-only request construction. Never return this body to an admin response/log. */
    public function submissionRequest(array $phones,string $message,int $language):array
    {
        if(!$phones || count($phones)>self::MAX_RECIPIENTS || count(array_unique($phones,SORT_STRING))!==count($phones))throw new \InvalidArgumentException('Use unique, bounded recipients.');
        foreach($phones as $phone)if(!is_string($phone)||(new EgyptMobileNormalizer())->normalize($phone)['phone']!==$phone)throw new \InvalidArgumentException('A canonical Egyptian mobile is required.');
        $metrics=MessageMetrics::calculate($message);if(!$metrics['valid']||!in_array($language,[1,2,3],true))throw new \InvalidArgumentException('Unsupported message/language.');
        if($language===1 && $metrics['encoding']!=='GSM-7')throw new \InvalidArgumentException('English language requires GSM-7 text.');
        if($language===3)$message=self::unicodeHex($message);
        $fields=['environment'=>$this->config['environment'],'username'=>$this->config['username'],'password'=>$this->config['password'],'sender'=>$this->config['sender'],'mobile'=>implode(',',$phones),'language'=>$language,'message'=>$message];
        return ['url'=>self::SMS_URL,'method'=>'POST','headers'=>['Content-Type'=>'application/x-www-form-urlencoded'],'body'=>http_build_query($fields,'','&',PHP_QUERY_RFC1738)];
    }
    public function batches(array $phones,string $message,int $language,int $size=100):array
    {
        if($size<1||$size>self::MAX_RECIPIENTS||count($phones)>25000||count(array_unique($phones,SORT_STRING))!==count($phones))throw new \InvalidArgumentException('Invalid bounded unique batch.');
        $requests=[];foreach(array_chunk($phones,$size) as $chunk)$requests[]=$this->submissionRequest($chunk,$message,$language);return $requests;
    }
    public static function unicodeHex(string $text):string
    {
        if(preg_match('//u',$text)!==1)throw new \InvalidArgumentException('Invalid Unicode.');$hex='';
        foreach(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY) as $char){$b=array_values(unpack('C*',$char));$cp=count($b)===1?$b[0]:(count($b)===2?(($b[0]&31)<<6)|($b[1]&63):(count($b)===3?(($b[0]&15)<<12)|(($b[1]&63)<<6)|($b[2]&63):-1));if($cp<0||($cp>=0xD800&&$cp<=0xDFFF))throw new \InvalidArgumentException('Non-BMP Unicode is unverified.');$hex.=sprintf('%04x',$cp);}
        return $hex;
    }
    public static function response(int $http,string $body):array
    {
        $base=['accepted'=>false,'delivered'=>false,'automatic_retry'=>false,'smsid'=>null,'cost'=>null,'code'=>null];
        if($http!==200)return $base+['status'=>$http===429?'http_rate_limited':($http>=500?'provider_unavailable':'http_error'),'retry_class'=>$http===429||$http>=500?'unknown_submission_outcome':'permanent_http'];
        if(strlen($body)>16384)return $base+['status'=>'unexpected_response','retry_class'=>'unknown_submission_outcome'];
        $p=json_decode($body,true);if(!is_array($p)||!is_scalar($p['code']??null))return $base+['status'=>'unexpected_response','retry_class'=>'unknown_submission_outcome'];
        $code=(string)$p['code'];$base['code']=preg_match('/^19\d{2}$/D',$code)?$code:null;
        $codes=['1901'=>'accepted','1902'=>'invalid_request','1903'=>'invalid_credentials','1904'=>'invalid_sender','1905'=>'invalid_mobile','1906'=>'insufficient_credit','1907'=>'server_updating','1908'=>'invalid_delay_format','1909'=>'invalid_message','1910'=>'invalid_language','1911'=>'text_too_long','1912'=>'invalid_environment'];
        if(!isset($codes[$code]))return $base+['status'=>'unknown_provider_code','retry_class'=>'unknown_submission_outcome'];
        if($code!=='1901')return $base+['status'=>$codes[$code],'retry_class'=>$code==='1907'?'transient_rejection':'permanent_rejection'];
        $id=$p['SMSID']??null;$cost=$p['Cost']??null;
        if(!is_scalar($id)||!preg_match('/^[A-Za-z0-9._:-]{1,190}$/D',(string)$id)||($cost!==null&&(!is_scalar($cost)||!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D',(string)$cost))))return $base+['status'=>'unexpected_response','retry_class'=>'unknown_submission_outcome'];
        $base['accepted']=true;$base['smsid']=(string)$id;$base['cost']=$cost===null?null:(string)$cost;
        return $base+['status'=>'accepted','retry_class'=>'none']; // Provider acceptance is never delivery.
    }
    /** Real submission is structurally blocked, including test-environment submission. */
    public function submit(array $phones,string $message,int $language):array { DispatchGate::assertAvailable();throw new \LogicException('M5 send authorization is required.'); }
    /** Offline-only contract exercise; no production transport implements this interface. */
    public function simulate(string $key,array $phones,string $message,int $language,SmsMisrOfflineTransport $transport):array
    {
        if(!preg_match('/^[A-Za-z0-9_-]{8,80}$/D',$key))throw new \InvalidArgumentException('Invalid simulation key.');
        $request=$this->submissionRequest($phones,$message,$language);$digest=hash('sha256',json_encode($request,JSON_THROW_ON_ERROR));
        if(isset($this->simulations[$key])){if($this->simulations[$key]['digest']!==$digest)throw new \DomainException('Simulation binding changed.');return $this->simulations[$key]['result'];}
        $result=['accepted'=>false,'delivered'=>false,'automatic_retry'=>false,'status'=>'transport_unknown','retry_class'=>'unknown_submission_outcome','smsid'=>null,'cost'=>null,'code'=>null];
        // Claim before exchanging. Ambiguous timeouts are retained, never blindly retried.
        $this->simulations[$key]=['digest'=>$digest,'result'=>$result];
        try{$response=$transport->exchange($request);$result=self::response((int)$response['http'],(string)$response['body']);}catch(\Throwable $e){}
        $this->simulations[$key]['result']=$result;return $result;
    }
    public static function deliveryCapability():array { return ['supported'=>false,'reason'=>'No authenticated delivery/status API was supplied. Acceptance cannot establish delivery.']; }
}
