# Phase Renaming — Reviewed Execution Guide

**Status:** Plan reviewed and approved. Structural option: **A** (move Phase 5 block to after Phase 4).
**Files:** `refactor_storage_media_rest_endpoint.md` (design), `refactor_storage_media_rest_endpoint_implementation.md` (impl). Azurite doc: zero phase mentions — no changes needed.

---

## Review Findings (pre-execution audit)

**Genuine gap not in the original plan — three bare `(10A)`/`(10B)` references in the impl doc:**
Pass 1 only replaces `Phase 10A` / `Phase 10B` (with the word "Phase"). Three occurrences use bare suffixes and will be silently skipped by Pass 1 and missed by the Pass 4 verification grep. These have been added to the Pass 3 manual cleanup list below.

| impl line | Current text | After fix |
|---|---|---|
| 3532 | `Once Azure (10A) is confirmed stable:` | `Once Azure (Phase 11) is confirmed stable:` |
| 3545 | `\| PHP tus server (10A step 2) \|` | `\| PHP tus server (Phase 11 step 2) \|` |
| 3548 | `\| Local bind mount removal (10B step 5) \|` | `\| Local bind mount removal (Phase 5 step 5) \|` |

**Documentation inaccuracy in Pass 4 (no impact on execution):**
The original plan's Pass 4 comment says `grep "^### Phase [0-9]"` should return "exactly 11 lines". It will return **13** — 10 Impl Phase section headings plus 3 Build Order section headings (`### Phase 3/4/5 — MediaStorageService / TusBlockUploadService / media-stream.php`). The check is still useful; the expected count in the comment is wrong.

**Occurrence index line numbers are off by ~44 in the impl doc (no impact on execution):**
The index was built when the impl doc was ~44 lines shorter. Since Passes 1/2 are global string substitutions and Pass 3 items are text-match based (not line-targeted), this has no effect on correctness.

---

## Approved Execution Plan

### Pass 1 — Sequential global replacements (ORDER IS MANDATORY)

> `Phase 1` is a literal substring of `Phase 10A` and `Phase 10B`. Steps 1 and 2 must run first
> or those will be silently corrupted. Step 11 must be last for the same reason.

| Order | Replace | With | Final Phase |
|---|---|---|---|
| 1 | `Phase 10A` | `T2S6` | Phase 11 |
| 2 | `Phase 10B` | `T1S5` | Phase 5 |
| 3 | `Phase 9` | `T2S5` | Phase 10 |
| 4 | `Phase 8` | `T2S4` | Phase 9 |
| 5 | `Phase 7` | `T2S3` | Phase 8 |
| 6 | `Phase 6` | `T2S2` | Phase 7 |
| 7 | `Phase 5` | `T1S4` | Phase 4 |
| 8 | `Phase 4` | `T1S3` | Phase 3 |
| 9 | `Phase 3` | `T1S2` | Phase 2 |
| 10 | `Phase 2` | `T1S1` | Phase 1 |
| 11 | `Phase 1` | `T2S1` | Phase 6 — **must be last** |

Apply each substitution globally across both files before moving to the next step.

### Pass 2 — Intermediate → final (order-independent)

After Pass 1, zero `Phase N` tokens remain in either doc, so no collisions are possible.

| Replace | With | Phase Name |
|---|---|---|
| `T1S1` | `Phase 1` | Runtime config: storage backend switch and IMDS access |
| `T1S2` | `Phase 2` | PHP storage abstraction layer |
| `T1S3` | `Phase 3` | Upload ingress: PHP Block Blob streaming |
| `T1S4` | `Phase 4` | Application-mediated media streaming |
| `T1S5` | `Phase 5` | Local / VirtualBox / Baremetal cutover |
| `T2S1` | `Phase 6` | Terraform: private endpoint and disable public network access |
| `T2S2` | `Phase 7` | Thumbnails and derived media into Blob Storage |
| `T2S3` | `Phase 8` | Runtime auth: Managed Identity replaces SAS for media path |
| `T2S4` | `Phase 9` | `2bootstrap.sh` and Ansible wiring |
| `T2S5` | `Phase 10` | Admin tooling updates |
| `T2S6` | `Phase 11` | Azure blob cutover and media backfill |

### Pass 3 — Manual cleanup

#### 3a. Plural "Phases" range refs (untouched by Passes 1/2 — "Phases" plural never matched by singular substitutions)

| File | Search | Replace |
|---|---|---|
| design | `Phases 2, 3, 4, 5, 10B` | `Phases 1, 2, 3, 4, 5` |
| design | `Phases 1, 6, 7, 8, 9, 10A` | `Phases 6, 7, 8, 9, 10, 11` |
| design | `Phases 2–5:` | `Phases 1–4:` |
| design | `Phases 2–5 verified` | `Phases 1–4 verified` |
| design | `Phases 1, 6–9:` | `Phases 6–10:` |
| design | `Phases 1–9` | `Phases 6–10` |

> Note: design lines 129 and 130 both contain `Phases 2–5`; a single global replace covers both.

#### 3b. Post-pass range fixes for `Phase 1 – Phase 10B` ranges (E2)

> Pass 1 converts `Phase 1` → `T2S1` and `Phase 10B` → `T1S5`; Pass 2 resolves those to
> `Phase 6` and `Phase 5`. Search for the post-pass text.

