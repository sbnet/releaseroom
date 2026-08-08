<?php

namespace Tests\Unit\GitHub;

use App\Support\GitHub\RepositoryReference;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryReferenceTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function acceptedAddresses(): array
    {
        return [
            'https url' => ['https://github.com/acme/platform'],
            'http url' => ['http://github.com/acme/platform'],
            'www subdomain' => ['https://www.github.com/acme/platform'],
            'trailing slash' => ['https://github.com/acme/platform/'],
            'dot git suffix' => ['https://github.com/acme/platform.git'],
            'no scheme' => ['github.com/acme/platform'],
            'pull request deep link' => ['https://github.com/acme/platform/pull/12'],
            'tree deep link' => ['https://github.com/acme/platform/tree/main'],
            'ssh remote' => ['git@github.com:acme/platform.git'],
            'bare pair' => ['acme/platform'],
            'surrounding whitespace' => ["  https://github.com/acme/platform \n"],
            'uppercase host' => ['https://GitHub.com/acme/platform'],
        ];
    }

    #[DataProvider('acceptedAddresses')]
    public function test_an_accepted_address_resolves_to_the_repository(string $input): void
    {
        $reference = RepositoryReference::parse($input);

        $this->assertNotNull($reference);
        $this->assertSame('acme', $reference->owner);
        $this->assertSame('platform', $reference->name);
        $this->assertSame('acme/platform', $reference->fullName());
        $this->assertSame('https://github.com/acme/platform', $reference->url());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedAddresses(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'gitlab' => ['https://gitlab.com/acme/platform'],
            'bitbucket' => ['https://bitbucket.org/acme/platform'],
            'gist' => ['https://gist.github.com/acme/platform'],
            'enterprise host' => ['https://github.acme.com/acme/platform'],
            'lookalike host' => ['https://github.com.evil.test/acme/platform'],
            'owner only' => ['https://github.com/acme'],
            'no repository' => ['https://github.com/'],
            'ssh on another host' => ['git@gitlab.com:acme/platform.git'],
            'owner with spaces' => ['acme corp/platform'],
            'owner starting with a hyphen' => ['-acme/platform'],
            'owner with a doubled hyphen' => ['ac--me/platform'],
            'owner too long' => [str_repeat('a', 40).'/platform'],
            'name with a slash only' => ['acme//'],
            'dot as a name' => ['acme/.'],
            'double dot as a name' => ['acme/..'],
            'name too long' => ['acme/'.str_repeat('a', 101)],
        ];
    }

    #[DataProvider('rejectedAddresses')]
    public function test_a_rejected_address_resolves_to_nothing(string $input): void
    {
        $this->assertNull(RepositoryReference::parse($input));
    }

    public function test_the_dot_git_suffix_is_only_stripped_from_the_end(): void
    {
        $reference = RepositoryReference::parse('acme/platform.github.io');

        $this->assertNotNull($reference);
        $this->assertSame('platform.github.io', $reference->name);
    }
}
