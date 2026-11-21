<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * How a page title is put together: the page's own title and the site name,
 * with a separator, the site name after or before. Content can use the
 * tokens {title} and {siteName}, never Twig.
 */
final class TitleFormat
{
    public const SEPARATORS = ['|', '–', '·', '—', '/', '-'];
    public const AFTER = 'after';
    public const BEFORE = 'before';
    public const NONE = 'none';

    public function __construct(
        public readonly string $separator = '|',
        public readonly string $position = self::AFTER,
        public readonly ?string $siteName = null,
        public readonly ?string $pattern = null,
    ) {
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $pos = (string)($a['position'] ?? self::AFTER);

        return new self(
            (string)($a['separator'] ?? '|'),
            in_array($pos, [self::AFTER, self::BEFORE, self::NONE], true) ? $pos : self::AFTER,
            Text::isBlank($a['siteName'] ?? null) ? null : (string)$a['siteName'],
            Text::isBlank($a['pattern'] ?? null) ? null : (string)$a['pattern'],
        );
    }

    /**
     * @return array{separator: string, position: string, siteName: ?string, pattern: ?string}
     */
    public function toArray(): array
    {
        return ['separator' => $this->separator, 'position' => $this->position, 'siteName' => $this->siteName, 'pattern' => $this->pattern];
    }

    public function format(string $title, string $siteName): string
    {
        $site = $this->siteName ?? $siteName;
        $title = strtr($title, ['{siteName}' => $site]);
        if ($this->pattern !== null) {
            return trim(self::tokens($this->pattern, $title, $site));
        }
        if ($this->position === self::NONE || $site === '' || $title === '' || mb_strtolower($title) === mb_strtolower($site)) {
            return $title === '' ? $site : $title;
        }
        $sep = ' ' . $this->separator . ' ';

        return $this->position === self::BEFORE ? $site . $sep . $title : $title . $sep . $site;
    }

    /**
     * Replaces {title} and {siteName}; nothing else is read.
     */
    public static function tokens(string $text, string $title, string $siteName): string
    {
        return strtr($text, ['{title}' => $title, '{siteName}' => $siteName]);
    }
}