| File | Search (post-pass text) | Replace |
|---|---|---|
| design | `Phase 6 – Phase 5 — Deployment Guide` | `Phase 1 – Phase 11 — Deployment Guide` |
| design | `Phase 6 – Phase 5)` | `Phase 1 – Phase 11)` |
| impl | `Phase 6 – Phase 5)` (×2 occurrences) | `Phase 1 – Phase 11)` |

#### 3c. Post-pass range fix for `Phase 3–5` ranges (E3)

> Pass 1 matches `Phase 3` as a substring of `Phase 3–5` → `T1S2–5`; Pass 2 resolves `T1S2`
> → `Phase 2`, leaving `Phase 2–5`. Search for the post-pass text.

| File | Search (post-pass text) | Replace |
|---|---|---|
| impl | `Phase 2–5 acceptance criteria` (×2 occurrences) | `Phase 2–4 acceptance criteria` |

#### 3d. Structural fixes (E4, E5, E7)

| File | Search | Replace |
|---|---|---|
| impl | `### Phase 10 — Migration and rollout` | `### Phase 11 — Azure migration and rollout` |
| impl | `already deployed and running from Phase 11 step 2` | `already deployed and running from Phase 3` |
| design | `Phase 5 — after Azure confirmed` | `Phase 5 — Tranche 1 final step` |

#### 3e. Gap fix — bare `(10A)` / `(10B)` in impl doc (not in original plan)

| impl line | Search | Replace |
|---|---|---|
| 3532 | `Once Azure (10A) is confirmed stable:` | `Once Azure (Phase 11) is confirmed stable:` |
| 3545 | `PHP tus server (10A step 2)` | `PHP tus server (Phase 11 step 2)` |
| 3548 | `Local bind mount removal (10B step 5)` | `Local bind mount removal (Phase 5 step 5)` |

#### 3f. Markdown anchor links — all 15 impl doc TOC entries

| impl line | Old anchor | New anchor |
|---|---|---|
| 45 | `#phase-3--mediastorageservice` | `#phase-2--mediastorageservice` |
| 46 | `#phase-4--tusblockuploadservice` | `#phase-3--tusblockuploadservice` |
| 47 | `#phase-5--media-streamphp` | `#phase-4--media-streamphp` |
| 51 | `#implementation-phases-phase-1--phase-10b` | `#implementation-phases-phase-1--phase-11` |
| 52 | `#phase-1--terraform-private-endpoint-and-disable-public-network-access` | `#phase-6--terraform-private-endpoint-and-disable-public-network-access` |
| 53 | `#phase-2--runtime-config-storage-backend-switch-and-imds-access` | `#phase-1--runtime-config-storage-backend-switch-and-imds-access` |
| 54 | `#phase-3--php-storage-abstraction-layer` | `#phase-2--php-storage-abstraction-layer` |
| 55 | `#phase-4--upload-ingress-php-block-blob-streaming-no-vm-disk-writes` | `#phase-3--upload-ingress-php-block-blob-streaming-no-vm-disk-writes` |
| 56 | `#phase-5--application-mediated-media-streaming` | `#phase-4--application-mediated-media-streaming` |
| 57 | `#phase-6--thumbnails-and-derived-media-into-blob-storage` | `#phase-7--thumbnails-and-derived-media-into-blob-storage` |
| 58 | `#phase-7--runtime-auth-managed-identity-replaces-sas-for-media-path` | `#phase-8--runtime-auth-managed-identity-replaces-sas-for-media-path` |
| 59 | `#phase-8--2bootstrapsh-and-ansible-wiring` | `#phase-9--2bootstrapsh-and-ansible-wiring` |
| 60 | `#phase-9--admin-tooling-updates` | `#phase-10--admin-tooling-updates` |
| 61 | `#phase-10--migration-and-rollout` | `#phase-11--azure-migration-and-rollout` |
| 62 | `#phase-10b--local--virtualbox--baremetal-after-azure-is-confirmed` | `#phase-5--local--virtualbox--baremetal-tranche-1-final-step` |

#### 3g. Structural move — Option A (Phase 5 block)

Cut the entire `#### Phase 5 — Local / VirtualBox / Baremetal` section (its `####` heading through its final line before the next `###` or end of file) from inside the Phase 11 parent and paste it immediately after `### Phase 4 — Application-mediated media streaming` section ends. Update the TOC entry order accordingly.

### Pass 4 — Post-rename verification

```bash
cd /Users/sodo/gighiveapp/gighiveinfra/docs

# No remaining intermediate tokens (confirms Pass 2 completed fully)
grep -n "T1S[0-9]\|T2S[0-9]" refactor_storage_media_rest_endpoint.md refactor_storage_media_rest_endpoint_implementation.md

# No remaining old A/B suffixed phases (with "Phase" prefix)
grep -n "Phase 10A\|Phase 10B" refactor_storage_media_rest_endpoint.md refactor_storage_media_rest_endpoint_implementation.md

# No remaining bare (10A) / (10B) suffixes (gap fix check)
grep -n "(10A)\|(10B)" refactor_storage_media_rest_endpoint.md refactor_storage_media_rest_endpoint_implementation.md

# No remaining bare "Phase 10 —" parent heading (should be Phase 11 now)
grep -n "Phase 10 —\|Phase 10—" refactor_storage_media_rest_endpoint_implementation.md

# Confirm new phases exist — expect 13 lines (NOT 11: 10 impl phases + 3 build order headings)
grep -n "^### Phase [0-9]" refactor_storage_media_rest_endpoint_implementation.md
```

---

# Phase Renaming Index

