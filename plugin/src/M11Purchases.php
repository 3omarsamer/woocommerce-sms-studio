<?php
namespace Azula\SmsStudio;

/** HPOS-compatible, bounded, read-only purchase exclusion across marketing channels. */
final class M11Purchases
{
    public static function sameCustomer(array $member,$order):bool
    {
        if(!($order instanceof \WC_Order)||$order instanceof \WC_Order_Refund||!empty($member['identity_warning']))return false;
        $uid=(int)($member['user_id']??0);$orderUid=(int)$order->get_customer_id();if($uid&&$orderUid===$uid)return true;
        $email=strtolower(trim((string)($member['email']??'')));$first=trim((string)($member['first_name']??''));$last=trim((string)($member['last_name']??''));
        return !$orderUid&&filter_var($email,FILTER_VALIDATE_EMAIL)&&strtolower(trim($order->get_billing_email()))===$email&&$first!==''&&$last!==''&&strcasecmp(trim($order->get_billing_first_name()),$first)===0&&strcasecmp(trim($order->get_billing_last_name()),$last)===0;
    }
    public static function check(array $member,int $since):?string
    {
        if(!empty($member['identity_warning'])||$since<1||!function_exists('wc_get_orders')||!function_exists('wc_get_order_statuses'))return 'purchase_unverified';
        $email=strtolower(trim((string)($member['email']??'')));$uid=(int)($member['user_id']??0);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))return 'purchase_unverified';
        $first=trim((string)($member['first_name']??''));$last=trim((string)($member['last_name']??''));
        if(!$uid&&($first===''||$last===''))return 'purchase_unverified';
        try{
            if(!$uid){if(!is_callable(['Azula\\CustomerSegments\\CustomerHistory','scope']))return 'purchase_unverified';\Azula\CustomerSegments\CustomerHistory::scope($member);}
            $base=['type'=>'shop_order','status'=>array_keys(wc_get_order_statuses()),'limit'=>101,'paginate'=>false,'return'=>'objects','orderby'=>'ID','order'=>'ASC'];
            if(!$base['status'])return 'purchase_unverified';
            $groups=[];$scopes=[['billing_email'=>$email]];if($uid)$scopes[]=['customer_id'=>$uid];
            foreach($scopes as $scope)foreach(['date_created','date_paid'] as $dateKey){$groups[]=wc_get_orders($base+$scope+[$dateKey=>'>='.$since]);global $wpdb;if(!empty($wpdb->last_error))return 'purchase_unverified';}
            $ambiguous=false;$seen=[];
            foreach($groups as $orders){if(!is_array($orders)||count($orders)>100)return 'purchase_unverified';foreach($orders as $order){
                if(!($order instanceof \WC_Order)||$order instanceof \WC_Order_Refund)return 'purchase_unverified';
                $id=(int)$order->get_id();if(isset($seen[$id]))continue;$seen[$id]=true;
                $date=$order->get_date_created();$paid=$order->get_date_paid();if(!$date||max($date->getTimestamp(),$paid?$paid->getTimestamp():0)<$since)return 'purchase_unverified';
                $status=$order->get_status();if(in_array($status,['failed','cancelled'],true))continue;
                $orderUid=(int)$order->get_customer_id();
                $same=$uid&&$orderUid===$uid;
                if(!$orderUid)$same=strtolower(trim($order->get_billing_email()))===$email&&$first!==''&&$last!==''&&strcasecmp(trim($order->get_billing_first_name()),$first)===0&&strcasecmp(trim($order->get_billing_last_name()),$last)===0;
                if(!$same){$ambiguous=true;continue;}
                // Refunded purchases are not unfinished checkouts; pending/on-hold/custom statuses withhold.
                if(in_array($status,['processing','completed','refunded'],true))return 'purchased';
                $ambiguous=true;
            }}
            return $ambiguous?'purchase_unverified':null;
        }catch(\Throwable $e){return 'purchase_unverified';}
    }
}
