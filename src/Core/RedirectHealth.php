<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Checks on a redirect list: self and loop refusal on save, chains and how
 * to collapse them, and rules nobody has used for a year.
 */
final class RedirectHealth
{
    public const MAX_HOPS = 10;

    public const OK = 'ok';
    public const SELF = 'self';
    public const LOOP = 'loop';
    public const BAD_REGEX = 'badRegex';

    /**
     * Whether a new or edited rule can be saved next to the others.
     *
     * @param list<Redirect> $others the site's other rules, in order
     */
    public static function validate(Redirect $rule, array $others): string
    {
        if ($rule->matchType === Redirect::REGEX && RegexRule::compile($rule->source) === null) {
            return self::BAD_REGEX;
        }
        if ($rule->isGone() || Paths::isAbsolute($rule->destination)) {
            return self::OK;
        }
        $dest = Paths::normalize($rule->destination);
        $self = new RedirectMatcher([$rule]);
        if ($rule->matchType !== Redirect::REGEX && $self->find($dest) !== null) {
            return self::SELF;
        }
        if ($rule->matchType === Redirect::EXACT && strcasecmp(Paths::split($rule->source)[0], Paths::split($dest)[0]) === 0) {
            return self::SELF;
        }
        $all = array_values(array_filter($others, static fn(Redirect $r) => $r->id === null || $r->id !== $rule->id));
        $all[] = $rule;

        return self::follow($dest, new RedirectMatcher($all), Paths::split($rule->source)[0]) === null ? self::LOOP : self::OK;
    }

    /**
     * Follows a path through the rules. Returns the hops taken, or null for a loop.
     *
     * @return list<RedirectMatch>|null
     */
    public static function follow(string $start, RedirectMatcher $matcher, ?string $origin = null): ?array
    {
        $seen = [mb_strtolower(Paths::split($start)[0]) => true];
        if ($origin !== null) {
            $seen[mb_strtolower($origin)] = true;
        }
        $hops = [];
        $at = $start;
        for ($i = 0; $i < self::MAX_HOPS; $i++) {
            $m = $matcher->find($at);
            if ($m === null || $m->isAbsolute() || $m->redirect->isGone()) {
                return $hops;
            }
            $hops[] = $m;
            $at = Paths::normalize($m->destination);
            $key = mb_strtolower(Paths::split($at)[0]);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
        }

        return null;
    }

    /**
     * Chains among exact and prefix rules: each rule whose destination is
     * itself redirected, with where it should point instead.
     *
     * @param list<Redirect> $rules one site's rules, in order
     * @return list<array{rule: Redirect, hops: int, final: string}>
     */
    public static function chains(array $rules): array
    {
        $matcher = new RedirectMatcher($rules);
        $out = [];
        foreach ($rules as $rule) {
            if (!$rule->enabled || $rule->matchType === Redirect::REGEX || $rule->isGone() || Paths::isAbsolute($rule->destination)) {
                continue;
            }
            $hops = self::follow($rule->destination, $matcher, Paths::split($rule->source)[0]);
            if ($hops === null || $hops === []) {
                continue;
            }
            $out[] = ['rule' => $rule, 'hops' => count($hops) + 1, 'final' => end($hops)->destination];
        }

        return $out;
    }

    /**
     * Loops anywhere in the list, regex rules included (the full check).
     * Tries each rule's own source, and for regex rules the given sample paths.
     *
     * @param list<Redirect> $rules
     * @param list<string> $samples
     * @return list<Redirect> the rules a loop starts from
     */
    public static function loops(array $rules, array $samples = []): array
    {
        $matcher = new RedirectMatcher($rules);
        $out = [];
        foreach ($rules as $rule) {
            if (!$rule->enabled) {
                continue;
            }
            $starts = $rule->matchType === Redirect::REGEX ? [...self::regexSamples($rule->source), ...$samples] : [Paths::split($rule->source)[0]];
            foreach ($starts as $start) {
                $first = $matcher->find($start);
                if ($first === null || $first->redirect !== $rule) {
                    continue;
                }
                if (self::follow($start, $matcher) === null) {
                    $out[] = $rule;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Paths a regex rule may match, made from the pattern itself, so a loop shows
     * even when no page address matches it: each group becomes "1" or "a".
     *
     * @return list<string>
     */
    public static function regexSamples(string $pattern): array
    {
        $p = preg_replace('/^\^|\$$/', '', $pattern) ?? $pattern;
        $out = [];
        foreach (['1', 'a'] as $fill) {
            $path = preg_replace('/\((?:[^()\\\\]|\\\\.)*\)[?*+]?/', $fill, $p) ?? $p;
            $path = preg_replace('/\\\\(.)/', '$1', $path) ?? $path;
            if (!preg_match('/[\[\](){}|*+?^$]/', $path)) {
                $out[] = $path;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Rules with no hit for 12 months (or never hit and older than that).
     *
     * @param list<array{rule: Redirect, lastHit: ?\DateTimeImmutable, created: \DateTimeImmutable}> $rows
     * @return list<Redirect>
     */
    public static function unused(array $rows, \DateTimeImmutable $now): array
    {
        $cut = $now->modify('-12 months');
        $out = [];
        foreach ($rows as $row) {
            $last = $row['lastHit'] ?? $row['created'];
            if ($last < $cut) {
                $out[] = $row['rule'];
            }
        }

        return $out;
    }
}
