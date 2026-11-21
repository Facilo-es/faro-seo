<?php

declare(strict_types=1);

namespace facilo\faro\tests\Core;

use facilo\faro\Core\EtherMapping;
use facilo\faro\Core\Redirect;
use facilo\faro\Core\RedirectMatcher;
use facilo\faro\Core\SeoValue;
use facilo\faro\Core\TitleFormat;
use PHPUnit\Framework\TestCase;

final class EtherTest extends TestCase
{
    private const TOKENS = [
        ['key' => '1', 'template' => '{title}', 'locked' => false],
        ['key' => '2', 'template' => ' | {{ siteName }}', 'locked' => true],
    ];

    // T42: Ether's stored field values read as Faro values.
    public function testEtherValue(): void
    {
        $ether = [
            'titleRaw' => ['1' => 'Zapatos rojos', '2' => ' | Tienda'],
            'descriptionRaw' => 'Los mejores zapatos',
            'keywords' => [['keyword' => 'zapatos', 'rating' => 'good']],
            'social' => [
                'facebook' => ['title' => 'FB', 'description' => 'FB d', 'image' => ['id' => 7]],
                'twitter' => ['title' => 'TW', 'description' => '', 'image' => null],
            ],
            'advanced' => ['robots' => ['noindex', 'bogus'], 'canonical' => 'https://x.es/c'],
        ];
        self::assertTrue(EtherMapping::isEtherShape($ether));
        $v = SeoValue::from($ether, self::TOKENS);
        self::assertTrue($v->fromEther);
        self::assertSame('Zapatos rojos', $v->title);
        self::assertSame('Los mejores zapatos', $v->description);
        self::assertSame(['zapatos'], $v->keywords);
        self::assertSame('FB', $v->social['og']['title']);
        self::assertSame(7, $v->social['og']['imageId']);
        self::assertSame('TW', $v->social['x']['title']);
        self::assertSame(['noindex'], $v->robots);
        self::assertSame('https://x.es/c', $v->canonical);
    }

    public function testFaroValueRoundTrip(): void
    {
        $v = SeoValue::from(['v' => 1, 'title' => 'T', 'description' => 'D', 'robots' => ['nofollow']]);
        self::assertFalse($v->fromEther);
        $again = SeoValue::from($v->toArray());
        self::assertSame($v->toArray(), $again->toArray());
        self::assertTrue(SeoValue::from(null)->isEmpty());
        self::assertTrue(SeoValue::from('')->isEmpty());
    }

    public function testTitleFormatDetected(): void
    {
        $f = EtherMapping::titleFormat(self::TOKENS);
        self::assertSame('|', $f->separator);
        self::assertSame(TitleFormat::AFTER, $f->position);
        self::assertSame('Zapatos | Tienda', $f->format('Zapatos', 'Tienda'));
        self::assertSame('Tienda', $f->format('Tienda', 'Tienda'));
        $before = new TitleFormat('–', TitleFormat::BEFORE);
        self::assertSame('Tienda – Zapatos', $before->format('Zapatos', 'Tienda'));
    }

    public function testUnknownTwigIsFlagged(): void
    {
        $check = [];
        $out = EtherMapping::convert('{{ entry.title }} en {{ siteName }} {{ craft.app.foo }}', $check);
        self::assertStringContainsString('{siteName}', $out);
        self::assertNotEmpty($check);
    }

    // T48: Ether redirects keep their order, code and regex meaning.
    public function testRedirectRows(): void
    {
        $r = EtherMapping::redirect(['uri' => 'old/(.*)', 'to' => 'new/$1', 'type' => '302', 'order' => 4, 'siteId' => 1]);
        self::assertSame(Redirect::REGEX, $r->matchType);
        self::assertSame(302, $r->statusCode);
        self::assertSame(4, $r->sortOrder);
        $p = EtherMapping::redirect(['uri' => '/plain-page', 'to' => '/x', 'type' => '301', 'order' => 0, 'siteId' => null]);
        self::assertSame(Redirect::EXACT, $p->matchType);
        self::assertSame(301, $p->statusCode);
    }