**Purpose:** Canonical reference of every phase mention across the two design documents before renaming.
**Scope:** `refactor_storage_media_rest_endpoint.md` (design), `refactor_storage_media_rest_endpoint_implementation.md` (impl).
**Azurite doc:** Zero phase mentions — no changes needed there.

---

## Phase Mapping Table

| Old Phase | New Phase | Tranche | Phase Name |
|---|---|---|---|
| Phase 2 | **Phase 1** | Tranche 1 | Runtime config: storage backend switch and IMDS access |
| Phase 3 | **Phase 2** | Tranche 1 | PHP storage abstraction layer |
| Phase 4 | **Phase 3** | Tranche 1 | Upload ingress: PHP Block Blob streaming |
| Phase 5 | **Phase 4** | Tranche 1 | Application-mediated media streaming |
| Phase 10B | **Phase 5** | Tranche 1 | Local / VirtualBox / Baremetal cutover |
| Phase 1 | **Phase 6** | Tranche 2 | Terraform: private endpoint and disable public network access |
| Phase 6 | **Phase 7** | Tranche 2 | Thumbnails and derived media into Blob Storage |
| Phase 7 | **Phase 8** | Tranche 2 | Runtime auth: Managed Identity replaces SAS for media path |
| Phase 8 | **Phase 9** | Tranche 2 | `2bootstrap.sh` and Ansible wiring |
| Phase 9 | **Phase 10** | Tranche 2 | Admin tooling updates |
| Phase 10A | **Phase 11** | Tranche 2 | Azure blob cutover and media backfill |

---


## Per-Phase Occurrence Index

### Old Phase 1 → New Phase 6
*(Terraform: private endpoint and disable public network access)*

**design doc:**
| Line | Context |
|---|---|
| 74 | `Phase 1 (Terraform) disables public network access...` |
| 409 | `Yes — Phase 1 Terraform changes` |
| 868 | `### Phase 1 – Phase 10B — Deployment Guide` (section heading — also a range ref) |
| 875 | `Section: "Implementation Phases (Phase 1 – Phase 10B)"` (cross-ref — also a range ref) |
| 1078 | `After Phase 1 (Terraform disables public network access)...` |
| 1080 | `Required change (Phase 1 / Phase 3):` |
| 1313 | `- [ ] Phase 1: Terraform private endpoint + disable public network access...` |

**impl doc:**
| Line | Context |
|---|---|
| 5 | TOC entry: `- [Phase 1 — Terraform: private endpoint](#phase-1--terraform-private-endpoint...)` |
| 57 | `## Implementation Phases (Phase 1 – Phase 10B)` (heading — also a range ref) |
| 58 | `### Phase 1 — Terraform: private endpoint and disable public network access` (section heading) |
| 60 | `#### Validation Checklist — Phase 1` |
| 61 | `#### Validation Checklist — Phase 1` anchor/text |
| 1627 | `Apply Terraform Phase 1 changes only after verifying blob access...` |
| 1677 | `"[T-6] Media endpoint returns 401 (not 403/500) after Phase 1 apply"` |
| 3278 | `6. Apply Terraform Phase 1 (private endpoint + disable public access)...` |
| 3468 | `**Step 6 (Terraform Phase 1 applied)**` |
| 3470 | `**T-52** — All Phase 1 checks pass. *(Run T-3, T-6 from Phase 1 checklist above.)*` |
| 3546 | `Terraform Phase 1 \| Re-apply with...` (rollback table) |

---

### Old Phase 2 → New Phase 1
*(Runtime config: storage backend switch and IMDS access)*

**design doc:**
| Line | Context |
|---|---|
| 993 | `The fix is in Phase 2 (compose change) and must be verified before Phase 3 token code is tested.` |
| 1187 | `**Required change (Phase 2 / Phase 10A):**` |
| 1314 | `- [ ] Phase 2: Runtime config, group_vars, compose IMDS fix...` |

**impl doc:**
| Line | Context |
|---|---|
| 6 | TOC entry: `- [Phase 2 — Runtime config and IMDS access](#phase-2--runtime-config...)` |
| 62 | `### Phase 2 — Runtime config: storage backend switch and IMDS access` (section heading) |
| 63 | `#### Validation Checklist — Phase 2` |
| 409 | `> **Phase 2 addendum:** The following new env vars...` |
| 410 | `> the corresponding group_vars. Add them to the Phase 2 env var block...` |

---

### Old Phase 3 → New Phase 2
*(PHP storage abstraction layer)*

**design doc:**
| Line | Context |
|---|---|
| 851 | `must be updated before Phase 3 implementation begins` |
| 993 | `...before Phase 3 token code is tested.` |
| 1080 | `Required change (Phase 1 / Phase 3):` |
| 1315 | `- [ ] Phase 3: MediaStorageService with LocalMediaBackend and AzureBlobMediaBackend` |

**impl doc:**
| Line | Context |
|---|---|
| 1 | TOC entry: `- [Phase 3 — MediaStorageService](#phase-3--mediastorageservice)` |
| 7 | TOC entry: `- [Phase 3 — PHP storage abstraction layer](#phase-3--php-storage-abstraction-layer)` |
| 54 | `### Phase 3 — MediaStorageService` (Build Order section heading) |
| 64 | `### Phase 3 — PHP storage abstraction layer` (Implementation Phases section heading) |
| 79 | `Update before implementing Phase 3:` |
| 90 | `assertion task documented in Phase 4 of the design doc...` — this refers to Phase 4 but within Phase 3 context |
| 113–129 | Build Order table: many files tagged `Phase 3` |
| 1627 | `This phase must not be applied until Phase 3 (the PHP storage service) is complete...` |
| 3426 | `Covers all Phase 3–5 acceptance criteria...` (range ref — see special handling) |
| 3645 | `Covers Phase 3–5 acceptance criteria...` (range ref — see special handling) |

