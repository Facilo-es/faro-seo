<?php

declare(strict_types=1);

namespace facilo\faro\Core;

use facilo\faro\Core\Ports\ElementSource;

/**
 * Suggests where a broken link should go. Never applied by itself.
 */
final class Suggester
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';

    /**
     * @param array<string, int> $liveUris uri => element id
     * @param array<string, string> $earlierUris old uri => current uri
     * @return array{uri: string, confidence: string, score: float}|null
     */
    public static function suggest(string $path, array $liveUris, array $earlierUris = []): ?array
    {
        $path = Paths::split($path)[0];
        $lower = mb_strtolower($path);
        foreach ($earlierUris as $old => $now) {
            if (mb_strtolower(Paths::normalize((string)$old)) === $lower) {
                return ['uri' => $now, 'confidence' => self::HIGH, 'score' => 1.0];
            }
        }
        $seg = mb_strtolower(Paths::lastSegment($path));
        $parent = mb_strtolower(self::parent($path));
        $words = Paths::words($path);
        $best = null;
        foreach ($liveUris as $uri => $_id) {
            $uri = (string)$uri;
            $useg = mb_strtolower(Paths::lastSegment($uri));
            $ratio = self::ratio($seg, $useg);
            if ($ratio < 0.6) {
                continue;
            }
            // Same folder counts, shared words and look-alike typos (o for 0) break ties.
            $score = $ratio + (mb_strtolower(self::parent($uri)) === $parent ? 0.05 : 0.0) + 0.01 * self::shared($words, Paths::words($uri))
                + (self::ratio(self::lookAlike($seg), self::lookAlike($useg)) > $ratio ? 0.02 : 0.0);
            // Equal scores go to the first address in order, so the database's row order never decides.
            if ($best === null || $score > $best['score'] || $score === $best['score'] && strcmp($uri, $best['uri']) < 0) {
                $best = ['uri' => $uri, 'ratio' => $ratio, 'score' => $score];
            }
        }
        if ($best === null) {
            return null;
        }

        return ['uri' => $best['uri'], 'confidence' => $best['ratio'] >= 0.8 ? self::HIGH : self::MEDIUM, 'score' => round($best['ratio'], 3)];
    }

    public static function fromSource(string $path, int $siteId, ElementSource $source): ?array
    {
        return self::suggest($path, $source->liveUris($siteId), $source->earlierUris($siteId));
    }

    /**
     * Levenshtein ratio, 1.0 for equal strings. Works on characters, not bytes.
     */
    public static function ratio(string $a, string $b): float
    {
        if ($a === $b) {
            return $a === '' ? 0.0 : 1.0;
        }
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        $max = max($la, $lb);
        if ($max === 0) {
            return 0.0;
        }
        if ($la <= 255 && $lb <= 255 && strlen($a) === $la && strlen($b) === $lb) {
            $d = levenshtein($a, $b);
        } else {
            $d = self::mbLevenshtein(mb_str_split($a), mb_str_split($b));
        }

        return 1 - $d / $max;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function mbLevenshtein(array $a, array $b): int
    {
        $prev = range(0, count($b));
        foreach ($a as $i => $ca) {
            $cur = [$i + 1];
            foreach ($b as $j => $cb) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
            }
            $prev = $cur;
        }

        return $prev[count($b)];
    }

    /** Folds characters people mix up when typing an address: o and 0, l, i and 1. */
    private static function lookAlike(string $s): string
    {
        return strtr($s, ['o' => '0', 'l' => '1', 'i' => '1']);
    }

    private static function parent(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? '' : substr($path, 0, $pos);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function shared(array $a, array $b): int
    {
        return count(array_intersect(array_unique($a), array_unique($b)));
    }
}
