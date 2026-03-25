# Skills

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
- If the user asks for a plan, provide phased steps, assumptions, and risks.
- If the user asks for confirmation of understanding, summarize scope and constraints without implementing.
- If the user asks to fix a specific issue and the target is clear, implement directly unless they asked to review first.

## Debugging Playbook

- Restate the symptom briefly.
- Separate observed behavior from likely cause.
- Inspect the authoritative files and call sites.
- If an error message says a variable is undefined or missing, automatically search the repo for where that variable is defined and where it is consumed.
- Prefer diagnostics first when the root cause is not yet clear.
- When proposing a fix, explain why it addresses the root cause.
- Keep fixes minimal and easy to verify.

## Planning Playbook

- Define the goal in one sentence.
- List the files or subsystems likely involved.
- Break the work into a few outcome-oriented phases.
- Call out compatibility constraints and rollback considerations.
- Wait for approval before implementing when the user asks for review first.

## Documentation Playbook

- Save plans, findings, and rationale into `/docs/*.md` when requested.
- Include exact paths, assumptions, and important operational notes.
- Keep documentation aligned with the actual implementation and deployment flow.
- Document reversibility or rollback notes when relevant.

## Project-Specific Preferences

- iPhone 12 is a baseline device for iOS behavior and layout decisions.
- In GigHive capture workflow, prefer the term `Event` as the general entity.
- Respect app flavor distinctions such as `gighive` and `defaultcodebase`.  `defaultcodebase` is a pseudonym for the stormpigs version of gighive.
- Follow existing Ansible and `group_vars` conventions for configuration.
- Be explicit about Ubuntu or tooling compatibility when the user raises environment concerns.

## Prompt Interpretation Rules

- "Give me a plan" means do not implement yet.
- "Let's review" means summarize and discuss before changing code.
- "Confirm your understanding" means restate scope, assumptions, and intended approach only.
- "Please fix" usually means implementation is expected if the target is clear.
- "Keep it simple" means avoid unnecessary abstraction, refactors, and extra features.
- "Document this" means the documentation itself is part of the required deliverable.

## Output Preferences

- Be concrete.
- Be operational.
- Prefer short lists over long prose.
- Name exactly what changed or will change.
- Ask narrow clarifying questions only when needed.
- For debugging, include what to check, where to check it, and what outcome to expect.
