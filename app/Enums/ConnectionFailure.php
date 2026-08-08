<?php

namespace App\Enums;

/**
 * Why a repository connection could not be verified.
 *
 * Every failure carries the field it belongs to and the sentence the owner
 * needs in order to fix it. A generic "could not connect" would leave them
 * guessing between a bad token, a missing permission and a wrong address.
 */
enum ConnectionFailure: string
{
    /** The token was rejected outright: revoked, mistyped or expired. */
    case InvalidToken = 'invalid_token';

    /** The token is valid but carries no access to this repository. */
    case InsufficientAccess = 'insufficient_access';

    /** No repository at this address, or none this token is allowed to see. */
    case RepositoryNotFound = 'repository_not_found';

    /** The repository is readable, but pull requests are not. */
    case MissingPullScope = 'missing_pull_scope';

    /** The token's GitHub quota is exhausted. */
    case RateLimited = 'rate_limited';

    /** GitHub is down, unreachable or too slow to answer. */
    case GithubUnavailable = 'github_unavailable';

    /** The address now resolves to a different repository than the one stored. */
    case IdentityChanged = 'identity_changed';

    /**
     * The form field this failure belongs to.
     *
     * `github` is the form-level bucket: the failure is not about anything
     * the owner typed, so no input can be highlighted.
     */
    public function field(): string
    {
        return match ($this) {
            self::InvalidToken,
            self::InsufficientAccess,
            self::MissingPullScope => 'token',
            self::RepositoryNotFound => 'repository_url',
            self::RateLimited,
            self::GithubUnavailable,
            self::IdentityChanged => 'github',
        };
    }

    /**
     * The message shown to the owner.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidToken => __('This token is invalid or has expired.'),
            self::InsufficientAccess => __('This token does not grant access to this repository.'),
            self::RepositoryNotFound => __('No repository found at this address, or this token cannot see it.'),
            self::MissingPullScope => __('This token cannot read pull requests. Grant the Pull requests permission, read-only.'),
            self::RateLimited => __("GitHub's rate limit is exhausted for this token. Try again later."),
            self::GithubUnavailable => __('GitHub is unavailable right now. Try again in a moment.'),
            self::IdentityChanged => __('The repository at this address is no longer the one you connected.'),
        };
    }
}
