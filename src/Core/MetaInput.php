<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * One layer of meta values. Layers are tried in order: template override,
 * the page's SEO field, the section's fallback map, the field's defaults,
 * then the site's defaults. Empty means "ask the next layer".
 */
final class MetaInput
{
    /**
     * @param array{og?: array<string, mixed>, x?: array<string, mixed>} $social
     * @param list<string>|null $robots null means not set
     */
    public function __construct(
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly ?int $imageId = null,
        public readonly array $social = [],
        public readonly ?array $robots = null,
        public readonly string $canonical = '',
        public readonly ?TitleFormat $titleFormat = null,
    ) {
    }

    public static function fromValue(SeoValue $v): self
    {
        return new self($v->title, $v->description, null, $v->social, $v->robots === [] ? null : $v->robots, $v->canonical);
    }

    /**
     * @param array<string, mixed> $a keys title, description, image/imageId, robots, canonical, social
     */
    public static function fromArray(array $a): self
    {
        $social = [];
        foreach (['og', 'x'] as $n) {
            if (is_array($a['social'][$n] ?? null)) {
                $social[$n] = $a['social'][$n];
            }
        }

        return new self(
            Text::plain($a['title'] ?? ''),
            Text::plain($a['description'] ?? ''),
            SeoValue::id($a['imageId'] ?? $a['image'] ?? null),
            $social,
            isset($a['robots']) ? SeoValue::robots($a['robots']) : null,
            SeoValue::str($a['canonical'] ?? ''),
            isset($a['titleFormat']) && is_array($a['titleFormat']) ? TitleFormat::fromArray($a['titleFormat']) : null,
        );
    }
}
