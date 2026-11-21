<?php

namespace facilo\faro\Craft;

use facilo\faro\Core\Meta;
use facilo\faro\Plugin;
use Twig\Markup;

/** craft.faro in templates. */
class TwigVariable
{
    /**
     * Changes this page's meta: title, description, image (an asset or id),
     * robots, canonical, social.
     *
     * @param array<string, mixed> $values
     */
    public function set(array $values): void
    {
        Plugin::getInstance()->meta->set($values);
    }

    /** Adds a JSON-LD block to this page. @param array<string, mixed> $jsonLd */
    public function schema(array $jsonLd): void
    {
        Plugin::getInstance()->meta->addSchema($jsonLd);
    }

    public function meta(): Meta
    {
        return Plugin::getInstance()->meta->pageMeta();
    }

    /** The tags, for templates that print them themselves. */
    public function tags(): Markup
    {
        return new Markup(Plugin::getInstance()->meta->hook(), 'UTF-8');
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->proActive();
    }
}
