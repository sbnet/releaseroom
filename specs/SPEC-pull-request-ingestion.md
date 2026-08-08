---
ref: pull-request-ingestion
title: "Merged pull request ingestion"
status: validated
issue: 6
date: 2026-08-08
---

# SPEC-pull-request-ingestion — Merged pull request ingestion

Issue: [#6](https://github.com/sbnet/releaseroom/issues/6)

## Context

A project can reach its GitHub repository, and that is all it can do.
SPEC-repository-connection stopped deliberately at "we can read it": nothing
is fetched, stored or displayed. This spec fills that gap. It brings merged
pull requests into ReleaseRoom, keeps them free of duplicates however many
times they arrive, and parks them in a list the owner triages before any
release exists.

It is the third vertical slice. It produces the raw material a changelog is
written from, and stops there: nothing is grouped, worded or published.

Two mechanisms carry the data, and they exist for different reasons. A
**webhook** delivers pull requests as they are merged, which is what makes
the product feel live. A **pull-based sync** covers everything the webhook
structurally cannot: the history that predates the connection, and the
deliveries GitHub gave up on while the application was down. Neither is
sufficient alone, and the dedup rule is what lets them overlap freely.

## Goals

- A pull request merged into the repository's default branch appears in the
  project's curation list without anyone doing anything.
- Connecting a repository immediately yields a usable list, rather than an
  empty one waiting for the next merge.
- The same pull request arriving twice — by redelivery, by backfill, by
  sync — is one row, always.
- The owner can dismiss noise, and a dismissal is permanent: no later
  ingestion resurrects it.
- A delivery that was never processed can be explained after the fact.

## Non-goals

Explicitly out of scope, and not to be anticipated in code:

- Releases, grouping, ordering within a release, publishing.
- Rewriting an entry's title or writing a summary. The only curation acts in
  this slice are dismiss and restore. Editing belongs with the spec that
  publishes the text.
- Ingestion filtering rules — no bot detection, no label or author ignore
  list. The dismiss action covers it, and the real data should inform the
  rule before the rule is written.
- Commits, issues, releases or tags as sources. Merged pull requests only.
- A scheduled reconciliation sweep. The recovery path is a button in this
  slice; the job it calls is written so that a schedule is a later trigger,
  not a rewrite.
- Scheduled re-verification of connections and token-expiry notifications,
  left open by SPEC-repository-connection and still open here — this slice
  adds no scheduler.
- Providers other than GitHub, and more than one repository per project.
- Real-time UI updates. The curation list is a page load.

## Domain

### PullRequestCandidate

New table `pull_request_candidates`. A merged pull request awaiting a
human's ruling.

| Attribute      | Type                | Rules                                                        |
|----------------|---------------------|--------------------------------------------------------------|
| `id`           | int                 | primary key                                                  |
| `project_id`   | FK → `projects.id`  | required, cascade on project delete                          |
| `github_id`    | unsigned big int    | required, the pull request's immutable numeric GitHub id     |
| `number`       | unsigned int        | required, the `#N` humans quote                              |
| `title`        | string(512)         | required                                                     |
| `body`         | text, null          | truncated at 65,535 characters                               |
| `author_login` | string(39), null    | null when GitHub reports a deleted account                   |
| `author_avatar_url` | string(255), null | for the list rows                                          |
| `labels`       | json                | list of label names as of ingestion, `[]` when none          |
| `base_branch`  | string(255)         | required, the branch it was merged into                      |
| `merged_at`    | datetime            | required, the ordering key                                   |
| `html_url`     | string(255)         | required, the link back to GitHub                            |
| `state`        | string              | required, `pending` \| `dismissed`                           |
| `curated_at`   | datetime, null      | set the first time a human rules on the entry — the freeze marker |
| `ingested_via` | string              | required, `webhook` \| `backfill` \| `sync`, first source wins |
| timestamps     |                     |                                                              |

Indexes:

- `unique(project_id, github_id)` — **the dedup key**. Everything else in
  this spec leans on it.
- `index(project_id, state, merged_at)` — the list query, in its only
  ordering.

`state` and `ingested_via` are backed PHP enums.

**Why the candidate hangs off `project_id`, not the connection.** Repointing
a connection is refused once candidates exist, which makes
disconnect-and-reconnect the deliberate escape hatch. If candidates cascaded
from the connection, taking that hatch would silently destroy every triage
decision the owner had made. Attaching them to the project means the token
can be revoked and the repository swapped without losing curation work. The
honest consequence, stated plainly in the UI: reconnecting a *different*
repository merges its pull requests into the same list.

**Why `github_id` and not `number`.** Pull request numbers are unique per
repository, not globally. Keying on the numeric id keeps the dedup key
correct if a project ever draws from more than one repository — which is
exactly what the disconnect-and-reconnect hatch permits today.

**Why `labels` is stored.** Not for grouping, which is a later spec, but for
triage: deciding that a pull request is noise is much faster when its labels
are on the row. It is display data in this slice, and nothing branches on it.

### WebhookDelivery

New table `webhook_deliveries`. What GitHub sent, and what we did with it.

| Attribute                  | Type                             | Rules                                    |
|----------------------------|----------------------------------|------------------------------------------|
| `id`                       | int                              | primary key                              |
| `repository_connection_id` | FK → `repository_connections.id` | required, cascade on connection delete   |
| `delivery_id`              | string(64)                       | required, GitHub's `X-GitHub-Delivery`   |
| `event`                    | string(64)                       | required, `pull_request`, `ping`, …      |
| `action`                   | string(64), null                 | the payload's `action`, when it has one  |
| `payload`                  | json                             | required, the body as received           |
| `status`                   | string                           | required, `received` \| `processed` \| `ignored` \| `failed` |
| `reason`                   | string(255), null                | why it was ignored or how it failed      |
| `processed_at`             | datetime, null                   |                                          |
| timestamps                 |                                  |                                          |

Index: `unique(repository_connection_id, delivery_id)` — GitHub retries a
failed delivery with the same id, and the unique index is what makes the
retry a no-op instead of a duplicate.

The model is `Prunable`, dropping rows older than 30 days. Nothing schedules
`model:prune` in this slice; see Open questions.

### Additions to `repository_connections`

| Attribute                  | Type              | Rules                                                       |
|----------------------------|-------------------|-------------------------------------------------------------|
| `webhook_token`            | string(64)        | required, **unique**, the opaque URL segment                |
| `webhook_secret`           | text              | required, **encrypted at rest**, the HMAC key               |
| `webhook_id`               | unsigned big int, null | GitHub's hook id, set only when we created it via the API |
| `webhook_status`           | string            | required, `active` \| `manual_setup_required`               |
| `webhook_last_delivery_at` | datetime, null    | the last signature-valid delivery                           |
| `last_synced_at`           | datetime, null    | the last successful backfill or sync                        |

`webhook_token` and `webhook_secret` are generated for every connection,
whether or not we manage to create the hook ourselves — the manual path needs
both. The migration backfills them for connections that already exist.

### Relationships

- `Project` has many `PullRequestCandidate`.
- `RepositoryConnection` has many `WebhookDelivery`.
- Deleting a project deletes its candidates; deleting a connection deletes its
  delivery log but **not** the project's candidates.

## Webhook setup

### Automatic, with a manual fallback

On connect, immediately after verification succeeds and the connection row is
written, ReleaseRoom attempts to create the hook itself:

```
POST /repos/{owner}/{name}/hooks
{
  "name": "web",
  "active": true,
  "events": ["pull_request"],
  "config": {
    "url": "https://<app>/webhooks/github/<webhook_token>",
    "content_type": "json",
    "secret": "<webhook_secret>",
    "insecure_ssl": "0"
  }
}
```

| Response                | `webhook_status`         | What the owner sees                                          |
|-------------------------|--------------------------|--------------------------------------------------------------|
| `201`                   | `active`                 | "Live delivery is active."                                   |
| `403` / `404`           | `manual_setup_required`  | The token lacks Webhooks access — new token, or set it up by hand |
| `422`                   | `manual_setup_required`  | A hook already exists for this URL                            |
| `5xx`, timeout          | `manual_setup_required`  | Same fallback; the retry button covers it                     |

**The connection is written before the hook is attempted, and a failed
attempt never rolls it back.** A connection whose webhook is not set up is a
working connection: backfill and sync only need Pull requests read access.
Ordering the writes the other way round would risk leaving a live hook on
GitHub pointing at a connection that does not exist.

The token requirement on the connect form becomes **Pull requests:
Read-only** and **Webhooks: Read and write**. Only the first is enforced by
verification; the second is discovered by trying, which costs one request
instead of a third blocking check.

### Connections that already exist

A connection created under the previous spec has a token with no Webhooks
permission. Nothing about it breaks: the migration gives it a
`webhook_token`, a `webhook_secret` and `manual_setup_required`. Backfill and
sync work unchanged. The repository screen states that live delivery is not
active and offers the two ways out — replace the token with one that carries
the permission, or create the hook by hand.

### The manual path

The repository screen shows exactly what GitHub asks for: the payload URL,
the secret, content type `application/json`, and the single event **Pull
requests**. A "Retry automatic setup" action re-attempts the API call, which
is the path after replacing the token.

**The secret is revealable, the GitHub token is not.** They are not the same
kind of secret. The token is a credential *against GitHub*, and showing it
again would make our database a readable vault for someone else's access. The
webhook secret is *ours*: knowing it lets someone forge deliveries to one
connection, injecting fake candidates into a list its owner is about to
triage by hand. That is a bounded, visible, recoverable nuisance — and the
manual path is impossible without showing it. A "Regenerate" action is
available; it invalidates the old secret and updates the hook when we manage
it.

### On disconnect

`DELETE /repos/{owner}/{name}/hooks/{webhook_id}` is attempted, best effort.
Failure is logged and ignored: the token may already have been revoked, which
is precisely why the owner is disconnecting. A hook left behind on GitHub
starts failing delivery and GitHub disables it on its own. Blocking a
disconnect on GitHub's availability would be the worse outcome — the point of
disconnecting is to stop trusting that token.

## Receiving a delivery

`POST /webhooks/github/{webhook_token}` — public, unauthenticated, no
session, no CSRF. Registered from a dedicated `routes/webhooks.php` loaded
outside the `web` middleware group, so no session cookie is ever issued on
this path.

In order:

1. **Resolve** the connection by `webhook_token`. Unknown → **404**.
2. **Verify the signature.** `X-Hub-Signature-256` is compared against
   `hash_hmac('sha256', <raw body>, <webhook_secret>)` with `hash_equals`.
   Missing, malformed or mismatched → **401**, and nothing is recorded. The
   comparison uses the *raw* request body: re-encoding the JSON would change
   the bytes and break every signature.
3. **Record** the delivery. A row already existing for
   `(connection, X-GitHub-Delivery)` means this is a retry: respond **202**
   and stop.
4. **Respond 202** and dispatch the processing job.

`webhook_last_delivery_at` is set on every signature-valid delivery, and a
connection sitting at `manual_setup_required` flips to `active` the first
time one arrives. That is how a hook the owner created by hand becomes
visible to us — the manual path's one real weakness, closed by the first
delivery it produces.

Throttle: `throttle:120,1` keyed on the webhook token, not the IP. GitHub
delivers from a wide, changing address range, so an IP key would either
throttle unrelated connections together or not throttle at all.

### What the job does with it

Only two shapes are acted on. Everything else is recorded as `ignored` with
its reason, which is the point of keeping the log at all.

| Event / action                                | Outcome                                                    |
|-----------------------------------------------|------------------------------------------------------------|
| `pull_request` / `closed`, `merged: true`     | Ingested, if the base branch matches                       |
| `pull_request` / `edited`, `merged: true`     | Refreshes an existing candidate, subject to the freeze rule |
| `pull_request` / `closed`, `merged: false`    | Ignored — closed without merging                            |
| `pull_request` / anything else                | Ignored — not a merge                                       |
| `ping`                                        | Ignored, and the hook is confirmed alive                    |
| Any other event                               | Ignored — not subscribed                                    |

A payload whose `repository.id` does not match the connection's stored
`github_id` is recorded as `failed` and dropped. It means the hook now points
at a different repository than the one connected — the delivery equivalent of
`identity_changed`, and not something to ingest quietly.

## What qualifies

A merged pull request becomes a candidate when **its base branch is the
connection's stored `default_branch`**.

Merges into feature branches, release trains and stacked-PR intermediate
branches are the noise this excludes, and they are the majority of merges in
the workflows that produce them. The default branch is already stored,
already refreshed by every "Test connection", and is what "shipped" means for
the repositories this product targets.

Candidates ingested under a previous default branch are left alone if it
changes: they were shipped work when they landed, and rewriting history to
match a new setting would delete real entries.

## Backfill and sync

Both walk the same endpoint with the same dedup, and differ only in where
they stop.

```
GET /repos/{owner}/{name}/pulls
      ?state=closed&base={default_branch}&sort=updated&direction=desc&per_page=100
```

`state=closed` is the closest GitHub offers: it returns closed-and-merged
alongside closed-and-abandoned, and entries with a null `merged_at` are
discarded client-side.

**The ordering is approximate, and that is accepted.** The endpoint cannot
sort by merge date; `sort=updated` puts a long-since-merged pull request that
was commented on yesterday ahead of one merged this morning. For a bounded
backfill of recent history this is close enough, and the alternative — the
Search API — trades it for a 30-requests-per-minute limit and a 1,000-result
ceiling. Page count is capped at **5** in both modes, so a repository whose
closed pull requests are mostly unmerged cannot spin.

**Backfill**, dispatched once when a repository is connected: collect up to
**100 merged pull requests**, then stop. A hundred entries is enough to
assemble a first release and small enough that the list is triageable on the
day it appears. A repository with five thousand merged pull requests does not
open with five thousand rows.

**Sync**, triggered by the owner: walk pages until one produces no candidate
that is either new or in need of a refresh, then stop. Gaps are usually one
delivery wide, so this normally reads a single page.

Both run in a queued job. Both are safe to run repeatedly and safe to run
concurrently with a webhook delivery: the unique index arbitrates.

### When they fail

A failed backfill or sync writes the same `status: failed` and mapped
`last_error_code` that "Test connection" writes, and leaves the candidates it
already ingested in place.

**A refusal from GitHub is recorded, not retried.** Every mapped failure —
including `rate_limited` — is already a considered verdict with a sentence
telling the owner what to do, and "Sync now" is the retry. Silently requeuing
would leave them watching an unchanged screen with no reason on it. The job's
three attempts are reserved for infrastructure faults, which surface as
exceptions rather than as an answer from GitHub. This keeps behavior identical
on every queue driver, including the synchronous one the test suite uses.

The tradeoff is stated openly: a rate limit hit during the backfill turns a
repository that just connected successfully into a "Connection failed" badge
reading *"GitHub's rate limit is exhausted for this token. Try again
later."* That is alarming for a connection that is in fact fine. It is
accepted because the message is true and actionable, "Test connection" clears
it in one click, and the alternative is a second parallel status system whose
only job is to say the same seven things the first one already says.

## Dedup and refresh

The dedup key is `unique(project_id, github_id)`. Ingestion is an upsert
governed by three rules, in this order:

1. **`state` is never written by ingestion.** Only a human sets it. This is
   what makes a dismissal permanent: the next sync re-reads that pull request
   from GitHub, finds the row, and leaves it dismissed.
2. **A curated entry is frozen.** When `curated_at` is not null, incoming
   `title`, `body` and `labels` are discarded. The human has ruled on this
   entry; GitHub does not get to overwrite that ruling.
3. **A pending, untouched entry tracks GitHub.** When `curated_at` is null,
   `title`, `body` and `labels` are refreshed from the payload. A typo fixed
   on GitHub minutes after the merge reaches the changelog without anyone
   retyping it.

`merged_at`, `number`, `html_url` and `base_branch` are immutable after the
first write; they describe an event that already happened.

`curated_at` is set the first time the entry is dismissed *or* restored, and
never cleared. A restored entry therefore stops tracking GitHub — the owner
looked at it and decided to keep it, and that decision is the thing being
protected.

## Authorization

- Curation routes require `auth` and `verified`, like the rest of the app.
- `index` delegates to `ProjectPolicy::view`; `dismiss`, `restore` and `sync`
  delegate to `ProjectPolicy::update`. No new policy: managing a project is
  managing its changelog.
- A non-owner receives **403** on every curation route, a guest is redirected
  to login.
- The webhook route is outside all of this. Its authentication *is* the
  signature, and it belongs to no session.
- `sync` carries `throttle:10,1`, matching the other routes that spend the
  token's GitHub quota. `dismiss` and `restore` spend nothing and are not
  throttled.

## Routes

| Method   | Path                                             | Name                          | Purpose                                  |
|----------|--------------------------------------------------|-------------------------------|------------------------------------------|
| `POST`   | `/webhooks/github/{token}`                       | `webhooks.github`             | Receive a delivery (public, signed)      |
| `GET`    | `/projects/{project}/candidates`                 | `projects.candidates.index`   | The curation list                        |
| `POST`   | `/projects/{project}/candidates/{candidate}/dismiss` | `projects.candidates.dismiss` | Dismiss noise                        |
| `POST`   | `/projects/{project}/candidates/{candidate}/restore` | `projects.candidates.restore` | Undo a dismissal                     |
| `POST`   | `/projects/{project}/repository/sync`            | `projects.repository.sync`    | Fill gaps from the API                   |
| `POST`   | `/projects/{project}/repository/webhook`         | `projects.repository.webhook.store` | Retry automatic hook creation      |
| `POST`   | `/projects/{project}/repository/webhook/secret`  | `projects.repository.webhook.secret` | Regenerate the signing secret     |

Replacing the token through `PUT /projects/{project}/repository` also retries
hook creation when live delivery is not active. A new token is the usual way
out of `manual_setup_required`, so the attempt happens there rather than
making the owner find the button afterwards.

A candidate belonging to another project returns **404**, not 403 — the
project in the URL is the authorization boundary, and a mismatched id is a
wrong address rather than a refused one.

`sync` and the webhook routes return **404** when the project has no
connection, consistent with the existing repository routes.

### Change to an existing route

`PUT /projects/{project}/repository` — repointing to a **different**
`github_id` is refused with a validation error on `repository_url` when the
project has any candidate, pending or dismissed:

> This project already has pull requests from another repository. Disconnect
> it first if you want to change the source.

Replacing the token, and re-verification that follows a rename or transfer
(same `github_id`, new `full_name`), are unaffected. This closes the open
question SPEC-repository-connection left for this spec: **refuse**.

## Screens

### `projects/candidates/Index` — the curation list

The full list, paginated at 20, ordered by `merged_at` descending.

Two tabs: **Pending** (default) and **Dismissed**, each with its count.

A row carries: `#number`, the title linking to `html_url`, the author's
avatar and login, the labels, "merged <relative time>", and a single action —
Dismiss on a pending row, Restore on a dismissed one.

The header holds "Sync now", with the last sync time next to it, and a link
back to the project.

Empty states, which are different problems and read differently:

- **No connection at all** — "Connect a GitHub repository to start collecting
  merged pull requests", with the connect action.
- **Connected, backfill still running** — "Importing pull requests from
  `owner/name`…" A page reload shows the result; nothing polls.
- **Connected, nothing ever ingested** — explains that pull requests appear
  as they are merged into `<default branch>`, and offers "Sync now". If
  `webhook_status` is `manual_setup_required`, it says so and links to the
  repository screen.
- **Everything triaged** — "Nothing pending. <N> dismissed."

### `projects/Show` — the Repository card

Gains, below the existing connection details:

- **Live delivery**: "Active" with the last delivery time, or "Not set up"
  with a link to the repository screen.
- **<N> pull requests pending curation**, linking to the list. Zero renders
  as "No pull requests pending".
- **Last synced <relative time>**, with a "Sync now" action.

### `projects/repository/Edit` — the Webhook section

New section between the connection form and the disconnect block.

**Active**: confirmation, the last delivery time, and the payload URL for
reference.

**Manual setup required**: the reason in one sentence — either the token
lacks the Webhooks permission, or the last attempt failed — then the exact
settings to paste (payload URL, secret behind a Show toggle with a copy
button, content type `application/json`, event **Pull requests**), a link to
the repository's hook settings page, and a "Retry automatic setup" action.

"Regenerate secret" sits under both states, with a confirmation stating that
an existing hook must be updated with the new secret unless ReleaseRoom
manages it.

The token helper text on the connect and manage forms is updated to ask for
**Pull requests: Read-only** *and* **Webhooks: Read and write**, noting that
the second is only needed for automatic setup.

## Business rules

1. A merged pull request is a candidate only if its base branch equals the
   connection's stored `default_branch`.
2. `(project_id, github_id)` is unique. Every ingestion path upserts on it.
3. Ingestion never writes `state`. A dismissed candidate stays dismissed
   through any number of later deliveries and syncs.
4. Ingestion refreshes `title`, `body` and `labels` only while `curated_at`
   is null.
5. `curated_at` is set on the first dismiss or restore and never cleared.
6. `merged_at`, `number`, `html_url` and `base_branch` are written once.
7. A delivery whose signature does not verify is rejected with 401 and leaves
   no trace in the delivery log.
8. A delivery whose `X-GitHub-Delivery` is already recorded for that
   connection is acknowledged and not reprocessed.
9. A delivery whose `repository.id` does not match the connection's
   `github_id` is recorded as failed and never ingested.
10. Creating the hook is best effort and never blocks, rolls back or fails a
    connection.
11. Deleting a connection removes its delivery log and leaves the project's
    candidates intact.
12. Deleting a project removes its candidates.
13. Repointing a connection to a different `github_id` is refused while the
    project holds any candidate.
14. The webhook secret is never sent to a client that is not the project's
    owner, and the GitHub token remains unreadable in every path this spec
    adds.

## Edge cases

- **The same public repository connected by two users.** Two connections, two
  hooks, two webhook tokens, two secrets, two candidate lists. The per-
  connection URL means no delivery is ever ambiguous, and no fan-out is
  needed.
- **GitHub retries a delivery we already processed.** The unique
  `(connection, delivery_id)` index turns it into a 202 and nothing else.
- **A delivery arrives while the backfill job is still running.** Both upsert
  on the same key; the database arbitrates. Whichever writes second refreshes
  a row it finds rather than inserting a duplicate.
- **A pull request is merged, then its title is edited.** The `edited`
  delivery refreshes it if nobody has ruled on it, and is ignored otherwise.
- **A pull request is merged and then reverted.** It stays a candidate.
  GitHub reports no event for a revert, and a revert is itself a change worth
  mentioning. The owner dismisses it if they disagree.
- **A pull request merged into a non-default branch, which is later
  promoted.** Not ingested, and not retroactively ingested. Sync queries by
  the current default branch, so it appears only if GitHub still lists it
  under that base.
- **The default branch is renamed.** "Test connection" updates it; subsequent
  syncs follow it; existing candidates are untouched.
- **The author's account is deleted.** GitHub returns a null user;
  `author_login` and `author_avatar_url` are stored null and the row renders
  without them.
- **A pull request body of 200 KB.** Truncated to 65,535 characters on
  storage. The link to GitHub is always present for the full text.
- **The token is revoked while the hook is alive.** Deliveries keep arriving
  and keep being ingested — the webhook does not authenticate with the token.
  Sync fails and flags the connection. This is correct: the two capabilities
  are genuinely independent.
- **The hook is deleted on GitHub.** Nothing arrives, and nothing tells us.
  "Sync now" is the recovery, and the repository card's last-delivery time is
  the symptom.
- **Local development.** GitHub cannot reach `localhost`, so
  `webhook_status` stays `manual_setup_required` and no delivery ever lands.
  Backfill and sync are the whole ingestion path in dev, and the signature
  path is exercised by the test suite rather than by hand.
- **A candidate id from another project in a dismiss URL.** 404.
- **`APP_KEY` rotation.** Now costs the webhook secrets as well as the
  tokens. Same open question, higher stakes.

## Technical decisions & tradeoffs

| Decision                | Choice                                              | Rationale                                                                                                                                  |
|-------------------------|-----------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| Delivery mechanism      | Webhook, plus backfill and manual sync              | The webhook alone can produce neither history nor recovery. The pull path alone is not live. Dedup makes running both free.                    |
| Hook creation           | Automatic, falling back to manual                   | Costs a broader token permission and two code paths; buys a zero-step setup for most owners without stranding those who will not grant it.     |
| Endpoint identity       | Per-connection opaque URL + per-connection secret    | Two users may connect the same public repository, so the repository id cannot identify a connection. The URL does, unambiguously, with no fan-out. |
| Delivery handling       | Persist, ack 202, process in a queued job           | GitHub's ~10s budget is never at risk, retries are idempotent by delivery id, and "why did this PR never appear" is answerable.                 |
| Delivery log retention  | Prunable, 30 days                                   | Long enough to debug an incident, short enough that the table is not a payload archive. Needs a scheduler, which this slice does not add.       |
| Dedup key               | `unique(project_id, github_id)`                     | One database-level guarantee serving three ingestion paths. No read-then-write race anywhere.                                                    |
| Candidate ownership     | The project, not the connection                     | Disconnect is the escape hatch from repointing; it must not destroy triage work. Cost: reconnecting a different repository merges the lists.    |
| Freeze rule             | `curated_at` null ⇒ track GitHub                    | Late typo fixes flow through, human rulings never get overwritten. Cost: a restored entry stops tracking upstream edits.                        |
| Dismissal permanence    | Ingestion never writes `state`                      | Without this, every sync would resurrect every dismissal and the list would be untriageable.                                                     |
| Qualification           | Base branch = stored `default_branch`               | Excludes stacked-PR and release-train noise using a value already stored and refreshed. Cost: teams shipping from another branch see nothing until a later spec makes it configurable. |
| Backfill bound          | 100 merged pull requests, 5 pages max               | Enough for a first release, bounded API spend, and a list a human can actually finish.                                                          |
| Backfill ordering       | `sort=updated`, filtered on `merged_at`             | The endpoint cannot sort by merge date. Approximate for recent history; the Search API's alternative costs a 30/min limit and a 1,000-result ceiling. |
| Sync failure reporting  | Reuse `status` / `last_error_code`                  | One vocabulary for GitHub failures instead of two. Cost: a rate limit during backfill shows "Connection failed" on a connection that is fine.    |
| Secret visibility       | Webhook secret revealable, GitHub token not          | Different blast radii: the token grants access to GitHub, the secret grants forged candidates in one already-human-reviewed list. The manual path requires showing it. |
| Noise filtering         | None; dismiss by hand                                | The dismiss action already covers it. A bot-detection rule written before seeing the data guesses at a policy the owner never chose.             |

## Testing

Every GitHub call is faked (`Http::fake`), extending the existing
`FakesGitHub` concern; no test touches the network. Jobs run synchronously
under the suite's `QUEUE_CONNECTION=sync`, with `Queue::fake()` where
dispatch itself is the assertion. The suite covers, at minimum:

**Webhook receipt**

- A valid signature over the raw body is accepted; a wrong secret, a
  malformed header and a missing header are each rejected with 401 and leave
  no delivery row.
- An unknown webhook token returns 404 without touching the database.
- A replayed `X-GitHub-Delivery` returns 202 and creates no second row and no
  second candidate.
- A `ping` flips `manual_setup_required` to `active` and ingests nothing.
- A payload whose `repository.id` differs from the connection's is recorded
  failed and ingests nothing.
- The route issues no session cookie and requires no CSRF token.

**Ingestion and dedup**

- A merged pull request on the default branch becomes a pending candidate
  with every stored field matching the payload.
- The same pull request delivered twice, backfilled and then synced, in any
  order, yields exactly one row.
- A merge into a non-default branch is ignored.
- `closed` with `merged: false` is ignored.
- A dismissed candidate re-arriving by sync stays dismissed.
- An `edited` delivery refreshes a pending, untouched candidate.
- An `edited` delivery does not modify a candidate whose `curated_at` is set.
- `merged_at`, `number`, `html_url` and `base_branch` survive a refresh
  unchanged.

**Hook creation**

- A `201` stores `webhook_id` and `active`.
- A `403` leaves the connection stored, connected and
  `manual_setup_required` — asserting explicitly that the connection was
  *not* rolled back.
- A `5xx` and a timeout behave the same way.
- The retry route succeeds after a token replacement.
- Disconnect attempts the hook deletion, and still disconnects when it fails.

**Backfill and sync**

- Connecting dispatches the backfill job.
- Backfill stops at 100 merged pull requests and discards unmerged ones.
- Backfill stops at the 5-page cap on a repository of nothing but unmerged
  closed pull requests.
- Sync ingests only what is missing and stops on the first page yielding
  nothing new.
- A failing sync sets `status: failed` with the mapped code and keeps the
  candidates already ingested.

**Curation**

- Dismiss moves a candidate to `dismissed` and sets `curated_at`.
- Restore moves it back to `pending` and leaves `curated_at` set.
- The list paginates, orders by `merged_at` descending, and its tab counts
  are correct.

**Repointing**

- Repointing to a different `github_id` with candidates present is refused
  with an error on `repository_url`, and the connection is unchanged.
- Repointing with no candidates still works.
- A rename or transfer (same `github_id`) is unaffected by the rule.

**Secrecy and access**

- The raw database value of `webhook_secret` is not the plaintext.
- The GitHub token appears in no response, prop or delivery-log payload.
- A non-owner gets 403 on the list, dismiss, restore, sync and both webhook
  routes; a guest is redirected to login.
- A candidate belonging to another project returns 404.
- Exceeding 10 sync requests in a minute returns 429.

**Cascades**

- Deleting a project deletes its candidates.
- Deleting a connection deletes its deliveries and leaves candidates intact.

Screen payloads are asserted on Inertia props, per the existing convention.

## Open questions

- [ ] **Scheduling.** Two jobs now want a scheduler that does not exist:
      `model:prune` for the delivery log, and the reconciliation sweep this
      slice ships only as a button. Deciding how the production deployment
      runs `schedule:work` is a prerequisite for both.
- [ ] **Configurable target branch.** Teams shipping from `release` or
      `production` ingest nothing today. The connection is the obvious place
      for the field; whether it is worth a form control is not yet clear.
- [ ] **Backfill depth on demand.** 100 is a good default and a bad ceiling
      for someone importing a year of history into a first release. An
      "import more" action is the likely answer.
- [ ] **Ingestion rules.** Revisit bot and label filtering once real
      repositories have populated real lists, per the decision to let the
      data write the rule.
- [ ] **`APP_KEY` rotation.** Still open from SPEC-repository-connection, now
      covering webhook secrets too.

## Acceptance criteria

**Webhook setup**

1. Connecting a repository with a token carrying Webhooks access creates the
   hook and reports live delivery as active.
2. Connecting with a Pull-requests-only token still succeeds, and the
   repository screen reports manual setup required with the payload URL,
   the secret and the required settings.
3. Hook creation failing for any reason never prevents, rolls back or
   invalidates the connection.
4. Retrying automatic setup after replacing the token activates live
   delivery.
5. The first signature-valid delivery flips a manually created hook to
   active.
6. Disconnecting removes the hook from GitHub when possible, and disconnects
   regardless.
7. Every connection has a unique webhook token and its own secret, including
   connections created before this spec.

**Receiving**

8. A correctly signed delivery is acknowledged with 202 and recorded.
9. An incorrectly signed, unsigned or malformed delivery is rejected with 401
   and recorded nowhere.
10. An unknown webhook token returns 404.
11. A redelivered `X-GitHub-Delivery` produces no duplicate work.
12. Every delivery that is not acted on is recorded as ignored with a reason.

**Ingesting**

13. A pull request merged into the default branch appears in the project's
    pending list with its number, title, body, author, labels, merge time and
    GitHub link.
14. A pull request merged into any other branch does not appear.
15. A pull request closed without merging does not appear.
16. Connecting a repository backfills up to 100 recently merged pull
    requests without anyone asking.
17. The same pull request reaching the project by webhook, backfill and sync
    exists exactly once.
18. "Sync now" ingests pull requests the webhook never delivered.
19. A sync failure surfaces the mapped GitHub error and keeps what was
    already ingested.

**Curating**

20. The owner can dismiss a candidate, and it leaves the pending list.
21. A dismissed candidate is never resurrected by a later delivery or sync.
22. The owner can restore a dismissed candidate.
23. An untouched pending candidate picks up a title edited on GitHub.
24. A candidate the owner has ruled on is never overwritten by GitHub.
25. The list paginates at 20, newest merge first, with correct tab counts.

**Reading**

26. The project page shows the pending count, the live delivery state and the
    last sync time.
27. Each empty state — no connection, backfill running, nothing ingested,
    all triaged — renders its own message.

**Boundaries**

28. Repointing a connection to a different repository is refused while
    candidates exist, and the message says to disconnect first.
29. Disconnecting the repository keeps the project's candidates.
30. Deleting the project deletes its candidates.
31. A non-owner receives 403 on every curation route; a guest is redirected
    to login.
32. A candidate belonging to another project returns 404.
33. Exceeding 10 sync requests in a minute returns 429.
34. The webhook secret is not readable from the database in plaintext, and
    the GitHub token appears in no response, prop or stored payload.
