# Skill

## Purpose

This file captures recurring user directions, explicit remember-this items, and stable working preferences for work in this repository.

## Workspace / Repo Topology

- `/Users/sodo/gighiveapp/gighiveinfra` is the repo used for GigHive infrastructure, PHP, and Ansible work.
- `/Users/sodo/gighiveapp` is the sibling repo used for GigHive iPhone app development on the user's Mac.
- The `gighiveinfra` repo lives on the user's pop-os box and is NFS-mounted into the Mac under the `gighiveapp` directory.
- The user frequently switches between these two repos during normal work.
- When working in this repo, be explicit about which repo a file belongs to before proposing edits, paths, or commands.

## Most Important Recurring Patterns

- Plan first when requested.
- Do not implement changes until the user explicitly approves when the request is framed as review, planning, confirmation, or discussion.
- Prefer simple, targeted fixes over broad refactors.
- Debug from concrete evidence such as errors, logs, screenshots, exact file paths, and observed behavior.
- Address root cause rather than symptoms.
- Treat documentation as a first-class deliverable when requested.
- Preserve working behavior and avoid regressions; favor rollback-safe changes.
- Honor exact environment constraints, device baselines, file paths, and deployment details provided by the user.
- For UI issues, reason in terms of the exact visible sequence the user expects.

## Working Style

- Lead with the answer, plan, or diagnosis.
- Keep responses concise but specific.
- Use exact filenames and paths when discussing changes.
- Prefer short lists over long prose.
- Name exactly what changed or will change.
- Be concrete and operational.
- Ask narrow clarifying questions only when needed.
- If the user asks for a plan, provide phased steps, assumptions, and risks.
- If the user asks for confirmation of understanding, summarize scope and constraints without implementing.
- If the user asks to fix a specific issue and the target is clear, implement directly unless they asked to review first.

## Prompt Interpretation Rules

- "Give me a plan" means do not implement yet.
- "Let's review" means summarize and discuss before changing code.
- "Confirm your understanding" means restate scope, assumptions, and intended approach only.
- "Please fix" usually means implementation is expected if the target is clear.
- "Keep it simple" means avoid unnecessary abstraction, refactors, and extra features.
- "Document this" means the documentation itself is part of the required deliverable.

## Debugging Playbook

- Restate the symptom briefly.
- Separate observed behavior from likely cause.
- Inspect the authoritative files and call sites.
- If an error message says a variable is undefined or missing, automatically search the repo for where that variable is defined and where it is consumed.
- Prefer diagnostics first when the root cause is not yet clear.
- When proposing a fix, explain why it addresses the root cause.
- Keep fixes minimal and easy to verify.
- For debugging, include what to check, where to check it, and what outcome to expect.

## Planning Playbook

- Define the goal in one sentence.
- List the files or subsystems likely involved.
- Break the work into a few outcome-oriented phases.
- Call out assumptions, compatibility constraints, risks, and rollback considerations.
- Wait for approval before implementing when the user asks for review first.

## Documentation Playbook

- Save plans, findings, and rationale into `/docs/*.md` when requested.
- Include exact paths, assumptions, and important operational notes.
- Keep documentation aligned with the actual implementation and deployment flow.
- Document reversibility or rollback notes when relevant.

## Documentation Format

- **General standards**
- Lead with a precise title that states the document type and subject.
- Prefer short, clearly named sections over long prose blocks.
- Include exact file paths, endpoint names, commands, and environment details when relevant.
- Make the document operational: explain what changed, why, how to verify it, and any rollback or follow-up implications.
- When a doc is plan-oriented, break the work into phases or milestones.
- When a doc describes implementation, include the exact files or subsystems involved.
- Keep terminology aligned with the product vocabulary actually in use.

- **Feature docs**
- Use the pattern seen in recent docs such as `docs/feature_mcp_server_doc_addition.md` and `docs/feature_completed_import_media_from_zip.md`.
- Preferred outline:
  - `# Feature: <name>`
  - `Status`, `Date`, and optional parent/related feature reference near the top
  - `## Overview`
  - `## Primary Use Case and Scope` or `## Use Cases`
  - `## Design Decisions` / architecture / data flow sections as needed
  - `## Files` or exact implementation surface when the feature reaches implementation-planning depth
