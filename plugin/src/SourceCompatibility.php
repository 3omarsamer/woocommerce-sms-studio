<?php
namespace Azula\SmsStudio;

final class SourceCompatibility
{
    public static function pinsForVersion(string $version):array
    {
        $files=['0.1.6.8'=>'source-pins.php','0.1.6.9'=>'source-pins-0169.php'];
        if(!isset($files[$version]))throw new \RuntimeException('Customer Segments version is not an audited read-only contract.');
        return require __DIR__.'/'.$files[$version];
    }
    public static function verify(): void
    {
        if (!class_exists('WooCommerce') || !class_exists('Azula_Customer_Segments') || !class_exists('Azula_Customer_Segments_API') || \Azula_Customer_Segments_API::VERSION !== 1) throw new \RuntimeException('An audited Customer Segments API v1 and WooCommerce are required.');
        $pins=self::pinsForVersion(\Azula_Customer_Segments::VERSION);
        foreach (['Azula\\CustomerSegments\\Dashboard' => 'scan', 'Azula\\CustomerSegments\\CustomerHistory' => 'decorate', 'Azula_Customer_Segments' => 'dashboard_sql'] as $class => $method) {
            if (!is_callable([$class, $method]) && $class !== 'Azula_Customer_Segments') throw new \RuntimeException('Read-only source interface unavailable.');
            if (!method_exists($class, $method)) throw new \RuntimeException('Read-only source interface unavailable.');
        }
        $root = dirname((new \ReflectionClass('Azula_Customer_Segments'))->getFileName());
        foreach ($pins as $relative => $hash) {
            $path = $root . '/' . $relative;
            $actual = @hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($hash, $actual)) throw new \RuntimeException('Customer Segments source differs from its audited read-only contract.');
        }
    }
}
