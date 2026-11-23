<?php

namespace facilo\faro\Craft\controllers;

use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use facilo\faro\Craft\jobs\MoveJob;
use facilo\faro\Craft\utilities\MoveFromEther;
use facilo\faro\Plugin;
use yii\web\Response;

/** Utilities → Move from Ether SEO (F14): check, download the report, move. */
class UtilityController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin(false);
        $this->requirePermission('utility:' . MoveFromEther::id());
        return parent::beforeAction($action);
    }

    public function actionScan(): Response
    {
        $this->requirePostRequest();
        Plugin::getInstance()->mover->scan();
        return $this->asSuccess(Craft::t('faro-seo', 'Check done. Read what will move below.'));
    }

    public function actionReport(): Response
    {
        $mover = Plugin::getInstance()->mover;
        $report = $mover->lastReport(true) ?? $mover->scan();
        return Craft::$app->getResponse()->sendContentAsFile($mover->markdown($report), 'faro-seo-move-report.md', ['mimeType' => 'text/markdown']);
    }

    public function actionMove(): ?Response
    {
        $this->requirePostRequest();
        if (!Craft::$app->getRequest()->getBodyParam('backup')) {
            $this->setFailFlash(Craft::t('faro-seo', 'Tick “Back up the database first” to move. The backup is your undo.'));
            return null;
        }
        try {
            Craft::$app->getDb()->backup();
        } catch (\Throwable $e) {
            Craft::error('Faro backup before move failed: ' . $e->getMessage(), 'faro-seo');
            $this->setFailFlash(Craft::t('faro-seo', 'The backup failed, so nothing was moved: {error}', ['error' => $e->getMessage()]));
            return null;
        }
        Queue::push(new MoveJob());
        return $this->asSuccess(Craft::t('faro-seo', 'Backup made. The move is running in the queue.'));
    }
}
