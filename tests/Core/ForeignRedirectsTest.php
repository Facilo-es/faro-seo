<?php

declare(strict_types=1);

namespace facilo\faro\tests\Core;

use facilo\faro\Core\ForeignRedirects;
use facilo\faro\Core\Redirect;
use PHPUnit\Framework\TestCase;

// T66 (pure part): rows from Retour, Sprout and Dolphiq map to the same rules.
final class ForeignRedirectsTest extends TestCase
{
    public function testRetour(): void
    {
        $r = ForeignRedirects::fromRow(ForeignRedirects::RETOUR, [
            'redirectSrcUrl' => 'https://example.com/old-page/', 'redirectDestUrl' => '/new-page',
            'redirectHttpCode' => '302', 'redirectMatchType' => 'exactmatch', 'siteId' => '2', 'enabled' => '1',
        ]);
        self::assertNotNull($r);
        self::assertSame('old-page', $r->source);
        self::assertSame('/new-page', $r->destination);
        self::assertSame(302, $r->statusCode);
        self::assertSame(Redirect::EXACT, $r->matchType);
        self::assertSame(2, $r->siteId);

        $x = ForeignRedirects::fromRow(ForeignRedirects::RETOUR, [
            'redirectSrcUrl' => '^/blog/(\d+)$', 'redirectDestUrl' => '/news/$1',
            'redirectHttpCode' => 301, 'redirectMatchType' => 'regexmatch', 'siteId' => null, 'enabled' => 0,
        ]);
        self::assertSame(Redirect::REGEX, $x->matchType);
        self::assertSame('^/blog/(\d+)$', $x->source);
        self::assertNull($x->siteId);
        self::assertFalse($x->enabled);
    }

    public function testSproutAndDolphiq(): void
    {
        $s = ForeignRedirects::fromRow(ForeignRedirects::SPROUT, ['oldUrl' => 'old-page', 'newUrl' => 'new-page', 'statusCode' => 301]);
        $d = ForeignRedirects::fromRow(ForeignRedirects::DOLPHIQ, ['sourceUrl' => '/old-page', 'destinationUrl' => 'new-page', 'statusCode' => '301']);
        self::assertSame([$s->source, $s->statusCode], [$d->source, $d->statusCode]);
        self::assertSame('old-page', $s->source);

        $legacy = ForeignRedirects::fromRow(ForeignRedirects::SPROUT, ['oldUrl' => 'a/(.*)', 'newUrl' => 'b/$1', 'method' => 302, 'regex' => 1]);
        self::assertSame(Redirect::REGEX, $legacy->matchType);
        self::assertSame(302, $legacy->statusCode);
    }

    public function testSkipsWhatCannotMove(): void
    {
        self::assertNull(ForeignRedirects::fromRow(ForeignRedirects::SPROUT, ['oldUrl' => '', 'newUrl' => '/x', 'statusCode' => 301]));
        self::assertNull(ForeignRedirects::fromRow(ForeignRedirects::SPROUT, ['oldUrl' => 'missing', 'newUrl' => '', 'statusCode' => 404]));
        $odd = ForeignRedirects::fromRow(ForeignRedirects::DOLPHIQ, ['sourceUrl' => 'a', 'destinationUrl' => 'b', 'statusCode' => 399]);
        self::assertSame(301, $odd->statusCode);
        $gone = ForeignRedirects::fromRow(ForeignRedirects::RETOUR, ['redirectSrcUrl' => 'gone', 'redirectDestUrl' => '', 'redirectHttpCode' => 410]);
        self::assertSame(410, $gone->statusCode);
        // A Pro-only code becomes 301 when the edition doesn't allow it.
        $lite = ForeignRedirects::fromRow(ForeignRedirects::RETOUR, ['redirectSrcUrl' => 'g', 'redirectDestUrl' => '/h', 'redirectHttpCode' => 308], [301, 302]);
        self::assertSame(301, $lite->statusCode);
    }
}
