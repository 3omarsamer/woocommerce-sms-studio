<?php
namespace Azula\SmsStudio;

interface AudienceSource
{
    public function catalog(int $after = 0, int $limit = 100): array;
    public function segment(int $id): array;
    public function ceiling(array $segment): int;
    public function scan(array $segment, int $after, int $ceiling): array;
}