---

### Old Phase 4 → New Phase 3
*(Upload ingress: PHP Block Blob streaming)*

**design doc:**
| Line | Context |
|---|---|
| 496 | `documented in Phase 4 failure table` |
| 847 | `(see Phase 4)` |
| 849 | `(schemas defined in Phase 4)` |
| 1001 | `async post-processing job in Phase 4` |
| 1037 | `The two new Phase 4 cron jobs...` |
| 1048 | `the container does not exist after Phase 4.` |
| 1049 | `dead after Phase 4.` |
| 1051 | `Required change (Phase 4):` |
| 1054 | `already specified in Phase 4 smoke test requirements` |
| 1068 | `After Phase 4, the container does not exist...` |
| 1070 | `Required change (Phase 4):` |
| 1124 | `After Phase 4, these volumes are gone.` |
| 1138 | `Required changes (Phase 4):` |
| 1213 | `Required change (Phase 4):` |
| 1316 | `- [ ] Phase 4: api/tus-upload.php + TusBlockUploadService...` |

**impl doc:**
| Line | Context |
|---|---|
| 2 | TOC entry: `- [Phase 4 — TusBlockUploadService](#phase-4--tusblockuploadservice)` |
| 8 | TOC entry: `- [Phase 4 — Upload ingress: PHP Block Blob streaming](#phase-4--upload-ingress...)` |
| 55 | `### Phase 4 — TusBlockUploadService` (Build Order section heading) |
| 65 | `### Phase 4 — Upload ingress: PHP Block Blob streaming (no VM disk writes)` (section heading) |
| 114, 119, 122–133 | Build Order table: many files tagged `Phase 4` |
| 2022 | `Before deploying Phase 4, verify the container PHP version:` |
| 2029 | `Add this as a pre-task assertion in the docker Ansible role for the Phase 4 deploy:` |
| 2091 | `must be added to default-ssl.conf.j2 as part of Phase 4` |
| 2241 | `Both tables must be added to create_media_db.sql as part of Phase 4.` |
| 2307 | `Add this check to handlePost() acceptance criteria in Phase 4.` |
| 2335 | `innodb_lock_wait_timeout pre-deployment check (required for Phase 4):` |
| 2337 | `before deploying Phase 4, alongside the PHP version check:` |
| 2357 | `apcu.enable_cli=1 pre-deployment check (required for Phase 4):` |
| 2374 | `add to the Phase 4 pre-deployment assertions line in the Progress checklist` |
| 2521 | `#### SonarQube / Best-Practice Notes — Phase 4` |
| 2528 | `#### Phase 4 Rollback Plan` |
| 2530 | `Phase 4 retires tusd from every compose template unconditionally.` |
| 2532 | `If a critical defect is found after Phase 4 deploys:` |
| 2534 | `1. Take a VM snapshot before Phase 4 deploy begins` |
| 856–860 | Code comment: `Phase 4 (design doc)` (×3) |
| 1135–1137 | Code comment: `Phase 4 (design doc)` (×3) |

---

### Old Phase 5 → New Phase 4
*(Application-mediated media streaming)*

**design doc:**
| Line | Context |
|---|---|
| 130 | `after Phases 2–5 verified` (range ref — see special handling) |
| 1007 | `The streaming endpoint in Phase 5 is the sole responsibility point.` |
| 1015 | `the backward-compat rules...deploy in Phase 5, before any bind mount removal in Phase 10A step 10` (×2 on this line) |
| 1317 | `- [ ] Phase 5: api/media-stream.php streaming endpoint...` |

**impl doc:**
| Line | Context |
|---|---|
| 3 | TOC entry: `- [Phase 5 — media-stream.php](#phase-5--media-streamphp)` |
| 9 | TOC entry: `- [Phase 5 — Application-mediated media streaming](#phase-5--application-mediated-media-streaming)` |
| 56 | `### Phase 5 — media-stream.php` (Build Order section heading) |
| 81 | `### Phase 5 — Application-mediated media streaming` (section heading) |
| 135 | Build Order table: `media-stream.php    Phase 5` |
| 2559 | `These must continue to work after Phase 5 deploys.` |
| 2573 | `New asset records written after Phase 5 should have their asset_url...` |
| 2575 | `Phase 10A step 10 prerequisite:` (also a Phase 10A ref) |
| 2577 | `Document this assumption before deploying Phase 5...` |
| 2638 | `iOS thumbnail authentication — acceptance criterion for Phase 10A step 9:` (Phase 10A ref) |
| 2648 | `Before proceeding to Phase 10A step 10, verify explicitly:` (Phase 10A ref) |
| 2651 | `before deploying Phase 5` |
| 2653 | `Add this as a checklist item in Phase 10A step 9.` (Phase 10A ref) |
| 2655 | `#### SonarQube / Best-Practice Notes — Phase 5` |
| 1333 | Code comment: `See design doc Phase 5:` |
| 1397 | Code comment: `see design doc Phase 5 for full snippet` |
| 3426 | `Covers all Phase 3–5 acceptance criteria...` (range ref — see special handling) |
| 3645 | `Covers Phase 3–5 acceptance criteria...` (range ref — see special handling) |

---

### Old Phase 6 → New Phase 7
*(Thumbnails and derived media into Blob Storage)*

