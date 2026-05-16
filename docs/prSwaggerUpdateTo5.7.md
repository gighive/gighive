# Swagger PHP Upgrade Plan (4.11.1 → 5.7.x)

## Current Situation

Current installed version:

```text
zircote/swagger-php 4.11.1
```

Composer currently allows:

```json
"zircote/swagger-php": "^4.0"
```

Dependabot opened a PR to upgrade to:

```text
5.7.7
```

However, the PR is stale and contains merge conflicts because it was left open while additional commits were made to `master`.

---

# Recommended Approach

Do NOT try to fix the old Dependabot PR.

Instead:

1. Close the old PR
2. Create a fresh branch
3. Perform the upgrade manually
4. Test the application
5. Create a new clean PR

---

# Step-by-Step TODO List

## 1. Close the Old Dependabot PR

Close the old Swagger PR in GitHub.

Reason:
- Merge conflicts
- Hundreds of changed files
- Stale branch

---

## 2. Update Local Master Branch

```bash
git checkout master
git pull origin master
```

---

## 3. Create a New Branch

```bash
git checkout -b upgrade-swagger-php-5
```

---

## 4. Update composer.json

File:

```text
ansible/roles/docker/files/apache/webroot/composer.json
```

Change this:

```json
"zircote/swagger-php": "^4.0"
```

To this:

```json
"zircote/swagger-php": "^5.7"
```

---

## 5. Run Composer Update

Go to the webroot directory:

```bash
cd ansible/roles/docker/files/apache/webroot
```

Run:

```bash
composer update zircote/swagger-php
```

This should update:
- composer.json
- composer.lock

---

## 6. Review Changes

Check modified files:

```bash
git status
```

Review changes:

```bash
git diff
```

Expected changes should mainly be:
- composer.json
- composer.lock

---

## 7. Test Swagger/OpenAPI Generation

Run the normal Swagger/OpenAPI generation command used by the project.

Verify:
- No PHP errors
- No annotation parsing errors
- OpenAPI output still generates successfully

---

## 8. Test the Application

At minimum:
- Start the application
- Verify APIs still work
- Verify Swagger documentation still loads correctly

---

## 9. Commit the Upgrade

```bash
git add composer.json composer.lock
git commit -m "Upgrade swagger-php to 5.7"
```

---

## 10. Push Branch and Create PR

```bash
git push origin upgrade-swagger-php-5
```

Create a new PR in GitHub.

---

# Important Notes

## If Composer Update Fails

Stop and investigate before proceeding.

Possible causes:
- PHP version incompatibility
- Other Composer dependency conflicts
- Swagger annotation compatibility changes

Do NOT force the upgrade if Composer reports dependency issues.

---

# Notes About Dependabot

Dependabot did NOT automatically upgrade Swagger.

Current installed version remains:

```text
4.11.1
```

Dependabot only opened a PR suggesting the upgrade.

The upgrade has not been merged into master.
