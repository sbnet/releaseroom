<?php

namespace App\Support\GitHub;

use Carbon\CarbonImmutable;
use Exception;

/**
 * A merged pull request, as GitHub describes it.
 *
 * The same shape arrives from two places — the `pull_request` object inside a
 * webhook payload, and each entry of the pull request list endpoint — so both
 * ingestion paths normalize through here and the rest of the application
 * never has to know which one it is looking at.
 */
class MergedPullRequest
{
    /** GitHub titles are short; the column is not, but a cap keeps it honest. */
    private const TITLE_LIMIT = 512;

    /**
     * A changelog entry needs a paragraph, not a novel. The link back to
     * GitHub is always there for the full text.
     *
     * Counted in **bytes**, not characters: a `text` column holds 65,535
     * bytes on MySQL, and a body full of emoji or CJK cut to 65,535
     * *characters* would still be refused by it. SQLite, which development and
     * the test suite run on, has no such ceiling and would never show it.
     */
    private const BODY_LIMIT = 65535;

    /**
     * The widths of the remaining string columns.
     *
     * GitHub's own values sit far below these, so nothing here is expected to
     * bite. They are cut anyway for the same reason the body is: SQLite, which
     * development and the suite run on, ignores a declared width entirely, so
     * an over-long value would sail through every test and only surface as a
     * length error against the production engine. Degrading a stored value
     * beats refusing the whole ingestion.
     */
    private const LOGIN_LIMIT = 39;

    private const URL_LIMIT = 255;

    private const BRANCH_LIMIT = 255;

    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        public readonly int $githubId,
        public readonly int $number,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $authorLogin,
        public readonly ?string $authorAvatarUrl,
        public readonly array $labels,
        public readonly string $baseBranch,
        public readonly CarbonImmutable $mergedAt,
        public readonly string $htmlUrl,
    ) {}

    /**
     * Read a pull request payload, or null when it is not a merged one we can
     * make sense of.
     *
     * An unmerged pull request is the common case here, not an error: the
     * list endpoint returns closed-and-abandoned alongside closed-and-merged,
     * and a `pull_request` subscription delivers every action there is.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $githubId = $payload['id'] ?? null;
        $number = $payload['number'] ?? null;
        $mergedAt = $payload['merged_at'] ?? null;

        if (! is_int($githubId) || ! is_int($number) || ! is_string($mergedAt)) {
            return null;
        }

        try {
            $merged = CarbonImmutable::parse($mergedAt);
        } catch (Exception) {
            return null;
        }

        $base = $payload['base'] ?? null;
        $baseBranch = is_array($base) && is_string($base['ref'] ?? null) ? $base['ref'] : null;

        if ($baseBranch === null) {
            return null;
        }

        $user = $payload['user'] ?? null;
        $user = is_array($user) ? $user : [];

        $title = is_string($payload['title'] ?? null) ? $payload['title'] : '';
        $body = is_string($payload['body'] ?? null) ? $payload['body'] : null;
        $authorLogin = is_string($user['login'] ?? null) ? $user['login'] : null;
        $authorAvatarUrl = is_string($user['avatar_url'] ?? null) ? $user['avatar_url'] : null;
        $htmlUrl = is_string($payload['html_url'] ?? null) ? $payload['html_url'] : '';

        return new self(
            githubId: $githubId,
            number: $number,
            title: mb_substr($title, 0, self::TITLE_LIMIT),
            /* mb_strcut cuts on a byte budget without splitting a character. */
            body: $body === null ? null : mb_strcut($body, 0, self::BODY_LIMIT),
            authorLogin: $authorLogin === null ? null : mb_substr($authorLogin, 0, self::LOGIN_LIMIT),
            authorAvatarUrl: $authorAvatarUrl === null ? null : mb_substr($authorAvatarUrl, 0, self::URL_LIMIT),
            labels: self::labelsFrom($payload['labels'] ?? null),
            baseBranch: mb_substr($baseBranch, 0, self::BRANCH_LIMIT),
            mergedAt: $merged,
            htmlUrl: mb_substr($htmlUrl, 0, self::URL_LIMIT),
        );
    }

    /**
     * Whether this pull request landed on the branch the project ships from.
     */
    public function targets(string $branch): bool
    {
        return $this->baseBranch === $branch;
    }

    /**
     * The label names, flattened.
     *
     * Stored for triage rather than for grouping: deciding that a pull
     * request is noise is far quicker when its labels are on the row.
     *
     * @return list<string>
     */
    private static function labelsFrom(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        $names = [];

        foreach ($labels as $label) {
            if (is_array($label) && is_string($label['name'] ?? null)) {
                $names[] = $label['name'];
            }
        }

        return $names;
    }
}
