<?php

namespace facilo\faro\Craft;

use Craft;
use craft\db\Query;
use facilo\faro\Core\ForeignRedirects;
use facilo\faro\Core\Redirect;
use facilo\faro\Plugin;

/** One-click imports from Retour, Sprout and Redirect Manager (Dolphiq) tables, when they exist (F13). */
class ForeignImport
{
    private const TABLES = [
        ForeignRedirects::RETOUR => ['{{%retour_static_redirects}}'],
        ForeignRedirects::SPROUT => ['{{%sprout_redirects}}', '{{%sproutseo_redirects}}'],
        ForeignRedirects::DOLPHIQ => ['{{%dolphiq_redirects}}'],
    ];

    public const LABELS = [
        ForeignRedirects::RETOUR => 'Retour',
        ForeignRedirects::SPROUT => 'Sprout Redirects',
        ForeignRedirects::DOLPHIQ => 'Redirect Manager (Dolphiq)',
    ];

    /** @return array<string, int> source => rows found */
    public static function available(): array
    {
        $out = [];
        foreach (array_keys(self::TABLES) as $source) {
            if ($table = self::table($source)) {
                $out[$source] = (int)(new Query())->from($table)->count();
            }
        }
        return $out;
    }

    /** @return list<Redirect> */
    public static function rules(string $source): array
    {
        $table = self::table($source);
        if (!$table) {
            return [];
        }
        $q = (new Query())->select(['r.*'])->from(['r' => $table]);
        // Sprout and Dolphiq redirects are elements: the site and status live on the element.
        if ($source !== ForeignRedirects::RETOUR) {
            $q->addSelect(['es.siteId', 'enabled' => 'e.enabled'])
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.id]]')
                ->leftJoin(['es' => '{{%elements_sites}}'], '[[es.elementId]] = [[r.id]]')
                ->where(['e.dateDeleted' => null]);
        }
        $allowed = Plugin::getInstance()->redirects->allowedCodes();
        $rules = [];
        foreach ($q->each() as $row) {
            if ($r = ForeignRedirects::fromRow($source, $row, $allowed)) {
                $rules[] = $r;
            }
        }
        return $rules;
    }

    private static function table(string $source): ?string
    {
        $db = Craft::$app->getDb();
        foreach (self::TABLES[$source] ?? [] as $t) {
            if ($db->tableExists($t)) {
                return $t;
            }
        }
        return null;
    }
}
