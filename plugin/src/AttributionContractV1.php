<?php
namespace Azula\SmsStudio;

/** Fixture-only projection. No Woo queries or order mutations. Monetary values are integer minor units. */
final class AttributionContractV1
{
    public function summarize(array $orders, array $knownCampaigns, int $from, int $to): array
    {
        $seen = []; $campaigns = []; $unresolved = []; $total = 0;
        foreach ($orders as $order) {
            $id = (int) $order['id'];
            if ($id < 1) throw new \InvalidArgumentException('Invalid order identity.');
            if (isset($seen[$id])) {
                if ($seen[$id] !== $order) throw new \DomainException('Conflicting projection rows for one order.');
                continue;
            }
            $seen[$id] = $order;
            if (!$order['eligible'] || $order['created'] < $from || $order['created'] > $to) continue;
            $s = $order['snapshot'] ?? [];
            if (empty($s['snapshot_version']) || strtolower(trim($s['source_raw'] ?? '')) !== 'smsmisr' || strtolower(trim($s['medium_raw'] ?? '')) !== 'sms') continue;
            $campaign = (string) ($s['campaign_raw'] ?? '');
            if (!in_array($campaign, $knownCampaigns, true)) { $unresolved[] = $id; continue; }
            if (($order['currency'] ?? '') !== 'EGP' || !is_int($order['sales_minor'])) throw new \DomainException('Unsupported currency or noninteger revenue.');
            $campaigns[$campaign] ??= ['orders' => 0, 'sales_minor' => 0];
            $campaigns[$campaign]['orders']++; $campaigns[$campaign]['sales_minor'] += $order['sales_minor'];
            $total += $order['sales_minor'];
        }
        return ['contract' => 1, 'orders' => array_sum(array_column($campaigns, 'orders')), 'sales_minor' => $total, 'campaigns' => $campaigns, 'unresolved_order_ids' => $unresolved, 'assistance' => ['available' => false], 'label' => 'SMS-attributed orders — Stage A/B campaign snapshot'];
    }
}
