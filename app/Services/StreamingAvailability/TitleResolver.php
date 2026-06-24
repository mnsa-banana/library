<?php

namespace App\Services\StreamingAvailability;

use Illuminate\Support\Facades\DB;

/**
 * Match a Netflix-discovered title to an existing streaming_title. Loads the DB
 * titles once (lazily) into a normalized index. Conservative — returns null on
 * any non-confident match (verify-kids re-validates the resulting offer anyway).
 */
class TitleResolver
{
    /**
     * The candidate data, stored ONCE. Both lookups below hold integer indices
     * into this list rather than copies of the candidate arrays.
     *
     * @var list<array{id:string, type:string, norm:string, tokens:list<string>, year:?int}>|null
     */
    private ?array $candidates = null;

    /** @var array<string, list<int>>|null exact-norm => candidate indices (Pass 1) */
    private ?array $byNorm = null;

    /** @var array<string, list<int>>|null token => candidate indices (Pass 2 inverted index) */
    private ?array $byToken = null;

    public function resolve(string $title, string $type, ?int $year = null): ?string
    {
        $this->ensureLoaded();
        $want = $this->norm($title);
        if ($want === '') {
            return null;
        }
        $wantTokens = $this->tokenize($title);
        if ($wantTokens === []) {
            return null;
        }

        // Pass 1: exact normalized title, same type only.
        //
        // A name+type can collide across distinct titles (the catalog holds four
        // "Fearless" movies). Greedily returning the first such candidate is how a
        // Netflix Kids title got stamped onto the wrong row: byNorm is built
        // orderBy('id') on a VARCHAR id, so "13144" (the 1993 adult drama) sorts
        // before "62957" (the 2020 Kids film) and won. So we collect the distinct
        // same-type exact matches and only return on a unique one; on a collision we
        // disambiguate by the NEAREST release year to the caller's year (the Netflix
        // browse supplies one). Nearest, not exact, because the years legitimately
        // drift — Netflix reports a later season for series (first_air_year ≠ that)
        // and a platform-add year for movies. We keep a uniqueness guard: only return
        // when one candidate is strictly closest; a tie (or no candidate year, or no
        // caller year) returns null so the command logs it rather than guessing —
        // mirroring Pass 2's conservative ambiguity stance.
        $exact = [];
        foreach ($this->byNorm[$want] ?? [] as $i) {
            if ($this->candidates[$i]['type'] === $type) {
                $exact[$this->candidates[$i]['id']] = $this->candidates[$i]['year']; // id => year (id is the PK)
            }
        }
        if (count($exact) === 1) {
            return array_key_first($exact);
        }
        if (count($exact) > 1) {
            return $year !== null ? $this->nearestYear($exact, $year) : null;
        }

        // Pass 2: subtitle-tolerant matching, same type.
        //
        // This deliberately diverges from NetflixKidsClient::resolveKidsVideoId(),
        // which uses raw substring containment. That resolver scans a SMALL,
        // Netflix-ranked search result set where the top hit is already the
        // intended title, so loose containment is safe. TitleResolver instead
        // scans ALL ~115k streaming_titles, where raw substring containment makes
        // mid-word collisions ("Coco" inside "Coconut", "Elemental" inside
        // "Elemental Forces") write Netflix offers onto the WRONG title and hide
        // a genuinely-new title from the unmatched log. We therefore require
        // WORD-BOUNDARY (whole-token) containment: the shorter title's tokens
        // must appear as a contiguous run of whole tokens inside the longer
        // title's tokens. We also reject ambiguity (≥2 distinct matches → null)
        // so the command logs it for human review rather than guessing.
        //
        // Candidate gathering uses the inverted token index instead of scanning
        // all ~115k titles: a whole-token contiguous-sublist match (in either
        // direction) implies the candidate shares at least one token with
        // $wantTokens, so the complete candidate set is the deduplicated union of
        // $byToken[$tok] over each $tok in $wantTokens. No real match can fall
        // outside this set, so results are identical to the old full scan; only
        // the examined set shrinks.
        $seen = [];
        foreach ($wantTokens as $tok) {
            foreach ($this->byToken[$tok] ?? [] as $i) {
                $seen[$i] = true;
            }
        }

        $matchedIds = [];
        foreach (array_keys($seen) as $i) {
            $c = $this->candidates[$i];
            if ($c['type'] !== $type) {
                continue;
            }
            $rn = $c['norm'];
            if ($rn === '') {
                continue;
            }

            // Whole-token contiguous-sublist containment (replaces raw str_contains).
            [$short, $long] = count($wantTokens) <= count($c['tokens'])
                ? [$wantTokens, $c['tokens']]
                : [$c['tokens'], $wantTokens];
            $offset = $this->sublistOffset($short, $long);
            if ($offset === null) {
                continue;
            }

            // Character length-ratio floor on the concatenated norms.
            $shorter = min(strlen($want), strlen($rn));
            $longer = max(strlen($want), strlen($rn));
            if ($shorter / $longer < 0.5) {
                continue; // too different in length
            }

            // Numeric-sequel rejection at the token boundary: if the shorter list
            // is a prefix or suffix run of the longer and the immediately-adjacent
            // extra token is purely numeric, it is a sequel ("Frozen" vs "Frozen 2").
            if (count($short) < count($long)) {
                $isPrefix = $offset === 0;
                $isSuffix = $offset + count($short) === count($long);
                if ($isPrefix && preg_match('/^\d+$/', $long[count($short)])) {
                    continue;
                }
                if ($isSuffix && preg_match('/^\d+$/', $long[$offset - 1])) {
                    continue;
                }
            }

            $matchedIds[$c['id']] = true;
        }

        // Ambiguity → null: only return on exactly one distinct matching id.
        if (count($matchedIds) === 1) {
            return array_key_first($matchedIds);
        }

        return null;
    }

