<?php

namespace facilo\faro\Craft\gql;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Asset as AssetInterface;
use facilo\faro\Craft\FieldValue;
use facilo\faro\Craft\SocialValue;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The field in GraphQL (F10), with Ether SEO's property names, so a headless
 * front end that queried Ether keeps working: title, description,
 * keywords{keyword,rating}, social{twitter,facebook}{title,description,image},
 * advanced{robots,canonical}.
 */
class SeoDataType
{
    public static function getType(): ObjectType
    {
        $name = 'Faro_SeoData';
        if ($type = GqlEntityRegistry::getEntity($name)) {
            return $type;
        }
        return GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'fields' => fn() => [
                'title' => ['type' => Type::string(), 'resolve' => fn(FieldValue $v) => (string)$v->getTitle()],
                'description' => ['type' => Type::string(), 'resolve' => fn(FieldValue $v) => (string)$v->getDescription()],
                'keywords' => ['type' => Type::listOf(self::keywordType()), 'resolve' => fn(FieldValue $v) => $v->getKeywords()],
                'social' => ['type' => self::networksType(), 'resolve' => fn(FieldValue $v) => $v->getSocial()],
                'advanced' => ['type' => self::advancedType(), 'resolve' => fn(FieldValue $v) => [
                    'robots' => $v->getMeta()->robots,
                    'canonical' => $v->getCanonical(),
                ]],
            ],
        ]));
    }

    private static function keywordType(): ObjectType
    {
        $name = 'Faro_SeoKeyword';
        return GqlEntityRegistry::getEntity($name) ?: GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'fields' => ['keyword' => Type::string(), 'rating' => Type::string()],
        ]));
    }

    private static function socialType(): ObjectType
    {
        $name = 'Faro_SeoSocialData';
        return GqlEntityRegistry::getEntity($name) ?: GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'fields' => fn() => [
                'handle' => ['type' => Type::string(), 'resolve' => fn(SocialValue $s) => $s->handle],
                'title' => ['type' => Type::string(), 'resolve' => fn(SocialValue $s) => $s->title],
                'description' => ['type' => Type::string(), 'resolve' => fn(SocialValue $s) => $s->description],
                'image' => ['type' => AssetInterface::getType(), 'resolve' => fn(SocialValue $s) => $s->getImage()],
            ],
        ]));
    }

    private static function networksType(): ObjectType
    {
        $name = 'Faro_SeoSocialNetworks';
        return GqlEntityRegistry::getEntity($name) ?: GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'fields' => fn() => [
                'twitter' => ['type' => self::socialType(), 'resolve' => fn(array $s) => $s['twitter'] ?? null],
                'facebook' => ['type' => self::socialType(), 'resolve' => fn(array $s) => $s['facebook'] ?? null],
            ],
        ]));
    }

    private static function advancedType(): ObjectType
    {
        $name = 'Faro_SeoAdvanced';
        return GqlEntityRegistry::getEntity($name) ?: GqlEntityRegistry::createEntity($name, new ObjectType([
            'name' => $name,
            'fields' => ['robots' => Type::listOf(Type::string()), 'canonical' => Type::string()],
        ]));
    }
}
