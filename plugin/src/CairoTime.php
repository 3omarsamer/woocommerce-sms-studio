<?php
namespace Azula\SmsStudio;

final class CairoTime
{
    public const ZONE = 'Africa/Cairo';
    /** Return both UTC instants for a fold; return none for a DST gap. Never guess. */
    public static function choices(string $local): array
    {
        $local = str_replace('T', ' ', $local);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i') !== $local) throw new \InvalidArgumentException('Enter a valid Egypt local date and minute.');
        $zone = new \DateTimeZone(self::ZONE); $wall = $date->getTimestamp(); $result = [];
        foreach ($zone->getTransitions($wall-172800, $wall+172800) as $transition) {
            $utc = $wall-(int)$transition['offset'];
            $at = (new \DateTimeImmutable('@'.$utc))->setTimezone($zone);
            if ($at->format('Y-m-d H:i') === $local) $result[$utc] = ['utc'=>$utc,'offset'=>$at->format('P'),'label'=>$at->format('Y-m-d H:i P').' · Egypt'];
        }
        ksort($result); return array_values($result);
    }
    public static function resolve(string $local, string $offset): int
    {
        foreach (self::choices($local) as $choice) if ($choice['offset']===$offset) return $choice['utc'];
        throw new \DomainException('Egypt time is nonexistent or the selected DST occurrence is invalid.');
    }
    public static function label(int $utc): string { return (new \DateTimeImmutable('@'.$utc))->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i P').' · Africa/Cairo'; }
}
