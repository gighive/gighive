---
description: Bug — once a guest upload is approved or rejected, the admin has no UI path to reverse the decision; fix extends moderation to allow re-approve and re-reject
---

# Problem: Guest Upload Moderation Is a One-Way Door

## Summary

Once an admin approves or rejects a guest upload in `event_qr.php`, the Action column for that row changes to `—` and no further moderation buttons appear. There is no UI path to:

- Reject a video that was previously approved (e.g. bad content reported after the initial approval)
- Approve a video that was incorrectly rejected

Direct database access was the only recovery path. This affects content safety — a flagged video showing the "Guest report" badge on an already-approved row offered a visual warning with no actionable button.

---

## Impact

- **Bad or inappropriate content cannot be removed via the UI after approval.** A guest-reported video continues to be visible in the guest gallery until an operator manually runs a SQL UPDATE.
- **Admin mistakes cannot be corrected.** An accidental approve or reject is permanent from the UI perspective.
- Token revocation does **not** fix this — token status has no effect on the moderation action gate.

---

## Symptoms

- Approving a video causes the Action column to show `—` with no Reject button.
- Rejecting a video causes the Action column to show `—` with no Approve button.
- A guest-flagged video with `moderation_status = 'approved'` displays the `⚠ Guest report` badge alongside `—` in the Action column — a warning with no available action.

---

## Root Cause

`event_qr.php` — moderation queue Action column (line ~631) — gates both Approve and Reject buttons on a single condition:

```php
<?php if ($modStatus === 'pending' || $modStatus === null): ?>
    ...Approve + Reject buttons...
<?php else: ?>
    &mdash;
<?php endif; ?>
```

Once `moderation_status` transitions to `'approved'` or `'rejected'`, this condition is `false` and both buttons disappear permanently. The first moderation decision is treated as irreversible despite no intentional design requirement for that constraint.

The existing `approve_upload` / `reject_upload` POST handler (line ~193) has **no such restriction** — it executes `UPDATE upload_jobs SET moderation_status = ?` for any valid `job_id` belonging to the event. The one-way constraint existed only in the UI gate, not in the backend.

---

## Resolution

Extend the Action column gate in the moderation queue to support re-moderation in both directions:

| `moderation_status` | Action shown | Confirm dialog |
|---------------------|-------------|----------------|
| `pending` / null | `[Approve] [Reject]` | none / "Reject this upload?" |
| `approved` | `[Reject]` | "This video has already been approved. Reject it anyway?" |
| `rejected` | `[Approve]` | "This video has already been rejected. Approve it anyway?" |

**File:** `admin/event_qr.php` — Action column in Section 3 (Guest Uploads — Moderation Queue).

Replace the single `if/else` gate with a three-branch structure:

```php
<?php if ($modStatus === 'pending' || $modStatus === null): ?>
    <!-- Approve + Reject (existing) -->
<?php elseif ($modStatus === 'approved'): ?>
    <form method="POST" ... style="display:inline">
      <input type="hidden" name="action" value="reject_upload">
      <input type="hidden" name="job_id" value="...">
      <button type="submit" class="btn-sm btn-danger"
              onclick="return confirm('This video has already been approved. Reject it anyway?')">Reject</button>
    </form>
<?php elseif ($modStatus === 'rejected'): ?>
    <form method="POST" ... style="display:inline">
      <input type="hidden" name="action" value="approve_upload">
      <input type="hidden" name="job_id" value="...">
      <button type="submit" class="btn-sm" style="border-color:#1f7a3b"
              onclick="return confirm('This video has already been rejected. Approve it anyway?')">Approve</button>
    </form>
<?php else: ?>
    &mdash;
<?php endif; ?>
```

No schema changes. No backend handler changes. The existing `approve_upload` / `reject_upload` handler already overwrites `moderation_status` unconditionally.

---

## Verification

1. Upload a guest video → confirm Action column shows `[Approve] [Reject]` (Pending).
2. Click **Approve** → row shows `Approved` badge + `[Reject]` button only.
3. Click **Reject** (confirm dialog fires) → row shows `Rejected` badge + `[Approve]` button only.
4. Click **Approve** (confirm dialog fires) → row shows `Approved` badge + `[Reject]` button again.
5. Verify that a guest-flagged approved video shows `[Reject]` alongside the `⚠ Guest report` badge.
6. Confirm POST handler correctly updates `moderation_status` in `upload_jobs` via `SELECT moderation_status FROM upload_jobs WHERE job_id = ?`.

---

## Preventative Actions

- When gating UI buttons on a database status field, always consider whether re-moderation or reversal is a valid use case. Permanent one-way UI decisions should be explicit and intentional (e.g. token revocation is intentional — re-activating a revoked token is out of scope by design).
- Admin tools should have a correction path for human errors. If an action truly must be irreversible, add a prominent warning (e.g. the "IS PERMANENT" confirm dialog on Revoke) rather than silently preventing any future action.
