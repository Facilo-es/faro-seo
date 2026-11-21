<?php

namespace facilo\faro\migrations;

use craft\db\Migration;

/** Faro's five tables (spec section 6). Ether's own tables are never touched. */
class Install extends Migration
{
    public const REDIRECTS = '{{%faro_redirects}}';
    public const SITEMAP_SOURCES = '{{%faro_sitemap_sources}}';
    public const SITEMAP_URLS = '{{%faro_sitemap_urls}}';
    public const NOT_FOUND = '{{%faro_404s}}';
    public const MOVES = '{{%faro_moves}}';

    public function safeUp(): bool
    {
        $this->createTable(self::REDIRECTS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->null(),
            'source' => $this->string(1000)->notNull(),
            'matchType' => $this->string(10)->notNull()->defaultValue('exact'),
            'destination' => $this->string(1000)->notNull()->defaultValue(''),
            'statusCode' => $this->smallInteger()->notNull()->defaultValue(301),
            'queryMode' => $this->string(5)->notNull()->defaultValue('keep'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'hits' => $this->integer()->notNull()->defaultValue(0),
            'lastHit' => $this->dateTime()->null(),
            'origin' => $this->string(12)->notNull()->defaultValue('manual'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, self::REDIRECTS, ['siteId', 'enabled', 'sortOrder']);
        $this->addForeignKey(null, self::REDIRECTS, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        $this->createTable(self::SITEMAP_SOURCES, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'sourceType' => $this->string(20)->notNull(),
            'sourceUid' => $this->uid(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'changefreq' => $this->string(10)->notNull()->defaultValue('weekly'),
            'priority' => $this->decimal(2, 1)->notNull()->defaultValue(0.5),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, self::SITEMAP_SOURCES, ['siteId', 'sourceType', 'sourceUid'], true);
        $this->addForeignKey(null, self::SITEMAP_SOURCES, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        $this->createTable(self::SITEMAP_URLS, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'url' => $this->string(2000)->notNull(),
            'changefreq' => $this->string(10)->notNull()->defaultValue('weekly'),
            'priority' => $this->decimal(2, 1)->notNull()->defaultValue(0.5),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->addForeignKey(null, self::SITEMAP_URLS, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        $this->createTable(self::NOT_FOUND, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'uri' => $this->string(2000)->notNull(),
            'uriHash' => $this->char(40)->notNull(),
            'hits' => $this->integer()->notNull()->defaultValue(1),
            'firstSeen' => $this->dateTime()->notNull(),
            'lastSeen' => $this->dateTime()->notNull(),
            'referrer' => $this->string(2000)->null(),
            'isBot' => $this->boolean()->notNull()->defaultValue(false),
            'status' => $this->string(10)->notNull()->defaultValue('new'),
            'suggestion' => $this->string(1000)->null(),
            'suggestionScore' => $this->string(6)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, self::NOT_FOUND, ['siteId', 'uriHash'], true);
        $this->createIndex(null, self::NOT_FOUND, ['status', 'isBot', 'lastSeen']);
        $this->addForeignKey(null, self::NOT_FOUND, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');

        $this->createTable(self::MOVES, [
            'id' => $this->primaryKey(),
            'source' => $this->string(20)->notNull(),
            'dryRun' => $this->boolean()->notNull()->defaultValue(false),
            'report' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        return true;
    }

    public function safeDown(): bool
    {
        foreach ([self::MOVES, self::NOT_FOUND, self::SITEMAP_URLS, self::SITEMAP_SOURCES, self::REDIRECTS] as $t) {
            $this->dropTableIfExists($t);
        }
        return true;
    }
}