    public function testTitleIgnoresKeysOutsideTheTokens(): void
    {
        $tokens = [['key' => '1', 'template' => '{title}', 'locked' => false], ['key' => '2', 'template' => ' - Blog {{ siteName }}', 'locked' => true]];
        self::assertSame('Salón 1', EtherMapping::title(['titleRaw' => ['1' => 'Salón 1', 'a' => 'Salón 1', '2' => 'x']], $tokens));
        self::assertSame('A B', EtherMapping::title(['titleRaw' => ['1' => 'A', 'b' => 'B']], []));
    }

    public function testRobotsTxt(): void
    {
        $ether = "{# Sitemap URL #}\nSitemap: {{ url(seo.sitemapName ~ '.xml') }}\n\n{# Disallows #}\n{% if craft.app.config.env != 'production' %}\n\n"
            . "{# Disallow access to everything when NOT in production #}\nUser-agent: *\nDisallow: /\n\n{% else %}\n\n"
            . "{# Disallow access to cpresources/ when live #}\nUser-agent: *\nDisallow: /cpresources/\n\n{% endif %}";
        self::assertSame("Sitemap: {sitemapUrl}\n\nUser-agent: *\nDisallow: /cpresources/\n", EtherMapping::robotsTxt($ether));
        $dev = "User-agent: *\n{% if craft.app.config.general.devMode %}Disallow: /{% else %}Disallow: /carrito/\nDisallow: /cuenta/{% endif %}\n\nSitemap: {{ url(seo.sitemapName ~ '.xml') }}";
        self::assertSame("User-agent: *\nDisallow: /carrito/\nDisallow: /cuenta/\n\nSitemap: {sitemapUrl}\n", EtherMapping::robotsTxt($dev));
        $live = "{% if craft.app.config.env == 'production' %}Allow: /{% endif %}\nHost: {{ siteUrl }}";
        self::assertSame("Allow: /\nHost: {siteUrl}\n", EtherMapping::robotsTxt($live));
        self::assertStringContainsString('{% for', EtherMapping::robotsTxt("{% for s in sites %}x{% endfor %}"));
        $check = [];
        EtherMapping::settings(['robotsTxt' => $ether], $check);
        self::assertSame([], $check);
    }

    public function testRedirectSitePrefix(): void
    {
        $prefixes = [1 => '', 2 => 'en', 3 => 'en/uk'];
        $site = EtherMapping::redirect(['uri' => '/en/offer-1/', 'to' => 'blog/a', 'siteId' => 2], $prefixes);
        self::assertSame('offer-1', $site->source);
        self::assertSame(2, $site->siteId);
        $all = EtherMapping::redirect(['uri' => 'EN/UK/old', 'to' => 'new', 'siteId' => null], $prefixes);
        self::assertSame('old', $all->source);
        self::assertSame(3, $all->siteId);
        $home = EtherMapping::redirect(['uri' => 'en', 'to' => 'x', 'siteId' => 2], $prefixes);
        self::assertSame('', $home->source);
        $other = EtherMapping::redirect(['uri' => 'english/page', 'to' => 'x', 'siteId' => null], $prefixes);
        self::assertSame('english/page', $other->source);
        self::assertNull($other->siteId);
        $wrongSite = EtherMapping::redirect(['uri' => 'en/page', 'to' => 'x', 'siteId' => 1], $prefixes);
        self::assertSame('en/page', $wrongSite->source);
        $regex = EtherMapping::redirect(['uri' => '^en/(.*)$', 'to' => 'x/$1', 'siteId' => 2], $prefixes);
        self::assertSame('^en/(.*)$', $regex->source);
        self::assertSame(Redirect::QUERY_KEEP, $regex->queryMode);
        $query = EtherMapping::redirect(['uri' => 'ref/(.*)\\?utm=.*', 'to' => 'productos/$1', 'siteId' => null]);
        self::assertSame(Redirect::QUERY_MATCH, $query->queryMode);
        $matcher = new RedirectMatcher([$query]);
        self::assertSame('productos/modelo-101', $matcher->find('ref/modelo-101?utm=x')?->destination);
        self::assertNull($matcher->find('ref/modelo-101'));
    }

    public function testSettings(): void
    {
        $check = [];
        $s = EtherMapping::settings([
            'sitemapName' => 'sitemap', 'sitemapLimit' => 500, 'titleSuffix' => ' | Tienda',
            'robotsTxt' => "User-agent: *\n{{ seo.sitemapUrl }}", 'metaTemplate' => '_seo/meta',
        ], $check);
        self::assertIsArray($s);
        self::assertNotEmpty($check);
    }
}
