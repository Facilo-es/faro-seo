<?php

declare(strict_types=1);

namespace facilo\faro\tests\Core;

use facilo\faro\Core\Indexing;
use facilo\faro\Core\MetaInput;
use facilo\faro\Core\MetaResolver;
use facilo\faro\Core\SitemapUrl;
use facilo\faro\Core\SitemapWriter;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase
{
    // T20: index, pages and valid urlset XML.
    public function testIndexAndUrlset(): void
    {
        self::assertSame(3, SitemapWriter::pages(1001, 500));
        self::assertSame(0, SitemapWriter::pages(0, 500));
        $index = SitemapWriter::index([['loc' => 'https://x.es/sitemap-blog-1.xml', 'lastmod' => new \DateTimeImmutable('2026-11-01')]]);
        $x = simplexml_load_string($index);
        self::assertSame('sitemapindex', $x->getName());

        $urls = [
            new SitemapUrl('https://x.es/a?b=1&c=2', new \DateTimeImmutable('2026-11-01'), 'weekly', 0.8, ['es' => 'https://x.es/a', 'en' => 'https://x.es/en/a']),
            new SitemapUrl('https://x.es/b', null, 'nonsense', 7, []),
        ];
        $xml = SitemapWriter::urlset($urls);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml));
        self::assertStringContainsString('&amp;c=2', $xml);
        self::assertStringContainsString('hreflang="en"', $xml);
        self::assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        self::assertStringNotContainsString('nonsense', $xml);
        self::assertStringContainsString('<priority>1.0</priority>', $xml);
    }

    // T21: noindex pages and pages pointing elsewhere stay out.
    public function testInclude(): void
    {
        $plain = MetaResolver::resolve([new MetaInput()], 'A', 'S', 'https://x.es/a');
        self::assertTrue(SitemapWriter::include($plain, 'https://x.es/a'));
        $noindex = MetaResolver::resolve([new MetaInput(robots: ['noindex'])], 'A', 'S', 'https://x.es/a');
        self::assertFalse(SitemapWriter::include($noindex, 'https://x.es/a'));
        $elsewhere = MetaResolver::resolve([new MetaInput(canonical: 'https://x.es/b')], 'A', 'S', 'https://x.es/a');
        self::assertFalse(SitemapWriter::include($elsewhere, 'https://x.es/a'));
        self::assertTrue(SitemapWriter::sameUrl('https://x.es/a/', 'https://X.es/a'));
    }

    // T23: non-production blocks indexing; robots.txt points at the sitemap.
    public function testIndexing(): void
    {
        self::assertTrue(Indexing::blocksIndexing('staging', 'production'));
        self::assertFalse(Indexing::blocksIndexing('production', ['production', 'live']));
        self::assertFalse(Indexing::blocksIndexing('live', ['production', 'live']));
        $txt = Indexing::defaultRobotsTxt('https://x.es/sitemap.xml');
        self::assertStringContainsString('Sitemap: https://x.es/sitemap.xml', $txt);
        self::assertStringContainsString('Disallow: /', Indexing::defaultRobotsTxt('https://x.es/sitemap.xml', true));
        self::assertSame("Sitemap: https://x.es/s.xml\nHost: https://x.es/", Indexing::robotsTxt("Sitemap: {sitemapUrl}\nHost: {siteUrl}", 'https://x.es/s.xml', 'https://x.es/'));
        self::assertSame('<https://x.es/a>; rel="canonical"', Indexing::linkHeader('https://x.es/a'));
    }
}