    /**
     * Pick the candidate whose year is *uniquely* closest to $want among [id => year].
     * Returns null on a tie for closest, or when no candidate carries a year — never
     * guesses. A dist-0 (exact) year is just the smallest possible distance, so exact
     * matches still win when present.
     *
     * @param  array<string, ?int>  $byYear  candidate id => its release/first-air year
     */
    private function nearestYear(array $byYear, int $want): ?string
    {
        $best = null;
        $bestDist = PHP_INT_MAX;
        $tie = false;
        foreach ($byYear as $id => $year) {
            if ($year === null) {
                continue;
            }
            $dist = abs($year - $want);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $id;
                $tie = false;
            } elseif ($dist === $bestDist) {
                $tie = true;
            }
        }

        return ($best !== null && ! $tie) ? $best : null;
    }

    /**
     * Diagnostic: the exact-normalized, same-type candidates a title collides with —
     * the set Pass 1 disambiguates among. Returns [{id, year}] (empty when nothing
     * shares the exact name). Lets callers explain why a collision could not be
     * confidently resolved (e.g. Netflix year matched none of the candidates).
     *
     * @return list<array{id:string, year:?int}>
     */
    public function exactCandidates(string $title, string $type): array
    {
        $this->ensureLoaded();
        $want = $this->norm($title);
        $out = [];
        foreach ($this->byNorm[$want] ?? [] as $i) {
            if ($this->candidates[$i]['type'] === $type) {
                $out[] = ['id' => $this->candidates[$i]['id'], 'year' => $this->candidates[$i]['year']];
            }
        }

        return $out;
    }

    private function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }

    /**
     * Lowercase, split on non-alphanumerics, drop empties.
     * "We're Lalaloopsy" => ["we","re","lalaloopsy"]; "Coconut" => ["coconut"].
     *
     * @return list<string>
     */
    private function tokenize(string $s): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($s)),
            static fn (string $t): bool => $t !== '',
        ));
    }

    /**
     * Offset at which $short appears as a contiguous run of whole tokens inside
     * $long, or null if it does not. ["lalaloopsy"] is a sublist of
     * ["we","re","lalaloopsy"] (offset 2); ["coco"] is not a sublist of ["coconut"].
     *
     * @param  list<string>  $short
     * @param  list<string>  $long
     */
    private function sublistOffset(array $short, array $long): ?int
    {
        $n = count($short);
        $m = count($long);
        if ($n === 0 || $n > $m) {
            return null;
        }
        for ($i = 0; $i + $n <= $m; $i++) {
            if (array_slice($long, $i, $n) === $short) {
                return $i;
            }
        }

        return null;
    }

    private function ensureLoaded(): void
    {
        if ($this->candidates !== null) {
            return;
        }
        $this->candidates = [];
        $this->byNorm = [];
        $this->byToken = [];
        DB::table('streaming_titles')->select('id', 'title', 'show_type', 'release_year', 'first_air_year')
            ->orderBy('id')->chunk(5000, function ($rows) {
                foreach ($rows as $r) {
                    $norm = $this->norm($r->title);
                    if ($norm === '') {
                        continue;
                    }
                    $tokens = $this->tokenize($r->title);
                    // Movies carry release_year, series carry first_air_year.
                    $year = $r->show_type === 'series' ? $r->first_air_year : $r->release_year;
                    $c = [
                        'id' => $r->id,
                        'type' => $r->show_type,
                        'norm' => $norm,
                        'tokens' => $tokens,
                        'year' => $year !== null ? (int) $year : null,
                    ];
                    // Store the candidate ONCE; both indices hold its integer
                    // index $i into $this->candidates.
                    $i = count($this->candidates);
                    $this->candidates[] = $c;
                    $this->byNorm[$norm][] = $i;
                    foreach ($tokens as $tok) {
                        $this->byToken[$tok][] = $i;
                    }
                }
            });
    }
}
