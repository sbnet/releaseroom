<?php

namespace App\Support\GitHub;

use Stringable;

/**
 * An `owner/name` pair addressing a repository on github.com.
 *
 * Owners paste whatever GitHub handed them — a browser URL, a deep link into
 * a pull request, an SSH remote. This normalizes all of it to the only two
 * segments the API needs, and refuses anything that is not github.com.
 */
class RepositoryReference implements Stringable
{
    /**
     * GitHub logins: alphanumerics and single inner hyphens, 39 characters
     * at most.
     */
    private const OWNER_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/';

    /** Repository names, as GitHub accepts them. */
    private const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    /** Hosts we address. Enterprise and gist hosts are out of scope. */
    private const HOSTS = ['github.com', 'www.github.com'];

    public function __construct(
        public readonly string $owner,
        public readonly string $name,
    ) {}

    /**
     * Parse user input into a reference, or null when it addresses no
     * repository we can reach.
     */
    public static function parse(string $input): ?self
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $path = self::toPath($input);

        if ($path === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment) => $segment !== ''));

        if (count($segments) < 2) {
            return null;
        }

        [$owner, $name] = $segments;

        $name = preg_replace('/\.git$/i', '', $name) ?? $name;

        return self::make($owner, $name);
    }

    /**
     * Build a reference from two already-separated segments, validating both.
     */
    public static function make(string $owner, string $name): ?self
    {
        if (preg_match(self::OWNER_PATTERN, $owner) !== 1) {
            return null;
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }

        if ($name === '.' || $name === '..') {
            return null;
        }

        return new self($owner, $name);
    }

    /**
     * Reduce any accepted input form to a bare `owner/name/...` path.
     *
     * Returns null when the input names a host that is not github.com.
     */
    private static function toPath(string $input): ?string
    {
        // SSH remotes: git@github.com:owner/name.git
        if (preg_match('#^(?:ssh://)?[^@/\s]+@([^:/\s]+)[:/](.+)$#', $input, $matches) === 1) {
            return self::isGithubHost($matches[1]) ? $matches[2] : null;
        }

        // Anything with a scheme, or a bare host: github.com/owner/name
        if (preg_match('#^(?:(?<scheme>[a-zA-Z][a-zA-Z0-9+.-]*)://)?(?<host>[^/\s]+\.[^/\s]+)(?<path>/.*)?$#', $input, $matches) === 1) {
            if (! self::isGithubHost($matches['host'])) {
                return null;
            }

            return trim($matches['path'] ?? '', '/');
        }

        // Bare owner/name
        return str_contains($input, '/') ? $input : null;
    }

    private static function isGithubHost(string $host): bool
    {
        return in_array(strtolower($host), self::HOSTS, true);
    }

    /**
     * The canonical `owner/name` GitHub itself uses.
     */
    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }

    /**
     * The browser URL for this repository.
     */
    public function url(): string
    {
        return "https://github.com/{$this->fullName()}";
    }

    public function __toString(): string
    {
        return $this->fullName();
    }
}
