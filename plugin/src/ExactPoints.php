<?php
namespace Azula\SmsStudio;

/** Decimal provider units. Never infer an EGP conversion or use binary floating-point sums. */
final class ExactPoints
{
    public static function add(string $left,string $right):string
    {
        foreach([$left,$right] as $value)if(!preg_match('/^[0-9]{1,30}(?:\.[0-9]{1,18})?$/D',$value))throw new \InvalidArgumentException('Invalid decimal points.');
        $a=explode('.',$left);$b=explode('.',$right);$scale=max(strlen($a[1]??''),strlen($b[1]??''));$x=$a[0].str_pad($a[1]??'',$scale,'0');$y=$b[0].str_pad($b[1]??'',$scale,'0');$length=max(strlen($x),strlen($y));$x=str_pad($x,$length,'0',STR_PAD_LEFT);$y=str_pad($y,$length,'0',STR_PAD_LEFT);$out='';$carry=0;
        for($i=$length-1;$i>=0;$i--){$n=(int)$x[$i]+(int)$y[$i]+$carry;$out=($n%10).$out;$carry=intdiv($n,10);}if($carry)$out=$carry.$out;if($scale){$out=str_pad($out,$scale+1,'0',STR_PAD_LEFT);$out=substr($out,0,-$scale).'.'.substr($out,-$scale);$out=rtrim(rtrim($out,'0'),'.');}$out=ltrim($out,'0');return $out===''?'0':($out[0]==='.'?'0'.$out:$out);
    }
}
