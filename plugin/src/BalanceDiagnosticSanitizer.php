<?php
namespace Azula\SmsStudio;

/** Reconstruct a small diagnostic shape; never echo the provider's raw text or unknown keys. */
final class BalanceDiagnosticSanitizer
{
    public static function response(string $body,array $secrets=[]):array
    {
        if(strlen($body)>16384)return ['format'=>'oversized','body'=>'[redacted oversized response]'];
        if(str_starts_with($body,"\xEF\xBB\xBF"))$body=substr($body,3);
        $decoded=json_decode($body,false,16);
        if(json_last_error()!==JSON_ERROR_NONE)return ['format'=>'non_json','body'=>'[redacted non-JSON response]'];
        $format=is_object($decoded)?'json_object':(is_array($decoded)?'json_array':(is_numeric($decoded)?'json_number':'json_scalar'));
        $safe=self::value($decoded,$secrets,0);
        return ['format'=>$format,'body'=>json_encode($safe,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)];
    }
    private static function value($value,array $secrets,int $depth)
    {
        if($depth>=4)return '[redacted depth limit]';
        if(!is_object($value))return is_int($value)||is_float($value)||(is_string($value)&&preg_match('/^\d+(?:\.\d+)?$/D',$value))?5000:'[redacted scalar or array]';
        $safe=new \stdClass();$n=0;
        foreach(get_object_vars($value) as $key=>$item){
            if(++$n>20){$safe->omitted='[redacted additional fields]';break;}
            $lower=strtolower($key);
            if($lower==='code'){
                $code=is_string($item)||is_int($item)?(string)$item:'';
                $secret=false;foreach($secrets as $s)if(is_string($s)&&$s!==''&&str_contains($code,$s))$secret=true;
                $safe->{$key}=!$secret&&preg_match('/^[1-9][0-9]{3}$/D',$code)?$code:'[redacted code]';
            }elseif($lower==='balance')$safe->{$key}=5000;
            elseif(in_array($lower,['data','result','response'],true)&&is_object($item))$safe->{$key}=self::value($item,$secrets,$depth+1);
            else $safe->{'redacted_field_'.$n}='[redacted]';
        }
        return $safe;
    }
}