**design doc:**
| Line | Context |
|---|---|
| 1318 | `- [ ] Phase 6: Thumbnail async generation and Blob storage (part of Phase 4 probe job)` |

**impl doc:**
| Line | Context |
|---|---|
| 10 | TOC entry: `- [Phase 6 — Thumbnails and derived media into Blob Storage](#phase-6--thumbnails...)` |
| 91 | `### Phase 6 — Thumbnails and derived media into Blob Storage` (section heading) |
| 92 | `**Thumbnail generation is part of the async post-processing job defined in Phase 4.**` |
| 93 | `1. Blob committed by PHP Block Blob server (Phase 4)` |
| 94 | `#### Validation Checklist — Phase 6` |

---

### Old Phase 7 → New Phase 8
*(Runtime auth: Managed Identity replaces SAS for media path)*

**design doc:**
| Line | Context |
|---|---|
| 1319 | `- [ ] Phase 7: Managed Identity token acquisition + caching verified from inside container` |

**impl doc:**
| Line | Context |
|---|---|
| 11 | TOC entry: `- [Phase 7 — Runtime auth: Managed Identity replaces SAS](#phase-7--runtime-auth...)` |
| 95 | `### Phase 7 — Runtime auth: Managed Identity replaces SAS for media path` (section heading) |
| 96 | `#### Validation Checklist — Phase 7` |
| 699 | Code comment: `See Phase 7 of the design doc for the token flow and IMDS routing.` |

---

### Old Phase 8 → New Phase 9
*(`2bootstrap.sh` and Ansible wiring)*

**design doc:**
| Line | Context |
|---|---|
| 1320 | `- [ ] Phase 8: 2bootstrap.sh Terraform output extraction + Ansible variable wiring` |

**impl doc:**
| Line | Context |
|---|---|
| 12 | TOC entry: `- [Phase 8 — 2bootstrap.sh and Ansible wiring](#phase-8--2bootstrapsh-and-ansible-wiring)` |
| 97 | `### Phase 8 — 2bootstrap.sh and Ansible wiring` (section heading) |
| 98 | `#### Validation Checklist — Phase 8` |

---

### Old Phase 9 → New Phase 10
*(Admin tooling updates)*

**design doc:**
| Line | Context |
|---|---|
| 850 | `see Phase 9 admin tooling table for full description` |
| 936 | `reconciliation tool (Phase 9) detects blobs with no DB record` |
| 1003 | `This must be handled in Phase 9 tooling before the VM disk media is retired.` |
| 1155 | `Required change (Phase 9 / before Phase 10A step 10):` |
| 1321 | `- [ ] Phase 9: Admin tooling updates...must be done before Phase 10A step 10...` |

**impl doc:**
| Line | Context |
|---|---|
| 13 | TOC entry: `- [Phase 9 — Admin tooling updates](#phase-9--admin-tooling-updates)` |
| 99 | `### Phase 9 — Admin tooling updates` (section heading) |
| 100 | `#### Validation Checklist — Phase 9` |
| 101 | `#### Validation Checklist — Phase 9` anchor/text |
| 2422 | `Add a "Probe job queue" row to the Phase 9 admin tooling table...` |
| 3194 | `Covered by T-13; duplicate reference here for Phase 9 completeness.` |

---

### Old Phase 10A → New Phase 11
*(Azure blob cutover and media backfill)*

**design doc:**
| Line | Context |
|---|---|
| 130 | `Phase 10B: VirtualBox / baremetal bind-mount cutover...` (in PR table — 10B line, not 10A) |
| 132 | `Phase 10A: Azure blob cutover and media backfill` (in PR table) |
| 194 | `All deployments today; all deployments until Phase 10A` |
| 195 | `Phase 10A only, during the backfill window` |
| 196 | `After Phase 10A backfill is verified complete` |
| 198 | `right up until Phase 10A` |
| 200 | `A rollback after Phase 10A is complete...` (×2 on this line) |
| 498 | `before Phase 10A cutover` |
| 829 | `new one-shot Phase 10A migration script` |
| 830 | `new temporary Phase 10A split-read backend...removed...after Phase 10A step 9...` (×2) |
| 1015 | `bind mounts are removed (Phase 10A step 10)` (×2 on this line) |
| 1185 | `removed after Phase 10A step 10` |
| 1187 | `Required change (Phase 2 / Phase 10A):` |
| 1244 | `In Azure mode...after Phase 10A step 10` |
| 1252 | `empty after Phase 10A step 10` |
| 1311 | `#### Azure (Phase 10A — primary)` (heading) |
| 1322 | `- [ ] Phase 10A: Deploy FallbackMediaBackend...` |
| 1344 | `Delete FallbackMediaBackend.php after Phase 10A step 9...` |

