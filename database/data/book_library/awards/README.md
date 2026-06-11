# Award canon data files

Input files for `php artisan book:seed --source=award --file=database/data/book_library/awards/<slug>.json`.
The membership `list_key` is derived from the file's basename (`newbery` /
`caldecott` / `printz`).

## Format

A JSON array of entries:

```json
[
  {"title": "The Story of Mankind", "author": "Hendrik Willem van Loon", "year": 1922, "type": "winner"}
]
```

`type` is `winner` or `honor`. Every entry carries an author.

## Coverage & sources (authored 2026-06)

| File | Coverage | Primary source (ala.org) |
|---|---|---|
| `newbery.json` | 1922–2026, complete (451 entries, 105 winners) | `sites/default/files/2026-03/newbery-medals-honors-1922-present.pdf` (linked from /alsc/awardsgrants/bookmedia/newbery) |
| `caldecott.json` | 1938–2026, complete (381 entries, 89 winners) | `sites/default/files/2025-09/caldecott-medal-honors-to-present.pdf` (linked from /alsc/awardsgrants/bookmedia/caldecott) |
| `printz.json` | 2000–2026, complete (129 entries, 27 winners) | /yalsa/booklistsawards/bookawards/printzaward/previouswinners/winners (2000–2024) + ALA news releases for 2025 and 2026 |

## Conventions

- **Author attribution** follows the dedup resolver's needs (matching against
  NYT/CSM-style author credits), not the medal recipient: for Caldecott entries
  citing both an illustrator and a writer ("illustrated by X, written by Y" /
  "text: Y"), `author` is the **writer**. Single-credit picture books keep the
  author-illustrator.
- Pseudonymous credits use the name on the book (`Dr. Seuss`, `Golden
  MacDonald`); `Finders Keepers` / `The Two Reds` use `William Lipkind`.
- Obvious source typos were corrected against the same document's other
  spellings (e.g. "Jeanetter Eaton" → "Jeanette Eaton", "Brough to Life" →
  "Brought to Life"); everything else is verbatim from the ALA lists.
- Years with no honor books (e.g. Newbery 1923/1924) simply have no honor
  entries.
