<?php

declare(strict_types=1);

namespace facilo\faro\tests\Core;

use facilo\faro\Core\BotList;
use facilo\faro\Core\NotFoundPolicy;
use facilo\faro\Core\PageChecks;
use facilo\faro\Core\PreviewCut;
use facilo\faro\Core\Suggester;
use facilo\faro\Core\Text;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    // T7: the checks find the keyword with or without accents.
    public function testChecks(): void
    {
        $html = '<h1>Zapatos de montaña</h1><p>Los mejores zapatos de montana para ti.</p><img src="a.jpg" alt="zapatos"><a href="/tienda">tienda</a>';
        $r = PageChecks::run(['zapatos de montaña'], 'Zapatos de montaña baratos para toda la familia', 'Compra zapatos de montaña con envío gratis en toda España y devolución fácil en 30 días.', 'zapatos-de-montana', $html, 'x.es');
        self::assertArrayHasKey('checks', $r);
        $byId = array_map(static fn($s) => $s === PageChecks::GOOD, array_column($r['checks'], 'status', 'id'));
        self::assertTrue($byId['keywordInTitle']);
        self::assertTrue($byId['keywordInDescription']);
        self::assertTrue($byId['keywordInUrl']);
        self::assertTrue($byId['keywordInHeading']);
        self::assertTrue($byId['titleLength']);
        self::assertTrue($byId['descriptionLength']);
        self::assertFalse($byId['textLength']);
        self::assertContains($r['score'], [PageChecks::GOOD, PageChecks::FAIR, PageChecks::POOR]);
        self::assertSame('montana', PageChecks::fold('Montaña'));
    }

    public function testPreview(): void
    {
        $long = str_repeat('Zapatos de montaña ', 10);
        self::assertStringEndsWith('…', PreviewCut::title($long));
        self::assertLessThanOrEqual(PreviewCut::TITLE_PX + 15, PreviewCut::width(PreviewCut::title($long), PreviewCut::TITLE_FONT));
        self::assertSame('Corto', PreviewCut::title('Corto'));
        self::assertSame('red', PreviewCut::band($long, PreviewCut::TITLE_PX, PreviewCut::TITLE_FONT));
        self::assertSame('green', PreviewCut::band('Zapatos de montaña para toda la familia', PreviewCut::TITLE_PX, PreviewCut::TITLE_FONT));
    }

    public function testText(): void
    {
        self::assertSame('Hola mundo', Text::plain('<p>Hola&nbsp;<b>mundo</b></p>'));
        self::assertSame('uno dos', Text::cut('uno dos tres', 9));
        self::assertTrue(Text::isBlank("  \n"));
    }

    // T62/T65: suggestions for broken links.
    public function testSuggester(): void
    {
        $live = ['blog/zapatos-de-montana' => 1, 'blog/botas' => 2, 'contacto' => 3];
        $s = Suggester::suggest('/blog/zapatos-de-montanna', $live);
        self::assertSame('blog/zapatos-de-montana', $s['uri']);
        self::assertSame(Suggester::HIGH, $s['confidence']);
        self::assertNull(Suggester::suggest('/xyz/qqqqqq', $live));
        $old = Suggester::suggest('/viejo/pagina', $live, ['viejo/pagina' => 'contacto']);
        self::assertSame('contacto', $old['uri']);
        $mid = Suggester::suggest('/blog/botitas', $live);
        self::assertSame(Suggester::MEDIUM, $mid['confidence']);
        self::assertEqualsWithDelta(1.0, Suggester::ratio('ñandú', 'ñandú'), 0.001);
        self::assertEqualsWithDelta(0.8, Suggester::ratio('ñandú', 'ñandu'), 0.001);
        // A letter o for a zero beats another one-character miss, whatever order the rows come in (PostgreSQL).
        foreach ([['productos/modelo-315' => 1, 'productos/modelo-305' => 2], ['productos/modelo-305' => 2, 'productos/modelo-315' => 1]] as $rows) {
            self::assertSame('productos/modelo-305', Suggester::suggest('/productos/modelo-3o5', $rows)['uri']);
        }
        // A true tie goes to the first address in order, never to row order.
        foreach ([['blog/post-13' => 1, 'blog/post-12' => 2], ['blog/post-12' => 2, 'blog/post-13' => 1]] as $rows) {
            self::assertSame('blog/post-12', Suggester::suggest('/blog/post-1x', $rows)['uri']);
        }
    }

    public function testBotsAndPrivacy(): void
    {
        self::assertTrue(BotList::isBot('/wp-login.php'));
        self::assertTrue(BotList::isBot('/.env'));
        self::assertTrue(BotList::isBot('/xmlrpc.php'));
        self::assertTrue(BotList::isBot('/old/../../etc/passwd'));
        self::assertFalse(BotList::isBot('/blog/mi-post'));
        self::assertTrue(BotList::isBot('/promo-viejo', ['promo-']));
        self::assertTrue(BotList::isBot('/abc123', ['/^abc\d+$/']));

        $r = NotFoundPolicy::record('/Blog/X?utm=1', 'https://google.com/search?q=secret');
        self::assertSame('https://google.com/search', $r['referrer']);
        self::assertSame(40, strlen($r['uriHash']));
        self::assertSame(5, NotFoundPolicy::overflow(10005));
        self::assertSame(0, NotFoundPolicy::overflow(10));
    }
}
