---
ref: projects
title: "Projects: the unit that owns a changelog"
status: validated
issue: 1
---

# SPEC-projects — Projects: the unit that owns a changelog

Issue: [#1](https://github.com/sbnet/releaseroom/issues/1)

## Context

ReleaseRoom publishes a changelog for a product. Everything the application
will later ingest, curate and publish — repository connections, merged pull
requests, releases, the public page — hangs off a single owning entity: the
**project**.

This spec establishes that entity, its authorization boundary and its CRUD
screens. It is the first vertical slice of the product and deliberately the
narrowest one that is still useful on its own.

## Scope

An authenticated user can:

- create a project with a name, a unique public slug and a short description;
- list the projects they own;
- open a project;
- rename a project (name, slug, description);
- delete a project.

### Out of scope

Explicitly deferred to later specs, and not to be anticipated in code:

- GitHub repository connection and credential storage;
- merged pull request ingestion;
- releases and release composition;
- the public release page, its feed and its embeddable widget;
- shared team access — a project belongs to exactly one owner for now.

## Domain

### Project

| Attribute     | Type              | Rules                                       |
|---------------|-------------------|---------------------------------------------|
| `id`          | int               | primary key                                 |
| `user_id`     | FK → `users.id`   | required, the owner, cascade on user delete |
| `name`        | string(255)       | required                                    |
| `slug`        | string(60)        | required, unique application-wide, URL-safe |
| `description` | string(280), null | optional, short                             |
| timestamps    |                   |                                             |

A project has exactly one owner. A user owns zero or more projects.

### Slug

The slug is the future address of the public release page, so it is
constrained beyond ordinary validation:

- **URL-safe**: lowercase ASCII letters, digits and single hyphens, never
  leading, trailing or doubled — `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
- **Unique across the application**, not per owner: two different users
  cannot both hold `acme`.
- **Not a reserved word.** Because the public page will eventually live at a
  top-level address, slugs that would collide with application routes are
  rejected: `admin`, `api`, `assets`, `build`, `dashboard`, `login`,
  `logout`, `register`, `settings`, `storage`, `projects`, `up`, `profile`,
  `security`, `password`, `email`, `verify`, `two-factor`, `passkey`,
  `well-known`, `feed`, `rss`, `embed`, `about`, `pricing`, `docs`.
- Suggested from the name on the create form, but always user-editable.

**Stability.** The slug must be stable once the project has published
something. Publication does not exist in this slice, so no project can be
published and the slug stays editable. The lock lands with the public
release page spec, which is what makes the constraint meaningful and
testable. This is recorded as an open point below rather than built now.

### Addressing

Authenticated management screens bind by **id** (`/projects/{project}`), not
by slug, so that renaming a slug never breaks a management URL or a
bookmark. The slug is reserved for the future public route.

## Authorization

- All routes require `auth` and `verified`, consistent with the dashboard.
- A `ProjectPolicy` governs `view`, `update` and `delete`: the acting user
  must be the owner.
- The index is scoped to the owner's projects — a non-owner never learns a
  project exists from the list.
- Acting on a project owned by someone else returns **403**, not 404.

## Screens

All under the authenticated app layout, reachable from a "Projects" entry in
the sidebar.

### `projects/Index` — `GET /projects`

- Lists the owner's projects, most recently created first.
- Each row shows name, slug and description, and links to the project.
- Empty state: a short explanation and a primary "New project" action.
- A "New project" action is always available.

### `projects/Create` — `GET /projects/create`

- Fields: name, slug, description.
- The slug field is pre-filled from the name as the user types, until the
  user edits the slug themselves — after that it is left alone.
- Validation errors are shown per field.
- On success: redirect to the project, success toast.

### `projects/Show` — `GET /projects/{project}`

- Shows name, slug, description and creation date.
- Links to the edit screen.
- Carries a visible placeholder for what lands next (repository connection,
  releases) without implementing any of it.

### `projects/Edit` — `GET /projects/{project}/edit`

- Same three fields, pre-filled. The slug is editable here.
- Includes the delete affordance: a destructive confirmation dialog, in the
  style of the existing account deletion, stating that deletion is permanent.
- On success: redirect to the project, success toast.
- On delete: redirect to the index, success toast.

## Acceptance criteria

**Creation**

1. An authenticated, verified user can create a project with a valid name,
   slug and description, and lands on the project's page.
2. A project is created with the acting user as owner.
3. `description` is optional; a project can be created without one.
4. A slug already taken by *any* user is rejected with a validation error on
   the `slug` field.
5. A slug that is not URL-safe (uppercase, spaces, underscores, accents,
   leading/trailing/doubled hyphens) is rejected.
6. A reserved slug is rejected.
7. A missing name or a missing slug is rejected.

**Listing and reading**

8. The index lists the acting user's projects and none belonging to another
   user.
9. The owner can open their project's page.
10. A user who is not the owner receives 403 on show, edit, update and
    delete.

**Update**

11. The owner can change the name, the slug and the description.
12. Updating a project while keeping its own slug unchanged is accepted —
    uniqueness ignores the project itself.
13. Changing a slug to one taken by another project is rejected.

**Deletion**

14. The owner can delete their project; it disappears from the index.
15. Deleting a user deletes their projects.

**Access**

16. A guest is redirected to login on every project route.

## Non-goals for the implementation

- No soft deletes: deletion is permanent in this slice.
- No pagination on the index yet — a user is not expected to own dozens of
  projects before the ingestion slice lands.
- No slug history or redirect table.

## Open points

- **Slug lock on publication.** Decide the exact trigger (first published
  release? an explicit "published" flag on the project?) when the public
  release page spec is written. Until then the slug is freely editable.
- **Shared team access** is a separate, later spec; the `user_id` column is
  expected to become a membership table at that point.
