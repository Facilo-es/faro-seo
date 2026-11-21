<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Probe patterns: requests no visitor makes, so they go to the Bots tab.
 * Shipped with each release; sites add their own.
 */
final class BotList
{
    public const PATTERNS = [
        '~(^|/)wp-(login|admin|includes|content|json|config|cron)~i',
        '~(^|/)xmlrpc\.php$~i',
        '~\.(php\d?|phtml|asp|aspx|jsp|cgi|pl|env|ini|bak|old|sql|sqlite|swp|git|svn|log|yml|yaml|config|tar|gz|zip|rar|7z)$~i',
        '~(^|/)\.(env|git|svn|hg|aws|ssh|docker|vscode|idea|DS_Store|well-known/security\.txt$)~i',
        '~(^|/)(phpmyadmin|pma|myadmin|adminer|cgi-bin|vendor/phpunit|server-status|actuator|boaform|HNAP1|owa|ecp|autodiscover)(/|$)~i',
        '~(^|/)(administrator|joomla|drupal|magento|typo3|umbraco|bitrix)(/|$)~i',
        '~(\.\./|%2e%2e|/etc/passwd|<script|union\s+select|base64_)~i',
        '~(^|/)(config|backup|db|database|dump|admin|install|setup)\.(json|php|sql|xml|zip)$~i',
    ];

    /**
     * @param list<string> $own the site's own patterns: plain text matches as "contains", /.../ as a regex
     */
    public static function isBot(string $uri, array $own = []): bool
    {
        $path = urldecode(Paths::split($uri)[0]);
        foreach (self::PATTERNS as $p) {
            if (preg_match($p, $path) === 1) {
                return true;
            }
        }
        foreach ($own as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (strlen($p) > 2 && $p[0] === '/' && RegexRule::valid($p)) {
                if (RegexRule::match($p, $path) === true) {
                    return true;
                }
            } elseif (stripos($path, trim($p, '/')) !== false) {
                return true;
            }
        }

        return false;
    }
}
