<?php

namespace facilo\faro\Craft;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use facilo\faro\Core\BotList;
use facilo\faro\Core\NotFoundPolicy;
use facilo\faro\Core\Paths;
use facilo\faro\Core\Redirect;
use facilo\faro\Core\Suggester;
use facilo\faro\Craft\jobs\SuggestJob;
use facilo\faro\migrations\Install;
use facilo\faro\Plugin;
use yii\base\Component;

/**
 * Broken links (F11, Pro). One upsert per 404, no IP and no user agent.
 * Suggestions are worked out in a queue job, never on the 404 itself.
 */
class NotFound extends Component
{
    public function record(string $uri, ?string $referrer): void
    {
        $plugin = Plugin::getInstance();
        if (!$plugin->proActive() || !$plugin->getSettings()->log404s) {
            return;
        }
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest() || $request->getIsActionRequest()) {
            return;
        }
        $row = NotFoundPolicy::record($uri, $referrer);
        $siteId = (int)Craft::$app->getSites()->getCurrentSite()->id;
        $now = Db::prepareDateForDb(new \DateTime());
        try {
            $isNew = !(new Query())->from(Install::NOT_FOUND)->where(['siteId' => $siteId, 'uriHash' => $row['uriHash']])->exists();
            Db::upsert(Install::NOT_FOUND, [
                'siteId' => $siteId,
                'uri' => $row['uri'],
                'uriHash' => $row['uriHash'],
                'hits' => 1,
                'firstSeen' => $now,
                'lastSeen' => $now,
                'referrer' => $row['referrer'],
                'isBot' => BotList::isBot($row['uri'], $plugin->getSettings()->botPatterns),
                'status' => 'new',
                'uid' => StringHelper::UUID(),
            ], [
                // Qualified: inside PostgreSQL's ON CONFLICT a bare [[hits]] is ambiguous, and the whole upsert fails.
                'hits' => new \yii\db\Expression(Install::NOT_FOUND . '.[[hits]] + 1'),
                'lastSeen' => $now,
                'referrer' => $row['referrer'],
            ]);
            if ($isNew) {
                Queue::push(new SuggestJob(['siteId' => $siteId, 'uriHash' => $row['uriHash']]), 2048);
            }
        } catch (\Throwable $e) {
            Craft::warning('Faro could not record a 404: ' . $e->getMessage(), 'faro-seo');
        }
    }

    /** Fills in the suggested fix for one row. */
    public function suggest(int $siteId, string $uriHash): void
    {
        $row = (new Query())->from(Install::NOT_FOUND)->where(['siteId' => $siteId, 'uriHash' => $uriHash])->one();
        if (!$row || (bool)$row['isBot']) {
            return;
        }
        $s = Suggester::fromSource((string)$row['uri'], $siteId, new CraftPorts());
        Db::update(Install::NOT_FOUND, [
            'suggestion' => $s['uri'] ?? null,
            'suggestionScore' => $s['confidence'] ?? null,
        ], ['id' => $row['id']]);
    }

    /** Visitors' and bots' open rows, or the ones already fixed with a redirect. */
    public function rows(string $tab = 'visitors', int $limit = 500): array
    {
        $where = $tab === 'fixed' ? ['status' => 'handled'] : ['isBot' => $tab === 'bots', 'status' => 'new'];
        return (new Query())->from(Install::NOT_FOUND)
            ->where($where)
            ->orderBy(['hits' => SORT_DESC, 'lastSeen' => SORT_DESC])
            ->limit($limit)->all();
    }

    /** @return list<array<string, mixed>> */
    public function topThisWeek(int $limit = 5): array
    {
        return (new Query())->from(Install::NOT_FOUND)
            ->where(['isBot' => false, 'status' => 'new'])
            ->andWhere(['>=', 'lastSeen', Db::prepareDateForDb(new \DateTime('-7 days'))])
            ->orderBy(['hits' => SORT_DESC])->limit($limit)->all();
    }

    public function countThisWeek(): int
    {
        return (int)(new Query())->from(Install::NOT_FOUND)
            ->where(['isBot' => false, 'status' => 'new'])
            ->andWhere(['>=', 'lastSeen', Db::prepareDateForDb(new \DateTime('-7 days'))])->count();
    }

    /** Accept (or edit, then accept): a 301 from the broken address. Returns an error, or null. */
    public function accept(int $id, ?string $destination = null): ?string
    {
        $row = (new Query())->from(Install::NOT_FOUND)->where(['id' => $id])->one();
        if (!$row) {
            return Craft::t('faro-seo', 'That broken link is gone.');
        }
        $dest = trim((string)($destination ?? $row['suggestion'] ?? ''));
        if ($dest === '') {
            return Craft::t('faro-seo', 'Enter the address to send visitors to.');
        }
        if (!Paths::isAbsolute($dest)) {
            $dest = '/' . ltrim($dest, '/');
        }
        $res = Plugin::getInstance()->redirects->save(new Redirect((string)$row['uri'], $dest, Redirect::EXACT, 301, (int)$row['siteId']), '404');
        if (is_string($res)) {
            return $res;
        }
        // Keep where it went, for the Fixed tab.
        Db::update(Install::NOT_FOUND, ['status' => 'handled', 'suggestion' => $dest], ['id' => $id]);
        return null;
    }

    public function ignore(int $id): void
    {
        Db::update(Install::NOT_FOUND, ['status' => 'ignored'], ['id' => $id]);
    }

    /** Old rows out; at most 10,000, oldest unhandled first. Runs on Craft's garbage collection. */
    public function prune(): void
    {
        $cutoff = NotFoundPolicy::cutoff(new \DateTimeImmutable());
        Db::delete(Install::NOT_FOUND, ['<', 'lastSeen', Db::prepareDateForDb($cutoff)]);
        $count = (int)(new Query())->from(Install::NOT_FOUND)->count();
        $over = NotFoundPolicy::overflow($count);
        if ($over > 0) {
            $ids = (new Query())->select('id')->from(Install::NOT_FOUND)
                ->orderBy([new \yii\db\Expression("CASE WHEN [[status]] = 'handled' THEN 1 ELSE 0 END"), 'lastSeen' => SORT_ASC])
                ->limit($over)->column();
            Db::delete(Install::NOT_FOUND, ['id' => $ids]);
        }
    }
}