**impl doc:**
| Line | Context |
|---|---|
| 14 | TOC entry: `- [Phase 10A — Azure migration and rollout](#phase-10--migration-and-rollout)` |
| 102 | `### Phase 10 — Migration and rollout` (parent section heading) |
| 103 | `#### Phase 10A — Azure (primary validation target)` (sub-heading) |
| 104 | `6. Apply Terraform Phase 1...` (Phase 1 ref within 10A) |
| 108 | `#### Validation Checklist — Phase 10A` |
| 109 | `Covers all Phase 3–5 acceptance criteria...` (range ref) |
| 110 | `**Step 6 (Terraform Phase 1 applied)**` (Phase 1 ref within 10A) |
| 111 | `T-52 — All Phase 1 checks pass. *(Run T-3, T-6 from Phase 1 checklist above.)*` (Phase 1 ref within 10A) |
| 292 | `// TEMPORARY — remove after Phase 10A step 9...` (code comment) |
| 332 | `**Backfill plan (Phase 10A step 8):**` |
| 344 | `// Run once during Phase 10A step 8...` (code comment) |
| 2575 | `Phase 10A step 10 prerequisite:` |
| 2638 | `acceptance criterion for Phase 10A step 9:` |
| 2648 | `Before proceeding to Phase 10A step 10, verify explicitly:` |
| 2653 | `Add this as a checklist item in Phase 10A step 9.` |
| 3534 | `LocalFileTusBackend is already deployed and running from Phase 10A step 2` |

---

### Old Phase 10B → New Phase 5
*(Local / VirtualBox / Baremetal cutover)*

**design doc:**
| Line | Context |
|---|---|
| 130 | `Phase 10B: VirtualBox / baremetal bind-mount cutover, after Phases 2–5 verified` (PR table) |
| 868 | `### Phase 1 – Phase 10B — Deployment Guide` (range ref — see special handling) |
| 875 | `Section: "Implementation Phases (Phase 1 – Phase 10B)"` (range ref — see special handling) |
| 1324 | `#### Local / VirtualBox / Baremetal (Phase 10B — after Azure confirmed)` |
| 1326 | `- [ ] Phase 10B: Deploy LocalMediaBackend read path...` |

**impl doc:**
| Line | Context |
|---|---|
| 15 | TOC entry: `- [Phase 10B — Local / VirtualBox / Baremetal](#phase-10b--local--virtualbox--baremetal...)` |
| 43 | Code comment: `see Phase 10B of the design doc.` |
| 112 | `#### Phase 10B — Local / VirtualBox / Baremetal (after Azure is confirmed)` (sub-heading) |
| 113 | `LocalFileTusBackend is already deployed and running from Phase 10A step 2 — no new upload code` |
| 114 | `...new RewriteRule routes for /audio/ and /video/ (added in Phase 5)...` (Phase 5 ref within 10B) |
| 115 | `Terraform Phase 1 \| Re-apply with...` (rollback table) |
| 116 | `#### Validation Checklist — Phase 10B` |
| 117 | `Covers Phase 3–5 acceptance criteria...` (range ref) |
| 732 | Code comment: `see Phase 10B of the design doc.` |

---

## Summary Counts

| Old Phase | New Phase | design doc hits | impl doc hits | Total |
|---|---|---|---|---|
| Phase 1 | Phase 6 | 7 | 11 | 18 |
| Phase 2 | Phase 1 | 3 | 5 | 8 |
| Phase 3 | Phase 2 | 4 | 15+ | 19+ |
| Phase 4 | Phase 3 | 15 | 20+ | 35+ |
| Phase 5 | Phase 4 | 4 | 14 | 18 |
| Phase 6 | Phase 7 | 1 | 4 | 5 |
| Phase 7 | Phase 8 | 1 | 4 | 5 |
| Phase 8 | Phase 9 | 1 | 3 | 4 |
| Phase 9 | Phase 10 | 5 | 6 | 11 |
| Phase 10A | Phase 11 | 17 | 14 | 31 |
| Phase 10B | Phase 5 | 5 | 7 | 12 |
| Range refs | various | 7 | 5 | 12 |
| **Total** | | **70** | **108+** | **178+** |

---

## Renaming Strategy Notes

> **Superseded by the Two-Pass Intermediate approach below.** The single-pass high-to-low strategy was the original plan but it cannot avoid collisions because the renumbering is a permutation, not a shift — some phases go up (Phase 1 → Phase 6, Phase 6 → Phase 7) and some go down (Phase 2 → Phase 1, Phase 10B → Phase 5). The two-pass approach below is the correct execution plan.

---

## Execution Plan: Two-Pass Intermediate Rename

The renumbering is a **permutation**, not a shift. Working high-to-low alone still creates collisions because target numbers are already occupied. The solution is to use a collision-free intermediate label in Pass 1, then resolve to final phase numbers in Pass 2.

### Pass 1 — Old phase labels → Intermediate labels

Intermediate tokens (`T1S1`–`T2S6`) do not appear anywhere in the docs today, so no collision is possible.

> **⚠ E1 — ORDER IS MANDATORY, NOT OPTIONAL.**
> `Phase 1` is a literal substring of `Phase 10A` and `Phase 10B`. Applying `Phase 1 → T2S1`
> before `Phase 10A` and `Phase 10B` are already converted silently corrupts them
> (e.g. `Phase 10B` → `T2S10B` instead of `T1S5`). Each substitution must be applied
> **sequentially in the exact order listed below**, never in parallel or out of order.

| Order | Old Phase | Intermediate | Tranche | Final Phase |
|---|---|---|---|---|
| 1 | Phase 10A | `T2S6` | Tranche 2 | Phase 11 |
| 2 | Phase 10B | `T1S5` | Tranche 1 | Phase 5 |
| 3 | Phase 9 | `T2S5` | Tranche 2 | Phase 10 |
| 4 | Phase 8 | `T2S4` | Tranche 2 | Phase 9 |
| 5 | Phase 7 | `T2S3` | Tranche 2 | Phase 8 |
| 6 | Phase 6 | `T2S2` | Tranche 2 | Phase 7 |
| 7 | Phase 5 | `T1S4` | Tranche 1 | Phase 4 |
| 8 | Phase 4 | `T1S3` | Tranche 1 | Phase 3 |
| 9 | Phase 3 | `T1S2` | Tranche 1 | Phase 2 |
| 10 | Phase 2 | `T1S1` | Tranche 1 | Phase 1 |
| 11 | Phase 1 | `T2S1` | Tranche 2 | Phase 6 — **must be last** |

