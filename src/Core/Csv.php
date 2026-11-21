<?php

declare(strict_types=1);

namespace facilo\faro\Core;

/**
 * Redirects as CSV: source, destination, matchType, statusCode, queryMode, enabled, hits.
 */
final class Csv
{
    public const MAX_BYTES = 10485760;
    public const HEADER = ['source', 'destination', 'matchType', 'statusCode', 'queryMode', 'enabled', 'hits'];

    /** Header names other tools and spreadsheets use for the same columns. */
    private const ALIASES = [
        'from' => 'source', 'old' => 'source', 'old url' => 'source', 'origen' => 'source',
        'to' => 'destination', 'new' => 'destination', 'new url' => 'destination', 'target' => 'destination', 'destino' => 'destination',
        'code' => 'statuscode', 'status' => 'statuscode', 'status code' => 'statuscode', 'http code' => 'statuscode', 'código' => 'statuscode', 'codigo' => 'statuscode',
    ];

    /**
     * @param iterable<array{0: Redirect, 1?: int}> $rows redirect and its hits
     */
    public static function export(iterable $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, self::HEADER, ',', '"', '');
        foreach ($rows as $row) {
            $r = $row[0];
            fputcsv($h, [
                self::safe($r->source), self::safe($r->destination), $r->matchType, $r->statusCode,
                $r->queryMode, $r->enabled ? 1 : 0, (int)($row[1] ?? 0),
            ], ',', '"', '');
        }
        rewind($h);
        $out = (string)stream_get_contents($h);
        fclose($h);

        return $out;
    }

    /**
     * @param list<int> $allowedCodes codes this edition may use
     * @return array{rows: list<Redirect>, errors: list<array{line: int, reason: string}>}
     */
    public static function import(string $csv, ?int $siteId, array $allowedCodes = Redirect::FREE_CODES): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            return ['rows' => [], 'errors' => [['line' => 0, 'reason' => 'tooBig']]];
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $delim = substr_count($lines[0] ?? '', ';') > substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $cols = null;
        $rows = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, $delim, '"', ''));
            if ($cols === null) {
                $lower = array_map(static fn(string $c) => self::ALIASES[strtolower($c)] ?? strtolower($c), $cells);
                if (in_array('source', $lower, true)) {
                    $cols = array_flip($lower);
                    continue;
                }
                $cols = ['source' => 0, 'destination' => 1, 'statuscode' => 2];
            }
            $get = static fn(string $k, string $d = '') => isset($cols[strtolower($k)]) ? (string)($cells[$cols[strtolower($k)]] ?? $d) : $d;
            $source = $get('source');
            $dest = $get('destination');
            $code = (int)($get('statusCode', '301') ?: 301);
            $type = strtolower($get('matchType', Redirect::EXACT)) ?: Redirect::EXACT;
            $query = strtolower($get('queryMode', Redirect::QUERY_KEEP)) ?: Redirect::QUERY_KEEP;
            $reason = match (true) {
                $source === '' => 'noSource',
                $dest === '' && $code !== 410 => 'noDestination',
                !in_array($type, Redirect::MATCH_TYPES, true) => 'badMatchType',
                !in_array($query, Redirect::QUERY_MODES, true) => 'badQueryMode',
                !in_array($code, $allowedCodes, true) => 'badStatusCode',
                $type === Redirect::REGEX && RegexRule::compile($source) === null => 'badRegex',
                default => null,
            };
            if ($reason !== null) {
                $errors[] = ['line' => $i + 1, 'reason' => $reason];
                continue;
            }
            $enabled = !in_array(strtolower($get('enabled', '1')), ['0', 'false', 'no', 'off'], true);
            $rows[] = new Redirect($source, $dest, $type, $code, $siteId, $query, null, $enabled, count($rows));
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Stops a spreadsheet from running a cell as a formula.
     */
    private static function safe(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }
}
