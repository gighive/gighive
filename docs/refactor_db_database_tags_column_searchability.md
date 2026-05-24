# Refactor: Tags Column Boolean Search Support

## Problem

The Tags column search box in `db/database.php` only supports simple substring matching (a single term). All other searchable columns support the full boolean operator syntax:

- `|` = OR
- `&` = AND
- `!` = NOT (highest precedence)
- Example: `.mp4&water&!ultra&!source`

Entering `jazz&guitar` in the Tags search box currently searches for the literal string `jazz&guitar` as a single tag name — it does not return assets tagged with both "jazz" AND "guitar".

## Root Cause

### Why other columns are easy

Other columns (`source_relpath`, `media_info`, etc.) are single cells on the same row. A multi-term boolean expression collapses into multiple `LIKE`/`NOT LIKE` conditions on a single field:

```sql
-- "water&!source" on source_relpath
LOWER(a.source_relpath) LIKE '%water%'
AND LOWER(a.source_relpath) NOT LIKE '%source%'
```

### Why tags are different

Each tag is a **separate row** in the `tags` table. An asset tagged "jazz", "guitar", "loud" has 3 rows. A single `EXISTS` subquery with two `AND LIKE` conditions would **never** match because no single tag row can simultaneously be both "jazz" and "guitar":

```sql
-- This never matches — one row can't be two different tags
EXISTS (SELECT 1 FROM taggings tg2 JOIN tags t2 ...
        WHERE ... AND t2.name LIKE '%jazz%' AND t2.name LIKE '%guitar%')
```

Each boolean term must get its **own** `EXISTS` or `NOT EXISTS` subquery:

```sql
-- Correct: one subquery per term
EXISTS (SELECT 1 FROM taggings tg2 JOIN tags t2 ... WHERE ... AND t2.name LIKE '%jazz%')
AND
EXISTS (SELECT 1 FROM taggings tg2 JOIN tags t2 ... WHERE ... AND t2.name LIKE '%guitar%')
```

## Files to Change

| File | Location of tag filter block |
|------|------------------------------|
| `ansible/roles/docker/files/apache/webroot/src/Repositories/AssetRepository.php` | Lines ~116–122 (used by librarian/asset view) |
| `ansible/roles/docker/files/apache/webroot/src/Repositories/EventRepository.php` | Lines ~128–134 (used by event view) |

Both repositories have identical tag filter logic and must both be updated.

## Before (current — both repositories)

```php
$tagRaw = trim((string)($filters['tag'] ?? ''));
if ($tagRaw !== '') {
    $where[]             = 'EXISTS (SELECT 1 FROM taggings tg2 JOIN tags t2 ON t2.id = tg2.tag_id'
                         . ' WHERE tg2.target_type = \'asset\' AND tg2.target_id = a.asset_id'
                         . ' AND LOWER(t2.name) LIKE LOWER(:tag_name))';
    $params[':tag_name'] = '%' . $tagRaw . '%';
}
```

Single EXISTS, no boolean parsing. `jazz&guitar` is treated as a literal string.

## After (new — both repositories)

```php
$tagRaw = trim((string)($filters['tag'] ?? ''));
if ($tagRaw !== '') {
    if ($hasInvalidEmptyTerm($tagRaw)) {
        $errors[] = 'Search for column "tag" contains empty terms around "|" or "&". Please remove extra operators.';
    } else {
        $orParts    = array_values(array_filter(array_map('trim', explode('|', $tagRaw))));
        $orExprs    = [];
        $totalTerms = 0;
        $valid      = true;
        foreach ($orParts as $orIdx => $orRaw) {
            $andParts = array_values(array_filter(array_map('trim', explode('&', $orRaw))));
            $andExprs = [];
            foreach ($andParts as $andIdx => $term) {
                $negated = false;
                $term    = trim($term);
                if ($term === '!' || str_starts_with($term, '!!')) {
                    $errors[] = 'Search for column "tag" contains an invalid NOT term. Use !term (e.g. !electric).';
                    $valid    = false;
                    break 2;
                }
                if (str_starts_with($term, '!')) {
                    $negated = true;
                    $term    = trim(substr($term, 1));
                    if ($term === '') {
                        $errors[] = 'Search for column "tag" contains an invalid NOT term. Use !term (e.g. !electric).';
                        $valid    = false;
                        break 2;
                    }
                }
                $param      = ':tag_' . $orIdx . '_' . $andIdx;
                $existsSql  = ($negated ? 'NOT ' : '')
                            . 'EXISTS (SELECT 1 FROM taggings tg2 JOIN tags t2 ON t2.id = tg2.tag_id'
                            . ' WHERE tg2.target_type = \'asset\' AND tg2.target_id = a.asset_id'
                            . ' AND LOWER(t2.name) LIKE LOWER(' . $param . '))';
                $andExprs[]     = $existsSql;
                $params[$param] = '%' . $term . '%';
                $totalTerms++;
            }
            if (!empty($andExprs)) {
                $orExprs[] = '(' . implode(' AND ', $andExprs) . ')';
            }
        }
        if ($valid && $totalTerms > $maxTermsPerField) {
            $errors[] = 'Search for column "tag" has too many terms (max ' . $maxTermsPerField . ').';
        } elseif ($valid && !empty($orExprs)) {
            $where[] = '(' . implode(' OR ', $orExprs) . ')';
        }
    }
}
```

## SQL Generated for Example Inputs

| Input | Generated SQL |
|-------|--------------|
| `jazz` | `EXISTS(... LIKE '%jazz%')` |
| `jazz&guitar` | `EXISTS(... LIKE '%jazz%') AND EXISTS(... LIKE '%guitar%')` |
| `jazz&!electric` | `EXISTS(... LIKE '%jazz%') AND NOT EXISTS(... LIKE '%electric%')` |
| `jazz\|blues` | `EXISTS(... LIKE '%jazz%') OR EXISTS(... LIKE '%blues%')` |
| `jazz&guitar\|blues&!electric` | `(EXISTS('%jazz%') AND EXISTS('%guitar%')) OR (EXISTS('%blues%') AND NOT EXISTS('%electric%'))` |

## Testing

1. Tag a video with "jazz" and "guitar" separately.
2. Search Tags for `jazz` — should return that video.
3. Search Tags for `jazz&guitar` — should return that video.
4. Search Tags for `jazz&!blues` — should return that video (has jazz, does not have blues).
5. Search Tags for `jazz&blues` — should return nothing (video has jazz but not blues).
6. Search Tags for `jazz|blues` — should return videos tagged with either.
7. Confirm invalid inputs (`jazz&&guitar`, `!`, `jazz|`) show an error message.