### Pass 2 — Intermediate labels → Final phase numbers

After Pass 1 there are zero remaining `Phase N` tokens in the docs, so nothing can conflict. Pass 2 substitutions are order-independent (all intermediates start with `T`, none is a substring of another).

| Intermediate | Final Phase | Phase Name |
|---|---|---|
| `T1S1` | Phase 1 | Runtime config: storage backend switch and IMDS access |
| `T1S2` | Phase 2 | PHP storage abstraction layer |
| `T1S3` | Phase 3 | Upload ingress: PHP Block Blob streaming |
| `T1S4` | Phase 4 | Application-mediated media streaming |
| `T1S5` | Phase 5 | Local / VirtualBox / Baremetal cutover |
| `T2S1` | Phase 6 | Terraform: private endpoint and disable public network access |
| `T2S2` | Phase 7 | Thumbnails and derived media into Blob Storage |
| `T2S3` | Phase 8 | Runtime auth: Managed Identity replaces SAS for media path |
| `T2S4` | Phase 9 | `2bootstrap.sh` and Ansible wiring |
| `T2S5` | Phase 10 | Admin tooling updates |
| `T2S6` | Phase 11 | Azure blob cutover and media backfill |

### Pass 3 — Manual cleanup

These items cannot be resolved by token substitution and must be fixed individually **after** Passes 1 and 2 are complete. Search strings in the "Text after Passes 1/2" column reflect what the files will actually contain at that point — not the original text.

**Range references using `Phases` (plural) — untouched by Passes 1/2, original text survives:**

| File | Line | Search text (original, unchanged after P1/P2) | Replace with |
|---|---|---|---|
| design | 113 | `Phases 2, 3, 4, 5, 10B` | `Phases 1, 2, 3, 4, 5` |
| design | 119 | `Phases 1, 6, 7, 8, 9, 10A` | `Phases 6, 7, 8, 9, 10, 11` |
| design | 129 | `Phases 2–5:` | `Phases 1–4:` |
| design | 130 | `Phases 2–5 verified` | `Phases 1–4 verified` |
| design | 131 | `Phases 1, 6–9:` | `Phases 6–10:` |
| design | 198 | `Phases 1–9` | `Phases 6–10` |

> Note: design lines 129 and 130 both contain `Phases 2–5`; a global replace covers both.

**Range references using `Phase` (singular) — partially or fully transformed by Passes 1/2:**

> **E2:** `Phase 1 – Phase 10B` ranges: Pass 1 converts `Phase 1` → `T2S1` and `Phase 10B` → `T1S5`;
> Pass 2 converts those to `Phase 6` and `Phase 5`. Search for the **post-pass** text.

| File | Line | Text after Passes 1/2 (search for this) | Replace with |
|---|---|---|---|
| design | 868 | `Phase 6 – Phase 5 — Deployment Guide` | `Phase 1 – Phase 11 — Deployment Guide` |
| design | 875 | `Phase 6 – Phase 5)` | `Phase 1 – Phase 11)` |
| impl | 51 | `Phase 6 – Phase 5)` | `Phase 1 – Phase 11)` |
| impl | 57 | `Phase 6 – Phase 5)` | `Phase 1 – Phase 11)` |

> **E3:** `Phase 3–5` (singular): Pass 1 matches `Phase 3` as a substring → `T1S2–5`; Pass 2
> converts `T1S2` → `Phase 2`, leaving `Phase 2–5`. Search for the **post-pass** text.

| File | Line | Text after Passes 1/2 (search for this) | Replace with |
|---|---|---|---|
| impl | 3426 | `Phase 2–5 acceptance criteria` | `Phase 2–4 acceptance criteria` |
| impl | 3645 | `Phase 2–5 acceptance criteria` | `Phase 2–4 acceptance criteria` |

**Structural fix — bare `Phase 10` parent heading (E4):**

> `### Phase 10 — Migration and rollout` at impl line 3267 uses bare `Phase 10` with no A/B suffix.
> Pass 1 has no entry for it; it survives both passes unchanged, then conflicts with new Phase 10
> (Admin tooling). Must be renamed manually.

| File | Line | Search text (unchanged after P1/P2) | Replace with |
|---|---|---|---|
| impl | 3267 | `### Phase 10 — Migration and rollout` | `### Phase 11 — Azure migration and rollout` |

**Wording fix — Phase 10B context label (E7):**

> After Passes 1/2, `Phase 10B — after Azure confirmed` → `Phase 5 — after Azure confirmed`.
> The qualifier `after Azure confirmed` is now wrong (Phase 5 runs *before* Azure in the new
> tranche order). Fix the description at that point.

| File | Line | Text after Passes 1/2 (search for this) | Replace with |
|---|---|---|---|
| design | 1324 | `Phase 5 — after Azure confirmed` | `Phase 5 — Tranche 1 final step` |

**Content logic correction — impl line 3534 (E5):**

> After renaming, this line reads: `LocalFileTusBackend is already deployed and running from
> Phase 11 step 2 — no new upload code`. This is semantically wrong: Phase 5 (new) is in
> Tranche 1 and runs *before* Phase 11 (Tranche 2). At the time Phase 5 runs, Phase 11
> has not yet happened. The correct reference is Phase 3 (new), where `LocalFileTusBackend`
> was actually deployed.

