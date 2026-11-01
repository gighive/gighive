# User Flow Refactor

> Status: stub / future work

## Goal

Replace implicit, deployment-config-driven navigation with explicit, role-driven user flows.
The user should always land in the right context because of what they chose to do — not because
of a server environment variable.

## Key ideas

- **Home page role fork** — present two clear entry points: musician/event planner and media librarian.
- **Persistent role awareness** — once a user picks a role (or arrives via a role-specific link), the app keeps them in that context.
- **APP_FLAVOR becomes a true last resort** — only applies to direct URLs and old bookmarks with no other context signal.

## Related docs

- `docs/navigation_event_librarian.md` — current `?view=` param implementation and all inbound link changes
- `docs/pr_librarianAsset_musicianEvent_implementation.md` — DB schema refactor driving the event/librarian split

## Pending decisions

- Where does the home page fork live? (`index.php`, a new landing page, or `header.php` redesign?)
- Does the role choice persist (session/cookie) or is it always inferred from the entry point?
- What does the musician-facing home page look like vs. the librarian home page?
