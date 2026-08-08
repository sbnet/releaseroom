# ReleaseRoom

Turn merged pull requests into a published changelog.

ReleaseRoom ingests merged pull requests from GitHub, lets a human curate
and group them into a release, and publishes a public release page with a
feed and an embeddable widget.

It is also the reference showcase for [flux](https://github.com/sbnet/flux),
the spec-driven delivery workflow that builds it: every feature here starts
as a spec in [specs/](specs/), becomes an issue, and lands through a pull
request that passed the flux quality gates.

## Getting started

```bash
composer setup
composer run dev
```

The application is then served on the URL printed by `artisan dev`.

## Quality gates

Gates are declared in [flux-config.yml](flux-config.yml) and enforced
locally by the flux hooks, in CI by
[.github/workflows/ci.yml](.github/workflows/ci.yml):

```bash
bash .claude/hooks/flux-gate.sh ci
```

Pint, PHPStan, vue-tsc, ESLint, Prettier and Pest all have to pass before
anything is pushed.
