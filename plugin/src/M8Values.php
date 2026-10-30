<?php
namespace Azula\SmsStudio;
/** Values are read through Woo public APIs. Never recalculate/mutate the cart. */
final class M8Values
{
    public static function unavailable(string $basis):array{return ['minor'=>null,'decimal'=>null,'currency'=>function_exists('get_woocommerce_currency')?(string)get_woocommerce_currency():'','basis'=>$basis];}
    public static function amount($amount,string $currency,string $basis):array
    {
        if(is_float($amount)&&is_finite($amount))$amount=function_exists('wc_format_decimal')?wc_format_decimal($amount,function_exists('wc_get_price_decimals')?wc_get_price_decimals():2):number_format($amount,2,'.','');
        if((!is_string($amount)&&!is_int($amount))||!preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D',(string)$amount)||!preg_match('/^[A-Z]{3}$/D',$currency))return self::unavailable('invalid_amount_or_currency');
        return ['minor'=>$currency==='EGP'?M6Revenue::minor((string)$amount):null,'decimal'=>(string)$amount,'currency'=>$currency,'basis'=>$basis];
    }
    public static function cart(int $product,int $variation,$quantity):array
    {
        try{if(!is_numeric($quantity)||!is_finite((float)$quantity)||(float)$quantity<=0||!function_exists('wc_get_product')||!function_exists('wc_get_price_excluding_tax'))return self::unavailable('catalog_value_unavailable');$p=wc_get_product($variation?:$product);if(!$p||$p->get_price()==='')return self::unavailable('catalog_price_unavailable');return self::amount(wc_get_price_excluding_tax($p,['qty'=>$quantity]),get_woocommerce_currency(),'added_quantity_catalog_sale_ex_tax_no_cart_coupons');}catch(\Throwable $e){return self::unavailable('catalog_value_unavailable');}
    }
    public static function checkout():array
    {
        try{$woo=function_exists('WC')?WC():null;$cart=$woo->cart??null;if(!$cart||!method_exists($cart,'is_empty')||$cart->is_empty()||!method_exists($cart,'get_total'))return self::unavailable('cart_total_unavailable');return self::amount($cart->get_total('edit'),get_woocommerce_currency(),'cart_payable_after_discounts_including_shipping_tax_fees');}catch(\Throwable $e){return self::unavailable('cart_total_unavailable');}
    }
}
