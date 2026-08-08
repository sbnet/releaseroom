---
ref: repository-connection
title: "GitHub repository connection"
status: validated
issue: 3
date: 2026-08-08
---

# SPEC-repository-connection — GitHub repository connection

Issue: [#3](https://github.com/sbnet/releaseroom/issues/3)

## Context

A project owns a changelog, but it has no source of truth yet. Everything
ReleaseRoom will later ingest — merged pull requests — comes from a GitHub
repository the owner controls. This spec attaches exactly one repository to
a project, stores the credential that grants read access to it, and proves
that credential works before anything is persisted.

It is the second vertical slice, and the last one before ingestion becomes
possible. It deliberately stops at "we can reach the repository": nothing is
fetched, stored or displayed from the repository's contents.

## Goals

- An owner can connect a GitHub repository to their project by pasting its
  URL and a fine-grained personal access token.
- The token is stored encrypted, is never displayed again, and never leaves
  the server.
- A connection that exists in the database is a connection that GitHub
  confirmed at least once.
- The owner can re-verify the connection on demand, replace the token,
  repoint the repository, or disconnect entirely.

## Non-goals

Explicitly out of scope, and not to be anticipated in code:

- Pull request ingestion, storage or display — the next spec.
- Webhooks, and any push-based mechanism.
- A GitHub App or an OAuth "Sign in with GitHub" flow. This slice is a
  pasted fine-grained PAT; the App path is a later migration.
- Providers other than GitHub. No `provider` column, no abstraction layer
  for a second forge.
- More than one repository per project.
- Background re-verification on a schedule, and any notification (email or
  in-app) when a token expires or a check fails.
- Revealing the stored token back to the user, under any confirmation.

## Domain

### RepositoryConnection

New table `repository_connections`.

| Attribute         | Type                | Rules                                                    |
|-------------------|---------------------|----------------------------------------------------------|
| `id`              | int                 | primary key                                              |
| `project_id`      | FK → `projects.id`  | required, **unique**, cascade on project delete          |
| `user_id`         | FK → `users.id`     | required, denormalized owner, cascade on user delete     |
| `github_id`       | unsigned big int    | required, the repository's immutable numeric GitHub id   |
| `owner`           | string(39)          | required, canonical owner as returned by the API         |
| `name`            | string(100)         | required, canonical name as returned by the API          |
| `is_private`      | boolean             | required                                                 |
| `default_branch`  | string(255)         | required                                                 |
| `token`           | text                | required, **encrypted at rest**, write-only              |
| `token_last_four` | string(4)           | required, the last four characters, for display only     |
| `token_expires_at`| datetime, null      | when GitHub reports one, see below                       |
| `status`          | string              | required, `connected` \| `failed`                        |
| `last_error_code` | string, null        | set when `status` is `failed`, see the failure table     |
| `last_checked_at` | datetime            | required, timestamp of the last GitHub verification      |
| timestamps        |                     |                                                          |

Indexes:

- `unique(project_id)` — a project has at most one connection.
- `unique(user_id, github_id)` — a user cannot connect the same repository
  to two of their projects. Two different users may each connect the same
  public repository.

`status` and `last_error_code` are backed PHP enums, not free strings.

**Why `user_id` is denormalized.** The per-owner uniqueness rule spans two
tables (`projects.user_id` and the connection's repository identity). Carrying
the owner on the connection makes it a database-level guarantee instead of a
validation-time race. This is safe because a project's owner is immutable in
the current model — there is no transfer flow. If shared team access or
project transfer lands later, this column becomes the thing to revisit.

**Why `github_id`.** Repositories get renamed and transferred; the numeric id
does not. Storing it lets a re-check follow a rename instead of breaking, and
lets us detect the dangerous case where the same `owner/name` now points at a
different repository.

### Relationships

- `Project` has one `RepositoryConnection` (nullable).
- `RepositoryConnection` belongs to a `Project` and to a `User`.
- Deleting a project deletes its connection, and with it the stored token.

## Repository URL parsing

The owner pastes whatever GitHub gave them. The input is normalized to an
`owner/name` pair before anything else happens.

Accepted:

- `https://github.com/owner/name`, with or without `www.`, `http`, a
  trailing `/` or a trailing `.git`
- deep links: `https://github.com/owner/name/pull/12`,
  `https://github.com/owner/name/tree/main`
- SSH form: `git@github.com:owner/name.git`
- bare `owner/name`

Rejected with a validation error on `repository_url`:

- any host other than `github.com` (including `gist.github.com` and GitHub
  Enterprise hosts — out of scope)
- fewer than two path segments
- an `owner` that does not match `^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$`
- a `name` that does not match `^[A-Za-z0-9._-]{1,100}$`, or that is `.` or `..`

Parsing is case-insensitive on the host. The parsed `owner/name` is used only
to address the API; what is **stored** is the canonical `full_name` the API
returns, which resolves renames for us.

## Access verification

Verification is a synchronous, blocking call to the GitHub REST API. It runs
on connect, on update, and on demand. Nothing is written to the database
unless it succeeds — with one exception, described under "Re-verification".

Two requests, in order:

1. `GET https://api.github.com/repos/{owner}/{name}` — proves the repository
   exists and is visible to the token, and yields `id`, `full_name`,
   `private` and `default_branch`.
2. `GET https://api.github.com/repos/{owner}/{name}/pulls?state=closed&per_page=1`
   — proves the token actually carries the Pull requests read permission.
   The body is discarded; only the status code matters.

Headers on both: `Authorization: Bearer <token>`,
`Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`,
and a `User-Agent` identifying ReleaseRoom.

Timeouts: 3s to connect, 5s total per request. No retries — a failure should
surface immediately rather than making the form hang.

**Token expiry.** GitHub returns a
`github-authentication-token-expiration` header on responses authenticated
with a PAT that has an expiry. When present, it is parsed into
`token_expires_at`; when absent, the column stays null. It is displayed, and
nothing acts on it in this slice.

### Failure mapping

Every failure maps to one code, one target field, and one message. The user
must know what to fix.

| Situation                                          | `last_error_code`      | Field on the form | Message                                                                                     |
|----------------------------------------------------|------------------------|-------------------|---------------------------------------------------------------------------------------------|
| `401` on either call                               | `invalid_token`        | `token`           | This token is invalid or has expired.                                                        |
| `403`, rate limit not exhausted                    | `insufficient_access`  | `token`           | This token does not grant access to this repository.                                         |
| `404` on the repository call                       | `repository_not_found` | `repository_url`  | No repository found at this address, or this token cannot see it.                            |
| Repository call succeeds, pulls call returns `403`/`404` | `missing_pull_scope` | `token`         | This token cannot read pull requests. Grant the Pull requests permission, read-only.         |
| `403`/`429` with `x-ratelimit-remaining: 0`        | `rate_limited`         | form-level        | GitHub's rate limit is exhausted for this token. Try again later.                            |
| `5xx`, connection error, or timeout                | `github_unavailable`   | form-level        | GitHub is unavailable right now. Try again in a moment.                                      |
| Re-check returns a different `github_id`           | `identity_changed`     | n/a (status only) | The repository at this address is no longer the one you connected.                           |

A private repository the token cannot see returns `404` from GitHub, not
`403`. Reporting that verbatim leaks nothing: our message covers both
readings.

## Authorization

- All routes require `auth` and `verified`, like the rest of the app.
- Authorization delegates to `ProjectPolicy::update` — being allowed to
  manage a project is being allowed to manage its integration. No separate
  policy.
- A non-owner receives **403** on every route, consistent with SPEC-projects.
- Write routes (`store`, `update`, `check`) carry `throttle:10,1` per user.
  The token's GitHub quota is a shared resource and these routes are the only
  way to spend it from outside.

## Routes

All nested under the project, bound by project id.

| Method   | Path                                    | Name                          | Purpose                                  |
|----------|-----------------------------------------|-------------------------------|------------------------------------------|
| `GET`    | `/projects/{project}/repository`        | `projects.repository.edit`    | The connect / manage screen              |
| `POST`   | `/projects/{project}/repository`        | `projects.repository.store`   | Connect a repository                     |
| `PUT`    | `/projects/{project}/repository`        | `projects.repository.update`  | Repoint the repository, replace the token |
| `DELETE` | `/projects/{project}/repository`        | `projects.repository.destroy` | Disconnect                               |
| `POST`   | `/projects/{project}/repository/check`  | `projects.repository.check`   | Re-verify on demand                      |

`store` on a project that already has a connection returns a validation
error rather than silently replacing it; `update`, `destroy` and `check` on a
project with no connection return **404**.

Redirects follow the existing convention: back to the project's page with a
success toast on connect, update and disconnect; back to the repository
screen with the error otherwise.

## Screens

### `projects/Show` — the Repository card

Replaces the placeholder SPEC-projects left behind.

**No connection.** A short line explaining that ReleaseRoom reads merged pull
requests from a GitHub repository, and a primary action "Connect a
repository".

**Connected.** `owner/name` as a link to `https://github.com/owner/name`, a
`Private` / `Public` badge, the default branch, a status badge, and
"Last checked <relative time>". Two actions: "Test connection" (posts to
`check`) and "Manage".

The status badge is `Connected` when `status` is `connected`, and
`Connection failed` with the mapped failure message when it is `failed`.

### `projects/repository/Edit` — `GET /projects/{project}/repository`

One screen, two states.

**Connect form** (no connection yet):

- `repository_url`, with the placeholder `https://github.com/owner/name`.
- `token`, a password-type field. Helper text states exactly what to create:
  a fine-grained personal access token, scoped to that repository, with
  **Repository permissions → Pull requests: Read-only**, and links to
  GitHub's token settings page.
- Submit is disabled while the request is in flight — verification takes a
  network round trip and the user must not be able to fire it twice.

**Manage form** (connected):

- `repository_url` prefilled with the canonical
  `https://github.com/owner/name`. Editable: repointing is allowed and
  re-verified.
- `token` empty, with the placeholder "Leave blank to keep the current
  token", next to the fingerprint `••••abcd`, the date it was added, and the
  expiry date when known.
- Metadata read back: default branch, visibility, last check.
- A destructive "Disconnect" section at the bottom, behind a confirmation
  dialog in the style of the existing account and project deletion. It states
  that the stored token is destroyed and cannot be recovered.

Field errors render per field; `rate_limited` and `github_unavailable` render
as a form-level error, since neither is the user's input being wrong.

## Business rules

1. A project has at most one repository connection.
2. A user cannot connect the same GitHub repository (by `github_id`) to two
   of their own projects. The error names the project already holding it.
3. Two different users may connect the same public repository independently.
4. A connection is only written after GitHub confirms both calls.
5. On update with a blank `token`, the stored token is reused for
   verification and left untouched. On update with a filled `token`, the new
   one is verified and only replaces the old one on success.
6. A failed update leaves the previous connection exactly as it was.
7. The token is never sent to the client, in any response, prop or error.
8. `token` is registered in the framework's "do not flash" list, so a
   validation error never round-trips it through the session.

## Re-verification

"Test connection" re-runs the same two calls with the stored token. Unlike
connect and update, it is allowed to write a failure:

- **Success, same `github_id`** → `status: connected`, `last_error_code`
  cleared, metadata refreshed, `last_checked_at` updated.
- **Success, same `github_id`, different `full_name`** → the repository was
  renamed or transferred. The stored `owner` and `name` are updated silently
  and the connection stays `connected`. This is precisely what `github_id`
  is for.
- **Success, different `github_id`** → the address now resolves to a
  different repository. `status: failed`, `last_error_code:
  identity_changed`. Stored metadata is **not** overwritten: the user must
  decide, by repointing or disconnecting.
- **Any failure** → `status: failed`, the mapped `last_error_code`,
  `last_checked_at` updated. The connection and its token are kept so the
  user can fix the cause.

A `failed` connection is a normal, recoverable state, not a broken record.

## Edge cases

- **GitHub unreachable on connect** — nothing is persisted, form-level error,
  the user retries. The invariant that a stored connection was verified once
  holds.
- **GitHub unreachable on re-check** — `status: failed` with
  `github_unavailable`. The distinction between "your token is bad" and
  "GitHub is down" is preserved in the message.
- **Token pasted with surrounding whitespace** — trimmed before validation.
  A token containing inner whitespace is rejected as malformed.
- **Token prefix** — no prefix is enforced. GitHub has changed its token
  formats before, and the live API call is the real gate. The rule is only:
  non-empty, no whitespace, at most 255 characters.
- **Repository URL pointing at a gist or an enterprise host** — rejected at
  parse time, before any network call.
- **`store` raced against itself** — the `unique(project_id)` index is the
  backstop; the duplicate insert surfaces as the same validation error.
- **Project deleted while connected** — the connection and its token go with
  it, by cascade.
- **User deleted** — projects cascade, connections cascade with them.
- **A token valid for the repository but not for pull requests** — caught by
  the second call, mapped to `missing_pull_scope`, and rejected at connect
  time. It never becomes a stored connection.

## Technical decisions & tradeoffs

| Decision                     | Choice                                              | Rationale                                                                                                        |
|------------------------------|-----------------------------------------------------|------------------------------------------------------------------------------------------------------------------|
| Authentication               | Pasted fine-grained PAT                             | No app registration, no callback, no private key. Works for private repositories today. A GitHub App is a later migration, deliberately deferred. |
| Encryption                   | Laravel's `encrypted` cast, `APP_KEY`               | Framework-native AES, zero bespoke crypto. Cost: rotating `APP_KEY` invalidates every stored token — accepted, and worth documenting in the deploy notes. |
| Credential scope             | One token per connection                            | Revoking one project's access breaks nothing else. Costs the user a paste per repository.                         |
| Cardinality                  | One repository per project                          | Matches a single-product changelog. Multi-repo aggregation is a schema change, not a rewrite.                     |
| Verification                 | Blocking, plus on-demand re-check                   | A stored connection is always one that worked. The re-check covers revocation and expiry after the fact, without a queue. |
| Verification depth           | Repository read **and** pull request list           | Costs one extra request; buys the guarantee that ingestion will not fail later on a permission the user never granted. |
| Repository identity          | Store the numeric `github_id`                       | Survives renames and transfers, and makes "this is a different repository now" detectable instead of silent.       |
| Per-owner uniqueness         | Denormalized `user_id` + composite unique index     | Database-level guarantee rather than a checked-then-inserted race. Ties the column to today's single-owner model. |
| Token display                | Write-only, last four characters                    | The database never becomes a readable secret vault. The user re-pastes instead of reading back.                    |
| Rate limiting                | `throttle:10,1` on write routes                     | The GitHub quota is spendable from outside; the routes that spend it are throttled.                               |

## Testing

Every GitHub call is faked (`Http::fake`); no test touches the network. The
suite covers, at minimum:

- URL parsing, accepted and rejected forms, as a unit test over the parser.
- Each row of the failure mapping table, asserting the error code, the target
  field and that nothing was persisted.
- The happy path, asserting the stored metadata matches the API payload and
  that both endpoints were called.
- Encryption at rest: the raw database value for `token` is not the plaintext
  token, and the model attribute is.
- Non-leakage: the token appears in no response body and in no Inertia prop,
  including on validation failure.
- Authorization: a non-owner gets 403 on all five routes; a guest is
  redirected to login.
- Uniqueness: the same repository on a second project of the same user is
  rejected; the same repository for a different user is accepted.
- Re-verification: each of the four outcomes, including the rename-follows
  and identity-changed branches.
- Cascade: deleting the project removes the connection row.

## Acceptance criteria

**Connecting**

1. An owner can connect a repository by pasting a GitHub URL and a valid
   token, and lands back on the project page with a success toast.
2. The connection stores the canonical `owner/name`, `github_id`,
   visibility and default branch as returned by the API.
3. All accepted URL forms resolve to the same `owner/name`.
4. A URL on a host other than `github.com` is rejected before any network
   call.
5. A malformed URL is rejected with an error on `repository_url`.
6. An empty or whitespace-bearing token is rejected with an error on `token`.
7. A `401` from GitHub is rejected with `invalid_token` on the `token` field,
   and nothing is persisted.
8. A `404` on the repository is rejected with `repository_not_found` on the
   `repository_url` field, and nothing is persisted.
9. A token that reads the repository but not its pull requests is rejected
   with `missing_pull_scope`, and nothing is persisted.
10. GitHub being unavailable is rejected with a form-level error, and nothing
    is persisted.
11. Connecting a repository already connected to another project of the same
    user is rejected, and the error names that project.
12. Another user connecting the same public repository succeeds.
13. Connecting on a project that already has a connection is rejected.

**Storage and secrecy**

14. The `token` column's raw database value is not the plaintext token.
15. The token is absent from every response and every Inertia prop.
16. A validation failure does not flash the token back into the form.
17. Only the last four characters are ever rendered.

**Reading**

18. The project page shows the connected repository, its visibility, its
    default branch, its status and the time of the last check.
19. A project with no connection shows the empty state and a connect action.

**Updating**

20. The owner can replace the token; the new one is verified before it
    replaces the old one.
21. Submitting the manage form with a blank token re-verifies with the stored
    token and leaves it unchanged.
22. The owner can repoint the connection to a different repository; the new
    one is verified first.
23. A failed update leaves the stored connection byte-for-byte unchanged.

**Re-verification**

24. A successful check sets `status: connected`, clears `last_error_code` and
    updates `last_checked_at`.
25. A check on a renamed repository (same `github_id`) updates the stored
    `owner/name` and stays connected.
26. A check resolving to a different `github_id` sets `status: failed` with
    `identity_changed` and does not overwrite the stored metadata.
27. A failing check sets `status: failed` with the mapped code, and keeps the
    connection and its token.

**Disconnecting**

28. The owner can disconnect; the row and its token are deleted and the
    project returns to the empty state.
29. Deleting a project deletes its connection.

**Access**

30. A non-owner receives 403 on the screen and on all four write routes.
31. A guest is redirected to login on every repository route.
32. Exceeding 10 write requests in a minute returns 429.

## Open questions

- [ ] **Scheduled re-verification.** Nothing re-checks a connection on its
      own. A daily job plus a notification when a token is expiring belongs
      with the ingestion spec, which will already own a queue.
- [ ] **Repointing after ingestion.** Once pull requests are stored, changing
      the repository of a connection orphans them. The ingestion spec must
      decide: refuse, reassign, or purge. Until then, repointing is free.
- [ ] **`APP_KEY` rotation.** Rotating it makes every stored token
      undecryptable. Decide whether that warrants a dedicated credentials key
      and a re-encrypt command before the first real deployment.
- [ ] **GitHub App migration.** The path from stored PATs to installation
      tokens, and what happens to existing connections, is a later decision.
