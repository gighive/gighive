# UI Patterns

Reusable frontend/UI patterns used across the GigHive web layer.

---

## Pattern: Lazy Batch-Fetch for Secondary Column Data

### Problem

Paginated list views (e.g. `db/database.php`) render rows via a PHP query against
the primary entity (assets, events). Adding a secondary data column — such as AI tags
— would require either:

- **N+1 queries**: one extra query per row at render time, or
- **A JOIN on the primary query**: complicates the repository/controller layer and
  pulls in data the user may not need on every page load.

### Solution

Render the primary rows with placeholder cells carrying only a `data-asset-id`
attribute. After the page paints, a single JS `fetch` collects all visible IDs,
deduplicates them, and retrieves secondary data in one batch API call. The cells are
then populated client-side.

### How it works (step by step)

1. **PHP render**: each row emits a placeholder element with a data attribute:
   ```html
   <span class="tag-chip-cell" data-asset-id="42"></span>
   ```

2. **JS batch collect**: after DOM ready, collect all IDs from the page and
   deduplicate:
   ```js
   const ids = Array.from(document.querySelectorAll('.tag-chip-cell'))
                    .map(c => parseInt(c.dataset.assetId, 10))
                    .filter(Boolean);
   const unique = [...new Set(ids)];
   ```

3. **Single fetch**: one request for all IDs on the current page:
   ```js
   fetch('/api/tags.php?target_type=asset&asset_ids=' + unique.join(','))
   ```

4. **Fill cells**: the API returns a map of `{ asset_id: [...tags] }`. Each
   placeholder is replaced with its rendered content (or cleared if empty).

5. **Graceful failure**: `.catch()` silently clears placeholders — the page remains
   fully usable without secondary data.

### Current usage

| Page | Placeholder selector | Batch API endpoint |
|------|---------------------|--------------------|
| `src/Views/media/list.php` | `.tag-chip-cell[data-asset-id]` | `GET /api/tags.php?target_type=asset&asset_ids=…` |

### Apache access-control implication

**This is the most common oversight when using this pattern on a publicly accessible
page.**

Any page that is exempted from Basic Auth (e.g. a staging demo page) will also cause
its batch-fetch endpoint to be hit without credentials. The broad `LocationMatch` in
`ansible/roles/docker/templates/default-ssl.conf.j2` protects `/api/*` by default.

**Checklist when adding a batch-fetch endpoint to a page that has a staging public
exception:**

1. Identify which `/api/*.php` endpoint the JS fetches.
2. Add a matching `<Location "/api/your-endpoint.php">` block with `AuthMerging Off`
   and the same staging-conditional `Require all granted` pattern used for
   `/db/database.php`:

   ```apache
   <Location "/api/your-endpoint.php">
       AuthMerging Off
       <If "%{HTTP_HOST} == 'staging.gighive.app'">
           Require all granted
       </If>
       <Else>
           AuthType Basic
           AuthName "GigHive Protected"
           AuthBasicProvider file
           AuthUserFile {{ gighive_htpasswd_path | default('/etc/apache2/gighive.htpasswd') }}
           Require valid-user
       </Else>
   </Location>
   ```

3. Place the block **before** the broad `<LocationMatch>` in `default-ssl.conf.j2`
   so `AuthMerging Off` can discard the inherited auth requirement.

### When to use this pattern

- The secondary data is **not needed for the initial render** (decorative, supplemental).
- The number of rows per page is bounded (pagination already in place).
- The secondary data lives in a **separate table** from the primary entity query.

### When NOT to use this pattern

- The secondary data is needed for **sorting or filtering** (must be in the primary
  query instead).
- The secondary data is **always present** on every row — a JOIN is simpler and
  avoids the extra HTTP round-trip.
