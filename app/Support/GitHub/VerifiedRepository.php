<?php

namespace App\Support\GitHub;

use Carbon\CarbonImmutable;

/**
 * What GitHub told us about a repository we just proved we can read.
 */
class VerifiedRepository
{
    public function __construct(
        public readonly int $githubId,
        public readonly RepositoryReference $reference,
        public readonly bool $isPrivate,
        public readonly string $defaultBranch,
        public readonly ?CarbonImmutable $tokenExpiresAt,
    ) {}
}
