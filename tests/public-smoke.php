<?php
// Format-only synthetic inputs. No WordPress, network, provider or database loaded.
spl_autoload_register(function($c){$p='Azula\\SmsStudio\\';if(str_starts_with($c,$p))require __DIR__.'/../plugin/src/'.substr($c,strlen($p)).'.php';});
use Azula\SmsStudio\{EgyptMobileNormalizer,MessageMetrics,Crypto,CairoTime,PhoneDiagnostics};
$passed=0;
function eq($a,$b){global $passed;if($a!==$b)throw new RuntimeException('Synthetic assertion failed');$passed++;}
$n=new EgyptMobileNormalizer();
foreach(['01100000000','+201100000000','201100000000','00201100000000','1100000000',"'01100000000","\u{200F}+20 110 0000000", "\u{2067}+201100000000\u{2069}"] as $v)eq('201100000000',$n->normalize($v)['phone']);
foreach(["''01100000000","011'00000000",'+441100000000','01100',"\u{202E}01100000000", "011\n00000000"] as $v)eq(null,$n->normalize($v)['phone']);
eq(1,MessageMetrics::calculate('Synthetic campaign')['segments']);eq('GSM-7',MessageMetrics::calculate('Test')['encoding']);eq(2,MessageMetrics::calculate(str_repeat('A',161))['segments']);
$c=new Crypto(base64_encode(random_bytes(32)));eq(['fixture'=>'synthetic'],$c->open($c->seal(['fixture'=>'synthetic'])));eq(true,strlen($c->correlate('synthetic'))===64);
eq(true,str_contains(CairoTime::label(0),'Africa/Cairo'));
$d=(new PhoneDiagnostics())->selfTest();foreach($d['cases'] as $row)eq('PASS',$row['status']);
echo json_encode(['status'=>'PASS','assertions'=>$passed,'scope'=>'Offline synthetic public-package smoke checks']).PHP_EOL;
