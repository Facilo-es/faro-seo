<?php

declare(strict_types=1);

namespace facilo\faro\tests\Core;

use facilo\faro\Core\Csv;
use facilo\faro\Core\Paths;
use facilo\faro\Core\Redirect;
use facilo\faro\Core\RedirectHealth;
use facilo\faro\Core\RedirectMatcher;
use facilo\faro\Core\RegexRule;
use PHPUnit\Framework\TestCase;

final class RedirectTest extends TestCase
{
    public function testPathsNormalize(): void
    {
        self::assertSame('blog/post', Paths::normalize('https://example.com//blog/post/'));
        self::assertSame('blog/post?a=1', Paths::normalize('/blog/post?a=1'));
        self::assertSame(['blog', 'a=1'], Paths::split('blog?a=1'));
        self::assertSame('https://x.es/a', Paths::withoutQuery('https://x.es/a?utm=1#f'));
    }

    // T30: exact, prefix and regex rules, first in order wins.
    public function testExactPrefixRegex(): void
    {
        $m = new RedirectMatcher([
            new Redirect('/old-page', '/new-page'),
            new Redirect('/shop', '/store', Redirect::PREFIX),
            new Redirect('blog/(\d+)/(.*)', '/news/$2', Redirect::REGEX, 302),
        ]);
        $r = $m->find('/Old-Page/');
        self::assertNotNull($r);
        self::assertSame('/new-page', $r->destination);
        self::assertSame(301, $r->statusCode);
        self::assertSame('/store/shoes/red', $m->find('/shop/shoes/red')->destination);
        self::assertNull($m->find('/shopping'));
        $x = $m->find('/blog/2023/hola');
        self::assertSame('/news/hola', $x->destination);
        self::assertSame(302, $x->statusCode);
        self::assertNull($m->find('/nothing'));
    }

    // T31: query string keep / drop / match.
    public function testQueryModes(): void
    {
        $m = new RedirectMatcher([
            new Redirect('/a', '/b'),
            new Redirect('/c', '/d', queryMode: Redirect::QUERY_DROP),
            new Redirect('/e?x=1', '/f', queryMode: Redirect::QUERY_MATCH),
        ]);
        self::assertSame('/b?utm=1', $m->find('/a?utm=1')->destination);
        self::assertSame('/d', $m->find('/c?utm=1')->destination);
        self::assertSame('/f', $m->find('/e?x=1')->destination);
        self::assertNull($m->find('/e?x=2'));
    }

    public function testDisabledAndGone(): void
    {
        $m = new RedirectMatcher([
            new Redirect('/off', '/x', enabled: false),
            new Redirect('/gone', '', statusCode: 410),
        ]);
        self::assertNull($m->find('/off'));
        $g = $m->find('/gone?a=1');
        self::assertSame(410, $g->statusCode);
        self::assertTrue($g->redirect->isGone());
    }

    // T32: a redirect to itself, a loop and a bad regex are refused.
    public function testHealth(): void
    {
        self::assertSame(RedirectHealth::SELF, RedirectHealth::validate(new Redirect('/a', '/a'), []));
        self::assertSame(RedirectHealth::LOOP, RedirectHealth::validate(new Redirect('/b', '/a'), [new Redirect('/a', '/b')]));
        self::assertSame(RedirectHealth::BAD_REGEX, RedirectHealth::validate(new Redirect('blog/(', '/x', Redirect::REGEX), []));
        self::assertSame(RedirectHealth::OK, RedirectHealth::validate(new Redirect('/c', '/d'), [new Redirect('/a', '/b')]));
    }

    public function testChains(): void
    {
        $chains = RedirectHealth::chains([new Redirect('/a', '/b'), new Redirect('/b', '/c'), new Redirect('/c', '/d')]);
        self::assertNotEmpty($chains);
        $first = $chains[0];
        self::assertSame('/d', $first['final']);
        self::assertGreaterThanOrEqual(2, $first['hops']);
    }

    public function testRegexLoopWithoutSamples(): void
    {
        $loop = new Redirect('bucle/(.*)', 'bucle/$1', Redirect::REGEX);
        $fine = new Redirect('viejo/(\d+)', 'nuevo/$1', Redirect::REGEX);
        self::assertSame([$loop], RedirectHealth::loops([$loop, $fine]));
    }

    public function testUnused(): void
    {
        $now = new \DateTimeImmutable('2026-11-18');
        $rows = [
            ['rule' => new Redirect('/a', '/b', id: 1), 'lastHit' => new \DateTimeImmutable('2025-01-01'), 'created' => new \DateTimeImmutable('2024-01-01')],
            ['rule' => new Redirect('/c', '/d', id: 2), 'lastHit' => new \DateTimeImmutable('2026-10-01'), 'created' => new \DateTimeImmutable('2024-01-01')],
        ];
        $ids = array_map(static fn($r) => $r->id, RedirectHealth::unused($rows, $now));
        self::assertSame([1], $ids);
    }

    // T33: a catastrophic regex stops instead of hanging the site.
    public function testRegexIsBounded(): void
    {
        $p = RegexRule::compile('(a+)+$');
        self::assertNotNull($p);
        $t = microtime(true);
        $r = RegexRule::match($p, str_repeat('a', 5000) . 'b');
        self::assertLessThan(2.0, microtime(true) - $t);
        self::assertNotTrue($r);
    }

    public function testEtherRegexSemantics(): void
    {
        // Ether treats every uri as a case-insensitive anchored regex.
        self::assertSame(1, preg_match(RegexRule::compile('old/(.*)'), 'OLD/x'));
        self::assertSame(0, preg_match(RegexRule::compile('old'), 'xold'));
        self::assertSame('/abc/i', RegexRule::compile('/abc/i'));
        self::assertTrue(RegexRule::looksLikeRegex('a/(.*)'));
        self::assertFalse(RegexRule::looksLikeRegex('plain/path'));
    }

    // T34: CSV round trip, Excel's semicolons, formula cells made safe.
    public function testCsv(): void
    {
        $csv = Csv::export([[new Redirect('/a', '/b'), 3], [new Redirect('=cmd', '/c', statusCode: 302), 0]]);
        self::assertStringContainsString("'=cmd", $csv);
        $back = Csv::import($csv, 1);
        self::assertCount(2, $back['rows']);
        self::assertSame('a', $back['rows'][0]->source);
        self::assertSame(302, $back['rows'][1]->statusCode);

        $semi = "source;destination;statusCode\n/x;/y;301\n/z;/w;308\n;/q;301\n";
        $r = Csv::import($semi, 1);
        self::assertCount(1, $r['rows']);
        self::assertSame(['badStatusCode', 'noSource'], array_column($r['errors'], 'reason'));
        $pro = Csv::import($semi, 1, [...Redirect::FREE_CODES, ...Redirect::PRO_CODES]);
        self::assertCount(2, $pro['rows']);

        $other = Csv::import("From,To,Code\n/a,/b,302\n", 1);
        self::assertSame(['a', '/b', 302], [$other['rows'][0]->source, $other['rows'][0]->destination, $other['rows'][0]->statusCode]);

        $big = Csv::import(str_repeat('a', Csv::MAX_BYTES + 1), 1);
        self::assertSame('tooBig', $big['errors'][0]['reason']);
    }
}
