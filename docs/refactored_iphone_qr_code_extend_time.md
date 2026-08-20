# Feature: Extend Upload Token Expiry

## Status — 2026-07-17

**Complete.** 2026-07-17. Deployed to dev and verified working.

---

## Rationale

When an admin generates a QR upload token with the wrong expiry duration (e.g., 24 hours instead of 14 days), the token expires before all guests have had a chance to upload. Currently there is no recovery path — a new QR code must be generated and redistributed. This feature adds a one-click extension mechanism directly in the admin UI.

---

## Goal

Allow an admin to set a new expiry for an individual upload token directly from the Upload Tokens table in `event_qr.php`, without revoking and regenerating the token.

**Policy:** The new expiry is always computed from **now**, not from the current expiry date.

---

## Scope

| In scope | Out of scope |
|----------|--------------|
| Per-token expiry extension (active and expired tokens) | Extending revoked tokens |
| Same TTL options as the QR generator | Extending all tokens for an event at once |
| No-schema-change server implementation | New DB columns or tables |
| iOS app automatic pickup via existing poll | Any iOS code changes |

---

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Granularity | Per-token | Different QR codes in an event may be at different stages |
| Compute from | **Now** | "Wrong expiry" use case — admin wants N more days starting today |
| Revoked tokens | Not extendable | Revocation is intentional; Extend is for wrong-date recovery |
| UX | Inline expand | No navigation away; no modal; consistent with existing page style |
| TTL options | Same as generator | Zero new mental overhead for admin |
| Re-activate expired? | Yes | `is_active = 1` is untouched; `expires_at` update makes an expired token active again |
| Default selection | 14 days (env default) | Matches generator default (updated Jul 17 2026) |

---

## How It Will Work

1. Admin opens `event_qr.php` for an event.
2. In the **Upload Tokens** table, each active or expired token now has an **Extend** button in the Action column (alongside Revoke for active tokens).
3. Clicking **Extend** expands an inline sub-row beneath that token row containing:
   - Label: "New expiry from now:"
   - The same six TTL radio buttons (24 hours / 7 days / 14 days / 28 days / 72 days / No Expiration), with the env-default pre-selected.
   - **Set Expiry** (green) and **Cancel** buttons.
4. Submitting the form POSTs `action=extend_token` with `token_id` and `ttl_hours`.
5. Server validates inputs, updates `expires_at = NOW() + N hours` (or +100 years for No Expiration), and redirects with `?msg=extended`.
6. A green flash banner "Token expiry updated." appears at the top of the page.
7. The token row now shows the new `expires_at` and (if it was expired) reverts to **Active** status.

**iOS side:** No changes. `pollGuestRecords()` already calls `guest-status.php` which returns `days_remaining` computed from `expires_at`. The updated value reaches the iPhone within 60 seconds automatically.

---

## Wireframe — Upload Tokens Table

### Before any Extend is clicked

All sub-rows are hidden. Active tokens show both buttons; expired tokens show only Extend; revoked tokens show a dash.

```
┌────────────────┬─────────────────────┬─────────┬─────────────────────┬───────────────────┐
│ Token (prefix) │ Expires             │ Status  │ Created             │ Action            │
├────────────────┼─────────────────────┼─────────┼─────────────────────┼───────────────────┤
│ 8b83576e…      │ 2026-07-23 18:48:43 │ Active  │ 2026-07-16 18:48:43 │ [Revoke] [Extend] │
├────────────────┼─────────────────────┼─────────┼─────────────────────┼───────────────────┤
│ a3f91cd2…      │ 2026-07-17 09:00:00 │ Expired │ 2026-07-15 09:00:00 │ [Extend]          │
├────────────────┼─────────────────────┼─────────┼─────────────────────┼───────────────────┤
│ c7b22e44…      │ 2026-07-10 14:30:00 │ Revoked │ 2026-07-09 14:30:00 │ —                 │
└────────────────┴─────────────────────┴─────────┴─────────────────────┴───────────────────┘
```

### After clicking [Extend] on the first token (8b83576e…)

A sub-row appears immediately below that token row. The TTL radio buttons appear with the default (14 days) pre-selected. No page navigation — everything is inline.

```
┌────────────────┬─────────────────────┬─────────┬─────────────────────┬───────────────────┐
│ Token (prefix) │ Expires             │ Status  │ Created             │ Action            │
├────────────────┼─────────────────────┼─────────┼─────────────────────┼───────────────────┤
│ 8b83576e…      │ 2026-07-23 18:48:43 │ Active  │ 2026-07-16 18:48:43 │ [Revoke] [Extend] │
├────────────────┴─────────────────────┴─────────┴─────────────────────┴───────────────────┤
│  New expiry from now:                                                                     │
│  ○ 24 hours  ○ 7 days  ● 14 days  ○ 28 days  ○ 72 days  ○ No Expiration                 │
│                                                    [Set Expiry]  [Cancel]                 │
├────────────────┬─────────────────────┬─────────┬─────────────────────┬───────────────────┤
│ a3f91cd2…      │ 2026-07-17 09:00:00 │ Expired │ 2026-07-15 09:00:00 │ [Extend]          │
├────────────────┼─────────────────────┼─────────┼─────────────────────┼───────────────────┤
│ c7b22e44…      │ 2026-07-10 14:30:00 │ Revoked │ 2026-07-09 14:30:00 │ —                 │
└────────────────┴─────────────────────┴─────────┴─────────────────────┴───────────────────┘
```

