<?php

namespace facilo\faro\Craft\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use facilo\faro\Core\MetaRenderer;
use facilo\faro\Plugin;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * faroMeta(uri, siteId | site): the page's final tags, for headless sites (F10).
 * `html` is ready to print in <head>; `tags` lists each tag's name and attributes.
 */
class FaroQueries
{
    /** @return array<string, array<string, mixed>> */
    public static function getQueries(): array
    {
        return [
            'faroMeta' => [
                'type' => self::metaType(),
                'args' => [
                    'uri' => ['type' => Type::string(), 'description' => 'The page path, such as "blog/hello". Empty for the home page.'],
                    'siteId' => Type::int(),
                    'site' => ['type' => Type::string(), 'description' => 'Site handle'],
                ],
                'resolve' => [self::class, 'resolve'],
                'description' => 'The final SEO tags of a page, as Faro SEO prints them.',
            ],
        ];
    }

    /** @return array{html: string, tags: list<array{tag: string, attributes: string}>}|null */
    public static function resolve(mixed $root, array $args): ?array
    {
        $sites = Craft::$app->getSites();
        $site = isset($args['siteId']) ? $sites->getSiteById((int)$args['siteId'])
            : (isset($args['site']) ? $sites->getSiteByHandle((string)$args['site']) : $sites->getPrimarySite());
        if ($site === null) {
            return null;
        }
        $uri = trim((string)($args['uri'] ?? ''), '/');
        $element = Craft::$app->getElements()->getElementByUri($uri === '' ? '__home__' : $uri, (int)$site->id, true);
        $meta = Plugin::getInstance()->meta;
        $tags = $meta->buildTags($element, $meta->forElement($element));
        return [
            'html' => MetaRenderer::html($tags),
            'tags' => array_map(static fn(array $t) => [
                'tag' => $t[0],
                'attributes' => json_encode($t[1], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ], $tags),
        ];
    }

    private static function metaType(): ObjectType
    {
        $tag = GqlEntityRegistry::getEntity('Faro_Tag') ?: GqlEntityRegistry::createEntity('Faro_Tag', new ObjectType([
            'name' => 'Faro_Tag',
            'fields' => ['tag' => Type::string(), 'attributes' => ['type' => Type::string(), 'description' => 'JSON object of the attributes']],
        ]));
        return GqlEntityRegistry::getEntity('Faro_Meta') ?: GqlEntityRegistry::createEntity('Faro_Meta', new ObjectType([
            'name' => 'Faro_Meta',
            'fields' => ['html' => Type::string(), 'tags' => Type::listOf($tag)],
        ]));
    }
}
