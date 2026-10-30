<?php
namespace Azula\SmsStudio;

final class Database
{
    public const TABLES = ['campaigns', 'revisions', 'audiences', 'members', 'recipients', 'suppressions', 'approvals', 'audit', 'schedules', 'queue', 'controlled', 'attempts', 'campaign_meta', 'sendplans', 'send_items', 'links', 'clicks', 'journey', 'conversions', 'recipient_links', 'link_requests', 'bulk_runs', 'target_segments', 'target_members'];
    public function __construct(public $db) {}
    private int $depth = 0;
    public function table(string $name): string
    {
        if (!in_array($name, self::TABLES, true) || !preg_match('/^[A-Za-z0-9_]+$/D', $this->db->prefix)) throw new \InvalidArgumentException('Invalid SMS table identifier.');
        return $this->db->prefix . 'azula_sms_' . $name;
    }
    public function check($result)
    {
        if ($result === false || $this->db->last_error) throw new \RuntimeException('SMS storage operation failed.');
        return $result;
    }
    public function transaction(callable $callback)
    {
        if($this->depth>0)return $callback(); // Same SMS unit of work; outer scope owns commit/rollback.
        $this->check($this->db->query('START TRANSACTION'));
        $this->depth++;
        try { $result = $callback(); $this->check($this->db->query('COMMIT')); return $result; }
        catch (\Throwable $e) { $this->db->query('ROLLBACK'); throw $e; }
        finally { $this->depth--; }
    }
}