Admin selects a duration, clicks **Set Expiry** → POST → redirect → page reloads showing the updated `Expires` timestamp and `Active` status badge. Clicking **Cancel** collapses the sub-row without any server call.

---

## Files to Change

| File | Repo | Change |
|------|------|--------|
| `admin/event_qr.php` | `gighiveinfra` | Add `extend_token` POST handler; add `$extendedMsg` flash var (reads `?msg=extended` from `$_GET`, displayed alongside `$revokedMsg`); update Action column with Extend button and inline TTL sub-row; add `toggleExtend()` JS |

**`$extendedMsg` display snippet** (alongside existing `$revokedMsg` block):
```php
$extendedMsg = trim($_GET['msg'] ?? '') === 'extended' ? 'Token expiry updated.' : '';
// ...
<?php if ($extendedMsg): ?>
  <div class="alert-ok"><?= htmlspecialchars($extendedMsg, ENT_QUOTES) ?></div>
<?php endif; ?>
```

**No other files change.** No schema changes. No iOS changes.

---

## Implementation Detail — PHP Handler

```php
} elseif ($action === 'extend_token') {
    $tokenId  = (int)($_POST['token_id'] ?? 0);
    $ttlHours = (int)($_POST['ttl_hours'] ?? -1);
    if ($tokenId <= 0) {
        $postMsg = 'Invalid token ID.';
        $postOk  = false;
    } elseif (!in_array($ttlHours, [0, 24, 168, 336, 672, 1728], true)) {
        $postMsg = 'Invalid expiry value.';
        $postOk  = false;
    } else {
        try {
            $newExpiry = $ttlHours === 0
                ? (new \DateTime())->modify('+100 years')->format('Y-m-d H:i:s')
                : (new \DateTime())->modify("+{$ttlHours} hours")->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                'UPDATE event_upload_tokens
                 SET expires_at = ?
                 WHERE token_id = ? AND event_id = ?'
            );
            $stmt->execute([$newExpiry, $tokenId, $eventId]);
            header('Location: /admin/event_qr.php?org_name=' . urlencode($orgName)
                 . '&event_date=' . urlencode($eventDate) . '&msg=extended');
            exit;
        } catch (\Throwable $e) {
            $postMsg = 'Error updating token: ' . $e->getMessage();
            $postOk  = false;
        }
    }
}
```

**Notes:**
- `WHERE token_id = ? AND event_id = ?` — prevents cross-event token manipulation.
- `is_active` is intentionally **not changed** — an expired (but not revoked) token becomes active again simply because `expires_at` is now in the future. Revoked tokens (is_active=0) cannot be extended via the UI (button not shown), and a direct POST against a revoked token would only update `expires_at` while leaving it revoked — a safe no-op.
- Allowed hours list `[0, 24, 168, 336, 672, 1728]` mirrors the `generate` action whitelist exactly.

---

## Implementation Detail — HTML Action Column (tokens table)

```php
<td>
  <?php if ($status === 'active'): ?>
    <form method="POST" ... style="display:inline">
      <input type="hidden" name="action" value="revoke">
      <input type="hidden" name="token_id" value="<?= (int)$tok['token_id'] ?>">
      <button type="submit" class="btn-danger btn-sm"
              onclick="return confirm('Revoke this token?...')">Revoke</button>
    </form>
    <button type="button" class="btn-sm" style="margin-left:.25rem"
            onclick="toggleExtend(<?= (int)$tok['token_id'] ?>)">Extend</button>
  <?php elseif ($status === 'expired'): ?>
    <button type="button" class="btn-sm"
            onclick="toggleExtend(<?= (int)$tok['token_id'] ?>)">Extend</button>
  <?php else: ?>
    &mdash;
  <?php endif; ?>
</td>
</tr>
<?php if ($status !== 'revoked'): ?>
<tr id="extend-row-<?= (int)$tok['token_id'] ?>" style="display:none">
  <td colspan="5" style="background:#0e1530;padding:.75rem 1rem">
    <form method="POST" action="..." style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <input type="hidden" name="action" value="extend_token">
      <input type="hidden" name="token_id" value="<?= (int)$tok['token_id'] ?>">
      <span style="font-weight:600;font-size:.88rem;white-space:nowrap">New expiry from now:</span>
      <div class="ttl-group" style="margin:0">
        <!-- same six options as generator, env-default pre-selected -->
      </div>
      <div style="display:flex;gap:.5rem">
        <button type="submit" class="btn-sm" style="border-color:#1f7a3b">Set Expiry</button>
        <button type="button" class="btn-sm"
                onclick="toggleExtend(<?= (int)$tok['token_id'] ?>)">Cancel</button>
      </div>
    </form>
  </td>
</tr>
<?php endif; ?>
```

---

## Implementation Detail — JS

```javascript
function toggleExtend(tokenId) {
  const row = document.getElementById('extend-row-' + tokenId);
  if (row) { row.style.display = row.style.display === 'none' ? '' : 'none'; }
}
```

Added at the top of the existing `<script>` block (before the `(function() {...})()` IIFE).

---

## Open Questions

None — all design decisions resolved before planning.

---

## Progress

### Completed
- Plan drafted and reviewed.
- Implementation in `event_qr.php` (2026-07-17).
- `group_vars` updated to 336h (14 days) across all environments (2026-07-17).
- Deployed to dev and verified working (2026-07-17).

### Remaining — This Feature
- None.

### Remaining — Follow-on Tasks
- None identified.
