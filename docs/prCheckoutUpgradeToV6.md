# Checkout Upgrade Plan (actions/checkout v4 → v6)

## Current Situation

There is an old Dependabot PR:

```text
Bump actions/checkout from 4 to 6
```

That PR is stale and has too many commits/files changed.

Do **not** try to resolve conflicts in the old PR.

Instead, close the old PR and perform a clean manual upgrade.

---

# Recommended Approach

1. Close the old Dependabot PR
2. Create a fresh branch from current `master`
3. Search for `actions/checkout@v4`
4. Replace it with `actions/checkout@v6`
5. Commit the change
6. Push the branch
7. Open a new clean PR
8. Confirm GitHub Actions still pass

---

# Step-by-Step TODO List

## 1. Close the Old Dependabot PR

Close the old PR:

```text
Bump actions/checkout from 4 to 6
```

Reason:
- It is stale
- It has many unrelated commits/files
- A clean manual upgrade is easier

---

## 2. Update Local Master Branch

```bash
git checkout master
git pull origin master
```

---

## 3. Create a New Branch

```bash
git checkout -b upgrade-actions-checkout-v6
```

---

## 4. Search for Existing Checkout Usage

From the root of the repo, run:

```bash
grep -R "actions/checkout@v4" .github/workflows
```

This should show one or more workflow files.

---

## 5. Edit the Workflow File(s)

Open each matching workflow file with `vi`.

Example:

```bash
vi .github/workflows/<workflow-file-name>.yml
```

Change this:

```yaml
uses: actions/checkout@v4
```

To this:

```yaml
uses: actions/checkout@v6
```

Save and exit.

---

## 6. Confirm the Change

Run:

```bash
grep -R "actions/checkout@" .github/workflows
```

Confirm that the workflow files now show:

```yaml
uses: actions/checkout@v6
```

Also confirm there are no remaining v4 references:

```bash
grep -R "actions/checkout@v4" .github/workflows
```

If this returns nothing, the v4 references are gone.

---

## 7. Review Git Changes

```bash
git status
git diff
```

Expected changes should be limited to GitHub workflow files, such as:

```text
.github/workflows/*.yml
```

---

## 8. Commit the Upgrade

```bash
git add .github/workflows
git commit -m "Upgrade actions/checkout to v6"
```

---

## 9. Push the Branch

```bash
git push origin upgrade-actions-checkout-v6
```

---

## 10. Open a New Pull Request

In GitHub:

1. Go to the repository
2. Click **Pull requests**
3. Click **New pull request**
4. Compare:
   - base: `master`
   - compare: `upgrade-actions-checkout-v6`
5. Create the PR

---

## 11. Check GitHub Actions

After opening the PR, watch the Actions/checks.

The upgrade is successful if:
- workflows run
- checks pass
- there are no checkout-related errors

---

# Important Notes

## Why Close the Dependabot PR?

The old Dependabot PR is stale and noisy.

A clean manual change is safer and easier to understand.

## Why Upgrade to v6?

`actions/checkout@v6` is the newer major version and supports GitHub's move toward Node.js 24-based actions.

## If a Workflow Fails

Do not merge the PR yet.

Open the failed workflow log and look for errors related to:

```text
actions/checkout
```

If the failure is unrelated, handle it separately.

---

# Expected Final Result

The repository should have workflow files using:

```yaml
uses: actions/checkout@v6
```

The old Dependabot PR should be closed.

The new clean PR should contain only the workflow file changes needed for this upgrade.