- Feature docs should explain the user-facing problem, the intended behavior, the chosen architecture, and important constraints or deferred scope.
- For larger features, prefer explicit phase breakdowns and call out shared patterns or reusable infrastructure.

- **Problem / RCA docs**
- Use the pattern seen in recent RCAs such as `docs/problem_iphone_qr_code_redirect.md` and `docs/problem_cloudflare_cached_error_messages.md`.
- Preferred outline:
  - YAML frontmatter `description`
  - `# Problem: <name>`
  - `## Summary`
  - `## Impact`
  - `## Symptoms`
  - chronology or `## Problems Encountered` when the issue unfolded in multiple steps
  - `## Root Cause`
  - `## Resolution`
  - `## Verification`
  - `## Preventative Actions` when relevant
- RCA docs should separate observable symptoms from the actual cause.
- Include exact diagnostics, commands, environment facts, and configuration values that proved or fixed the issue.
- When multiple issues were encountered, document them chronologically with a fix under each.

- **PR docs**
- Use the pattern seen in recent PR docs such as `docs/pr_delete_upload_iphone.md` and `docs/pr_librarianAsset_musicianEvent_completed_implementation.md`.
- Preferred outline depends on doc depth:
  - concise PR/design doc:
    - `# PR` or `# PR / Design Doc: <name>`
    - `## Summary` or `## Proposed change`
    - `## Rationale`
    - `## Constraints / non-goals`
    - `## UX requirements` or `## Agreed Requirements`
    - `## Implementation plan`
    - `## Verification`
  - large implementation PR plan:
    - overview and guiding decisions
    - PR milestone list
    - recommended sequencing
    - per-PR purpose, changes, exact files, and verification
- PR docs should be implementation-oriented, with exact files, concrete milestones, and explicit verification criteria.
- If a change is large or risky, include sequencing, rollback snapshot guidance, and contract/API coordination notes.

- **Styling cues repeatedly preferred by the user**
- Put saved analysis into a named doc file when requested rather than leaving it only in chat.
- Use dated/status context near the top when it helps anchor the doc.
- Prefer structured headings and milestone sections over freeform narrative.
- Include assumptions explicitly.
- Include verification steps and expected outcomes, not just proposed changes.

## Project-Specific Preferences

- Code for iPhone 12 as the baseline device unless told otherwise.
- Be explicit about iOS compatibility and layout behavior on iPhone 12.
- The GigHive iOS app minimum deployment target is iOS 14.
- In GigHive capture workflow, prefer the term `Event` as the general entity.
- Respect app flavor distinctions such as `gighive` and `defaultcodebase`. `defaultcodebase` is a pseudonym for the stormpigs version of gighive.
- Follow existing Ansible and `group_vars` conventions for configuration.
- For VM build/base VM configuration, when referring to "each group var file," this means exactly `ansible/inventories/group_vars/gighive/gighive.yml`, `ansible/inventories/group_vars/gighive2/gighive2.yml`, and `ansible/inventories/group_vars/prod/prod.yml`.
- Be explicit about Ubuntu or tooling compatibility when the user raises environment concerns.

## Stable Remembered Project Notes

- The active iOS project is `/Users/sodo/gighiveapp/GigHive/`.
- Any guest gallery data that must persist across restarts should be scoped to the event, not to a single nonce.
- The iOS app uses a shared `UploadStateStore` environment object at the app root to preserve upload form and in-flight upload state across navigation.
- Xcode's "All Exceptions" breakpoint can falsely pause on internal AVFoundation exceptions; use an Objective-C exception breakpoint when diagnosing the previously observed frozen audio UI issue.

## Source Notes

This file was consolidated from:

- `gighiveinfra/skill.md`
- `/SKILLS.md`
- remembered project notes available in assistant context
- recurring prompt patterns reflected in `gighiveinfra/user-prompts.md`

This is the canonical skills file and should be updated when new recurring instructions become stable and repeated.
