<?php
namespace Azula\SmsStudio;

final class CustomerSegmentsAdapterV1 implements AudienceSource, FrozenIdentitySource
{
    public const VERSION = 'cs-0.1.6.8-keyset-1';
    public const COVERAGE = "Customer Segments currently enumerates customer lookup records with email addresses. Customers without email are outside this source's coverage. An email address does not imply email marketing subscription is required for SMS.";
    private $db;
    public function __construct($db, ?callable $compatibility = null)
    {
        $this->db = $db;
        ($compatibility ?? [SourceCompatibility::class, 'verify'])();
    }
    private function checked($value)
    {
        if ($this->db->last_error || $value === false || $value === null) throw new \RuntimeException('Customer Segments read service unavailable.');
        return $value;
    }
    public function catalog(int $after = 0, int $limit = 100): array
    {
        if ($after < 0 || $limit < 1 || $limit > 100) throw new \InvalidArgumentException('Invalid segment cursor.');
        $table = $this->db->prefix . 'azula_customer_segments';
        $rows = $this->checked($this->db->get_results($this->db->prepare("SELECT id,name FROM $table WHERE id>%d ORDER BY id ASC LIMIT %d", $after, $limit), ARRAY_A));
        foreach ($rows as &$row) {
            // Same original membership query used by Customer Segments Dashboard::members.
            // Count only: no phone decoration, SMS eligibility rules or persistent cache.
            $saved = $this->segment((int) $row['id']);
            $base = \Azula_Customer_Segments::instance()->dashboard_sql(json_decode($saved['rules'], true));
            if (!is_string($base) || $base === '') throw new \RuntimeException('Segment count unavailable.');
            $count = $this->checked($this->db->get_var("SELECT COUNT(*) FROM ({$base}) segment_count"));
            if (!preg_match('/^[0-9]+$/D', (string) $count)) throw new \RuntimeException('Segment count unavailable.');
            $row['customer_count'] = (int) $count;
        }
        unset($row);
        return $rows;
    }
    public function segment(int $id): array
    {
        if ($id < 1) throw new \InvalidArgumentException('Choose an explicit saved segment.');
        $table = $this->db->prefix . 'azula_customer_segments';
        $row = $this->checked($this->db->get_row($this->db->prepare("SELECT id,name,rules,updated_at FROM $table WHERE id=%d", $id), ARRAY_A));
        $rules = json_decode($row['rules'], true);
        if (!is_array($rules) || ($rules['logic'] ?? '') !== 'AND' || !is_array($rules['conditions'] ?? null)) throw new \RuntimeException('Unsupported saved segment rules.');
        $row['fingerprint'] = hash('sha256', $row['id'] . '|' . $row['rules']);
        return $row;
    }
    public function identity(int $sourceId):?array
    {
        if($sourceId<1)throw new \InvalidArgumentException('Invalid captured source identity.');
        $row=$this->db->get_row($this->db->prepare('SELECT customer_id,user_id,first_name,last_name,email,0 orders_count,0 total_spent FROM '.$this->db->prefix.'wc_customer_lookup WHERE customer_id=%d',$sourceId),ARRAY_A);
        if($this->db->last_error)throw new \RuntimeException('Frozen identity lookup unavailable.');
        if(!$row||trim((string)$row['email'])==='')return null;
        $decorated=\Azula\CustomerSegments\CustomerHistory::decorate([$row]);return $decorated[0]??null;
    }
    public function ceiling(array $segment): int
    {
        // Global lookup ceiling bounds IDs without copying the segment rule engine.
        return (int) $this->checked($this->db->get_var('SELECT COALESCE(MAX(customer_id),0) FROM ' . $this->db->prefix . 'wc_customer_lookup'));
    }
    public function scan(array $segment, int $after, int $ceiling): array
    {
        if ($after < 0 || $ceiling < 0) throw new \InvalidArgumentException('Invalid capture cursor.');
        $current = $this->segment((int) $segment['id']);
        if (!hash_equals($segment['fingerprint'], $current['fingerprint'])) throw new \RuntimeException('Saved segment rules changed. Restart preparation.');
        // Empty search retains all members; scan calls CustomerHistory::decorate itself.
        $page = \Azula\CustomerSegments\Dashboard::scan($current, '', $after, $ceiling, 100);
        if (!hash_equals($segment['fingerprint'], $page['fingerprint']) || count($page['members']) > 100) throw new \RuntimeException('Source scan contract mismatch.');
        $last = $after;
        foreach ($page['members'] as $member) {
            $id = (int) $member['customer_id'];
            if ($id <= $last || $id > $ceiling) throw new \RuntimeException('Nonmonotonic source cursor.');
            $last = $id;
        }
        if ((int) $page['next_cursor'] !== $last || (!$page['complete'] && $last === $after)) throw new \RuntimeException('Source scan did not progress.');
        return $page;
    }
}
