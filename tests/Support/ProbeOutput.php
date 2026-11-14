<?php

declare(strict_types=1);

namespace Tests\Support;

final class ProbeOutput
{
    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $lockfiles
     * @param  array<string, string>  $locked
     * @param  list<string>  $markers
     */
    public static function for(array $files = [], array $lockfiles = [], array $locked = [], array $markers = []): string
    {
        $lines = array_map(fn(string $marker): string => '@@has ' . $marker, $markers);

        foreach ($files as $name => $content) {
            $lines[] = '@@file ' . $name . ' ' . base64_encode($content);
        }

        foreach ($lockfiles as $lockfile) {
            $lines[] = '@@lock ' . $lockfile;
        }

        foreach ($locked as $package => $version) {
            $lines[] = '@@locked ' . $package . ' ' . $version;
        }

        return implode("\n", $lines) . "\n";
    }
}
