<?php

namespace facilo\faro\Craft;

use Craft;
use DateTimeImmutable;
use facilo\faro\Core\Ports\Cache;
use facilo\faro\Core\Ports\Clock;
use facilo\faro\Core\Ports\ElementSource;
use facilo\faro\Core\Ports\RedirectStore;
use facilo\faro\Core\Redirect;
use facilo\faro\migrations\Install;
use yii\caching\TagDependency;

/** Craft's cache, clock and database, for the Core classes. */
final class CraftPorts implements Cache, Clock, RedirectStore, ElementSource
{
    public const TAG = 'faro-seo';

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    public function get(string $key): mixed
    {
        $value = Craft::$app->getCache()->get($key);
        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        Craft::$app->getCache()->set($key, $value, $ttl, new TagDependency(['tags' => [self::TAG]]));
    }

    public function delete(string $key): void
    {
        Craft::$app->getCache()->delete($key);
    }

    public static function clearAll(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::TAG);
    }

    public function forSite(int $siteId): array
    {
        $rows = (new \craft\db\Query())
            ->from(Install::REDIRECTS)
            ->where(['enabled' => true])
            ->andWhere(['or', ['siteId' => null], ['siteId' => $siteId]])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();
        return array_map([self::class, 'redirect'], $rows);
    }

    /** @param array<string, mixed> $row */
    public static function redirect(array $row): Redirect
    {
        return new Redirect(
            (string)$row['source'],
            (string)$row['destination'],
            (string)$row['matchType'],
            (int)$row['statusCode'],
            $row['siteId'] === null ? null : (int)$row['siteId'],
            (string)$row['queryMode'],
            (int)$row['id'],
            (bool)$row['enabled'],
            (int)$row['sortOrder'],
        );
    }

    public function liveUris(int $siteId): array
    {
        return (new \craft\db\Query())
            ->select(['uri', 'elementId'])
            ->from('{{%elements_sites}} es')
            ->innerJoin('{{%elements}} e', '[[e.id]] = [[es.elementId]]')
            ->where(['es.siteId' => $siteId, 'es.enabled' => true, 'e.enabled' => true, 'e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
            ->andWhere(['not', ['es.uri' => null]])
            ->andWhere(['not like', 'es.uri', '__temp_%', false])
            ->limit(200000)
            ->pairs();
    }

    public function earlierUris(int $siteId): array
    {
        // Redirects Faro made when a URI changed (F13) record exactly that.
        return (new \craft\db\Query())
            ->select(['source', 'destination'])
            ->from(Install::REDIRECTS)
            ->where(['origin' => 'uri-change', 'enabled' => true])
            ->andWhere(['or', ['siteId' => null], ['siteId' => $siteId]])
            ->pairs();
    }
}
