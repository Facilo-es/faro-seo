<?php

declare(strict_types=1);

namespace facilo\faro\tests\Core;

use facilo\faro\Core\JsonLd;
use facilo\faro\Core\MetaInput;
use facilo\faro\Core\MetaRenderer;
use facilo\faro\Core\MetaResolver;
use facilo\faro\Core\ShareImage;
use facilo\faro\Core\TitleFormat;
use PHPUnit\Framework\TestCase;

final class MetaTest extends TestCase
{
    private function resolve(array $layers, ?\DateTimeImmutable $expiry = null)
    {
        return MetaResolver::resolve($layers, 'Zapatos rojos', 'Tienda', 'https://x.es/zapatos', $expiry, new TitleFormat('|'));
    }

    // T1-T3: entry value first, then section defaults, then site defaults.
    public function testFallbacks(): void
    {
        $m = $this->resolve([new MetaInput(), new MetaInput(description: 'Sección {title}'), new MetaInput(description: 'Sitio', imageId: 9)]);
        self::assertSame('Zapatos rojos | Tienda', $m->title);
        self::assertSame('Sección Zapatos rojos', $m->description);
        self::assertSame(9, $m->imageId);
        self::assertSame('https://x.es/zapatos', $m->canonical);
        // Share titles fall back to the full page title, as Ether did (T49).
        self::assertSame('Zapatos rojos | Tienda', $m->social['og']['title']);
        self::assertSame('Sección Zapatos rojos', $m->social['x']['description']);

        $own = $this->resolve([new MetaInput(title: 'Mi título', description: 'Mía', canonical: 'https://x.es/c'), new MetaInput(description: 'Sitio')]);
        self::assertSame('Mi título | Tienda', $own->title);
        self::assertSame('Mía', $own->description);
        self::assertSame('https://x.es/c', $own->canonical);
    }

    // T4: descriptions are cut to 160 at a word.
    public function testDescriptionCut(): void
    {
        $m = $this->resolve([new MetaInput(description: str_repeat('palabra ', 40))]);
        self::assertLessThanOrEqual(160, mb_strlen($m->description));
        self::assertStringEndsNotWith(' ', $m->description);
    }

    // T5: robots and expiry.
    public function testRobots(): void
    {
        $m = $this->resolve([new MetaInput(robots: ['noindex', 'nofollow'])], new \DateTimeImmutable('2027-01-01 00:00:00 UTC'));
        self::assertTrue($m->isNoindex());
        $html = MetaRenderer::html(MetaRenderer::tags($m, 'Tienda', 'es-ES'));
        self::assertStringContainsString('content="noindex, nofollow"', $html);
        self::assertStringContainsString('unavailable_after:', $html);
        self::assertStringContainsString('og:locale" content="es_ES"', $html);
    }

    // T6: escaping, no empty tags, hreflang with x-default, JSON-LD safe in a script.
    public function testRender(): void
    {
        $m = $this->resolve([new MetaInput(title: 'A "quoted" <b>title</b>')]);
        $tags = MetaRenderer::tags(
            $m, 'Tienda', 'es',
            ['og' => new ShareImage('https://x.es/i.jpg', 1200, 630, 'Alt', 'image/jpeg')],
            ['es' => 'https://x.es/zapatos', 'en' => 'https://x.es/en/shoes'],
            'https://x.es/zapatos',
            '', '@tienda',
            [JsonLd::website('Tienda', 'https://x.es/'), ['@context' => 'https://schema.org', 'name' => '</script><script>alert(1)']],
        );
        $html = MetaRenderer::html($tags);
        self::assertStringContainsString('&quot;quoted&quot; &lt;b&gt;', $html);
        self::assertStringNotContainsString('content=""', $html);
        self::assertStringContainsString('hreflang="x-default"', $html);
        self::assertStringContainsString('hreflang="en"', $html);
        self::assertStringContainsString('og:image:width" content="1200"', $html);
        self::assertStringContainsString('twitter:site" content="@tienda"', $html);
        self::assertStringNotContainsString('</script><script>', $html);
        self::assertStringNotContainsString('fb:app_id', $html);
    }

    public function testTitlePattern(): void
    {
        $f = new TitleFormat(pattern: '{title} — compra en {siteName}');
        self::assertSame('Zapatos — compra en Tienda', $f->format('Zapatos', 'Tienda'));
        self::assertSame('Zapatos', (new TitleFormat(position: TitleFormat::NONE))->format('Zapatos', 'Tienda'));
    }

    public function testBreadcrumbs(): void
    {
        $b = JsonLd::breadcrumbs('https://x.es/', 'Tienda', 'calzado/zapatos', ['calzado' => 'Calzado', 'calzado/zapatos' => 'Zapatos']);
        self::assertSame('BreadcrumbList', $b['@type']);
        self::assertCount(3, $b['itemListElement']);
    }

    // A canonical typed as a path, as Ether allowed, becomes absolute on the page's host.
    public function testRelativeCanonical(): void
    {
        self::assertSame('https://x.es/blog/x', $this->resolve([new MetaInput(canonical: '/blog/x')])->canonical);
        self::assertSame('https://x.es/blog/x', $this->resolve([new MetaInput(canonical: 'blog/x')])->canonical);
        self::assertSame('//cdn.es/x', $this->resolve([new MetaInput(canonical: '//cdn.es/x')])->canonical);
        self::assertSame('https://y.es/x', $this->resolve([new MetaInput(canonical: 'https://y.es/x')])->canonical);
    }
}