| File | Line | Text after Passes 1/2 (search for this) | Replace with |
|---|---|---|---|
| impl | 3534 | `already deployed and running from Phase 11 step 2` | `already deployed and running from Phase 3` |

**Markdown anchor links — all 15 impl doc TOC entries (lines 45–62):**

Anchor strings use lowercase and are not touched by Passes 1/2. Apply these in Pass 3. Order is not critical (each anchor string is unique and long enough that none is a substring of another).

| impl line | Old anchor | New anchor |
|---|---|---|
| 45 | `#phase-3--mediastorageservice` | `#phase-2--mediastorageservice` |
| 46 | `#phase-4--tusblockuploadservice` | `#phase-3--tusblockuploadservice` |
| 47 | `#phase-5--media-streamphp` | `#phase-4--media-streamphp` |
| 51 | `#implementation-phases-phase-1--phase-10b` | `#implementation-phases-phase-1--phase-11` |
| 52 | `#phase-1--terraform-private-endpoint-and-disable-public-network-access` | `#phase-6--terraform-private-endpoint-and-disable-public-network-access` |
| 53 | `#phase-2--runtime-config-storage-backend-switch-and-imds-access` | `#phase-1--runtime-config-storage-backend-switch-and-imds-access` |
| 54 | `#phase-3--php-storage-abstraction-layer` | `#phase-2--php-storage-abstraction-layer` |
| 55 | `#phase-4--upload-ingress-php-block-blob-streaming-no-vm-disk-writes` | `#phase-3--upload-ingress-php-block-blob-streaming-no-vm-disk-writes` |
| 56 | `#phase-5--application-mediated-media-streaming` | `#phase-4--application-mediated-media-streaming` |
| 57 | `#phase-6--thumbnails-and-derived-media-into-blob-storage` | `#phase-7--thumbnails-and-derived-media-into-blob-storage` |
| 58 | `#phase-7--runtime-auth-managed-identity-replaces-sas-for-media-path` | `#phase-8--runtime-auth-managed-identity-replaces-sas-for-media-path` |
| 59 | `#phase-8--2bootstrapsh-and-ansible-wiring` | `#phase-9--2bootstrapsh-and-ansible-wiring` |
| 60 | `#phase-9--admin-tooling-updates` | `#phase-10--admin-tooling-updates` |
| 61 | `#phase-10--migration-and-rollout` | `#phase-11--azure-migration-and-rollout` |
| 62 | `#phase-10b--local--virtualbox--baremetal-after-azure-is-confirmed` | `#phase-5--local--virtualbox--baremetal-tranche-1-final-step` |

**Code comments** in PHP/YAML snippets inside the impl doc at lines 43, 292, 344, 699, 732, 856–860, 1135–1137, 1333, 1397 also require updating.

---

### Pass 4 — Post-rename verification

After all three passes are complete, run these grep checks on both docs. All should return zero matches.

```bash
# No remaining intermediate tokens (confirms Pass 2 completed fully)
grep -n "T1S[0-9]\|T2S[0-9]" refactor_storage_media_rest_endpoint.md refactor_storage_media_rest_endpoint_implementation.md

# No remaining old A/B suffixed phases
grep -n "Phase 10A\|Phase 10B" refactor_storage_media_rest_endpoint.md refactor_storage_media_rest_endpoint_implementation.md

# No remaining bare old "Phase 10 —" parent heading (should be Phase 11 now)
grep -n "Phase 10 —\|Phase 10—" refactor_storage_media_rest_endpoint_implementation.md
```

Also confirm the new phases exist and are correctly numbered:

```bash
# Should print exactly 11 lines: Phase 1 through Phase 11
grep -n "^### Phase [0-9]" refactor_storage_media_rest_endpoint_implementation.md
```

---

### Structural note — Phase 5 nested under Phase 11 parent (decision required)

After the rename, the impl doc section order will be:

```
### Phase 1  (old Phase 2)
### Phase 2  (old Phase 3)
### Phase 3  (old Phase 4)
### Phase 4  (old Phase 5)
### Phase 6  (old Phase 1)   ← Tranche 2 begins
### Phase 7  (old Phase 6)
### Phase 8  (old Phase 7)
### Phase 9  (old Phase 8)
### Phase 10 (old Phase 9)
### Phase 11 — Azure migration and rollout   ← renamed from Phase 10 parent
    #### Phase 11 — Azure (primary validation target)   ← renamed from Phase 10A
    #### Phase 5 — Local / VirtualBox / Baremetal       ← renamed from Phase 10B
```

**Problem:** Phase 5 is a Tranche 1 step but will appear at the very bottom of the impl doc, nested under the Phase 11 Azure parent. This is out of order and potentially confusing.

**Decision options — pick one before or after executing the rename:**

| Option | What it means |
|---|---|
| A — Move Phase 5 block | Cut the entire `#### Phase 5` section and paste it after `### Phase 4`. Requires editing ~100 lines of content. Most correct structurally. |
| B — Promote to top-level | Remove the `### Phase 11 — Azure migration and rollout` parent heading and make both Phase 11 and Phase 5 standalone `###` sections. Phase 5 still appears at the bottom but is no longer nested under Phase 11. |
| C — Accept as-is | Leave Phase 5 at the bottom with a note in its heading or a cross-reference near Phase 4. Lowest effort; readers can follow the TOC. |
