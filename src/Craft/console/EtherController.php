<?php

namespace facilo\faro\Craft\console;

use craft\console\Controller;
use facilo\faro\Plugin;
use yii\console\ExitCode;

/**
 * Move from Ether SEO on the command line.
 *
 * - `faro-seo/ether/scan`: what will move, what to check, what won't.
 * - `faro-seo/ether/move`: everything, where admin changes are allowed.
 * - `faro-seo/ether/data`: page values, redirects and sitemap rows only, for
 *   production after the field and settings came over in project config.
 */
class EtherController extends Controller
{
    public function actionScan(): int
    {
        $mover = Plugin::getInstance()->mover;
        if (!$mover->etherFound()) {
            $this->stdout("No Ether SEO data found.\n");
            return ExitCode::OK;
        }
        $this->stdout($mover->markdown($mover->scan()));
        return ExitCode::OK;
    }

    public function actionMove(): int
    {
        return $this->run2(fn($p) => Plugin::getInstance()->mover->move($p));
    }

    public function actionData(): int
    {
        return $this->run2(fn($p) => Plugin::getInstance()->mover->data($p));
    }

    private function run2(callable $job): int
    {
        if (!Plugin::getInstance()->mover->etherFound()) {
            $this->stderr("No Ether SEO data found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout("Back up the database first: the backup is the undo.\n");
        $counts = $job(fn(float $p, string $label) => $this->stdout(sprintf("%3d%% %s\n", (int)round($p * 100), $label)));
        foreach ($counts as $k => $v) {
            if (is_int($v)) {
                $this->stdout("$k: $v\n");
            }
        }
        return ExitCode::OK;
    }
}
