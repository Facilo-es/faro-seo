<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Finds the first redirect, in sort order, for a path. Exact rules are looked
 * up in a map, so thousands of them cost one hash lookup; prefix and regex
 * rules are tried in order up to the first exact hit.
 */
final class RedirectMatcher
{
    /** @var list<Redirect> */
    private array $rules;
    /** @var array<string, int> lower-case path => first keep/drop exact rule */
    private array $byPath = [];
    /** @var array<string, int> lower-case path?query => first query-matching exact rule */
    private array $byFull = [];
    /** @var list<int> indexes of prefix and regex rules */
    private array $patterns = [];
    /** @var array<int, string|null> */
    private array $compiled = [];

    /**
     * @param list<Redirect> $rules already filtered to one site and sorted
     */
    public function __construct(array $rules)
    {
        $this->rules = array_values(array_filter($rules, static fn(Redirect $r) => $r->enabled));
        foreach ($this->rules as $i => $rule) {
            if ($rule->matchType === Redirect::EXACT) {
                if ($rule->queryMode === Redirect::QUERY_MATCH) {
                    $this->byFull[self::fullKey(...Paths::split($rule->source))] ??= $i;
                } else {
                    $this->byPath[mb_strtolower(Paths::split($rule->source)[0])] ??= $i;
                }
            } else {
                $this->patterns[] = $i;
                if ($rule->matchType === Redirect::REGEX) {
                    $this->compiled[$i] = RegexRule::compile($rule->source);
                }
            }
        }
    }

    public function find(string $uri): ?RedirectMatch
    {
        [$path, $query] = Paths::split($uri);
        $candidates = array_filter([
            $this->byPath[mb_strtolower($path)] ?? null,
            $this->byFull[self::fullKey($path, $query)] ?? null,
        ], static fn(?int $i) => $i !== null);
        $best = $candidates === [] ? null : min($candidates);

        foreach ($this->patterns as $i) {
            if ($best !== null && $i > $best) {
                break;
            }
            $match = $this->tryPattern($i, $path, $query);
            if ($match !== null) {
                return $match;
            }
        }

        if ($best === null) {
            return null;
        }
        $rule = $this->rules[$best];

        return $this->result($rule, $rule->destination, $query);
    }

    private function tryPattern(int $i, string $path, string $query): ?RedirectMatch
    {
        $rule = $this->rules[$i];
        if ($rule->matchType === Redirect::PREFIX) {
            [$src] = Paths::split($rule->source);
            $lp = mb_strtolower($path);
            $ls = mb_strtolower($src);
            if ($ls === '' || !($lp === $ls || str_starts_with($lp, $ls . '/') || (str_ends_with($ls, '/') && str_starts_with($lp, $ls)))) {
                return null;
            }
            $rest = substr($path, strlen($src));
            $dest = Paths::isAbsolute($rule->destination) ? rtrim($rule->destination, '/') : (str_starts_with($rule->destination, '/') ? '/' : '') . Paths::split(Paths::normalize($rule->destination))[0];
            $dest = $rest === '' ? $dest : $dest . '/' . ltrim($rest, '/');

            return $this->result($rule, $dest, $query);
        }

        $pattern = $this->compiled[$i] ?? null;
        if ($pattern === null) {
            return null;
        }
        $subject = $rule->queryMode === Redirect::QUERY_MATCH && $query !== '' ? $path . '?' . $query : $path;
        if (RegexRule::match($pattern, $subject) !== true) {
            return null;
        }
        $dest = RegexRule::replace($pattern, $rule->destination, $subject);
        if ($dest === null) {
            return null;
        }

        return $this->result($rule, $dest, $query);
    }

    private function result(Redirect $rule, string $dest, string $query): RedirectMatch
    {
        if ($query !== '' && $rule->queryMode === Redirect::QUERY_KEEP && !$rule->isGone()) {
            $dest .= (str_contains($dest, '?') ? '&' : '?') . $query;
        }

        return new RedirectMatch($rule, $dest, $rule->statusCode);
    }

    private static function fullKey(string $path, string $query): string
    {
        $p = mb_strtolower($path);

        return $query === '' ? $p : $p . '?' . mb_strtolower($query);
    }
}
