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
    /** @var array<string, list<array{id:string, type:string, norm:string, tokens:list<string>}>>|null exact-norm => candidates */
    private ?array $byNorm = null;

    /** @var list<array{id:string, type:string, norm:string, tokens:list<string>}>|null flat list for containment */
    private ?array $all = null;

    public function resolve(string $title, string $type): ?string
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
        foreach ($this->byNorm[$want] ?? [] as $c) {
            if ($c['type'] === $type) {
                return $c['id'];
            }
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
        $matchedIds = [];
        foreach ($this->all as $c) {
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
        if ($this->all !== null) {
            return;
        }
        $this->byNorm = [];
        $this->all = [];
        DB::table('streaming_titles')->select('id', 'title', 'show_type')
            ->orderBy('id')->chunk(5000, function ($rows) {
                foreach ($rows as $r) {
                    $norm = $this->norm($r->title);
                    if ($norm === '') {
                        continue;
                    }
                    $c = [
                        'id' => $r->id,
                        'type' => $r->show_type,
                        'norm' => $norm,
                        'tokens' => $this->tokenize($r->title),
                    ];
                    $this->byNorm[$c['norm']][] = $c;
                    $this->all[] = $c;
                }
            });
    }
}
