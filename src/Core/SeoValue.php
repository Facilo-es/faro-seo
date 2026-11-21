<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * The stored value of a Faro SEO field, version 1. Reads Ether SEO's stored
 * shape as well, so a field changed by project config works before its data
 * is rewritten.
 */
final class SeoValue
{
    public const VERSION = 1;
    public const NETWORKS = ['og', 'x'];
    public const ROBOTS = ['noindex', 'nofollow', 'noarchive', 'nosnippet', 'notranslate', 'noimageindex'];

    /**
     * @param list<string> $keywords
     * @param array{og: array{title: string, description: string, imageId: ?int}, x: array{title: string, description: string, imageId: ?int}} $social
     * @param list<string> $robots
     * @param list<string> $needsCheck Ether text kept as plain text (Twig Faro won't run)
     */
    public function __construct(
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly array $keywords = [],
        public readonly array $social = ['og' => ['title' => '', 'description' => '', 'imageId' => null], 'x' => ['title' => '', 'description' => '', 'imageId' => null]],
        public readonly array $robots = [],
        public readonly string $canonical = '',
        public readonly bool $fromEther = false,
        public readonly array $needsCheck = [],
    ) {
    }

    /**
     * @param array<string, mixed>|string|null $value stored JSON or decoded array
     * @param array<int|string, array<string, mixed>> $etherTokens the Ether field's title tokens, for its shape
     */
    public static function from(array|string|null $value, array $etherTokens = []): self
    {
        if (is_string($value)) {
            $value = $value === '' ? [] : json_decode($value, true);
        }
        if (!is_array($value)) {
            return new self();
        }
        if (EtherMapping::isEtherShape($value)) {
            return EtherMapping::value($value, $etherTokens);
        }
        $social = self::emptySocial();
        foreach (self::NETWORKS as $n) {
            $s = is_array($value['social'][$n] ?? null) ? $value['social'][$n] : [];
            $social[$n] = [
                'title' => self::str($s['title'] ?? ''),
                'description' => self::str($s['description'] ?? ''),
                'imageId' => self::id($s['imageId'] ?? null),
            ];
        }

        return new self(
            self::str($value['title'] ?? ''),
            self::str($value['description'] ?? ''),
            self::keywords($value['keywords'] ?? []),
            $social,
            self::robots($value['robots'] ?? []),
            self::str($value['canonical'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'title' => $this->title,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'social' => $this->social,
            'robots' => $this->robots,
            'canonical' => $this->canonical,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === (new self())->toArray();
    }

    /**
     * @return array{og: array{title: string, description: string, imageId: ?int}, x: array{title: string, description: string, imageId: ?int}}
     */
    public static function emptySocial(): array
    {
        return ['og' => ['title' => '', 'description' => '', 'imageId' => null], 'x' => ['title' => '', 'description' => '', 'imageId' => null]];
    }

    public static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string)$v) : '';
    }

    public static function id(mixed $v): ?int
    {
        if (is_array($v)) {
            $v = $v['id'] ?? $v[0]['id'] ?? $v[0] ?? null;
        }

        return is_numeric($v) && (int)$v > 0 ? (int)$v : null;
    }

    /**
     * @return list<string>
     */
    public static function keywords(mixed $v): array
    {
        if (is_string($v)) {
            $v = preg_split('~\s*,\s*~', $v) ?: [];
        }
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k) {
            $k = self::str(is_array($k) ? ($k['keyword'] ?? '') : $k);
            if ($k !== '' && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function robots(mixed $v): array
    {
        if (is_string($v)) {
            $v = preg_split('~\s*,\s*~', $v) ?: [];
        }
        if (!is_array($v)) {
            return [];
        }
        $v = array_map(static fn($r) => strtolower(self::str($r)), $v);

        return array_values(array_intersect(self::ROBOTS, $v));
    }
}
