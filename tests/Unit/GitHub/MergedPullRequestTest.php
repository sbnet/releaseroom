<?php

use App\Support\GitHub\MergedPullRequest;

function payload(array $overrides = []): array
{
    return array_merge([
        'id' => 1001,
        'number' => 7,
        'title' => 'Add the thing',
        'body' => 'It does the thing.',
        'user' => ['login' => 'octocat', 'avatar_url' => 'https://example.test/a.png'],
        'labels' => [['name' => 'feature']],
        'base' => ['ref' => 'main'],
        'merged_at' => '2026-07-01T10:00:00Z',
        'html_url' => 'https://github.com/acme/platform/pull/7',
    ], $overrides);
}

it('reads a merged pull request', function () {
    $pull = MergedPullRequest::fromPayload(payload());

    expect($pull)->not->toBeNull()
        ->and($pull->githubId)->toBe(1001)
        ->and($pull->number)->toBe(7)
        ->and($pull->title)->toBe('Add the thing')
        ->and($pull->authorLogin)->toBe('octocat')
        ->and($pull->labels)->toBe(['feature'])
        ->and($pull->baseBranch)->toBe('main')
        ->and($pull->mergedAt->toIso8601String())->toBe('2026-07-01T10:00:00+00:00');
});

it('refuses anything that is not a merged pull request', function (array $overrides) {
    expect(MergedPullRequest::fromPayload(payload($overrides)))->toBeNull();
})->with([
    'never merged' => [['merged_at' => null]],
    'unparseable merge date' => [['merged_at' => 'the day before yesterday']],
    'no id' => [['id' => null]],
    'no number' => [['number' => null]],
    'no base branch' => [['base' => null]],
    'base without a ref' => [['base' => ['label' => 'acme:main']]],
]);

it('survives a deleted author', function () {
    $pull = MergedPullRequest::fromPayload(payload(['user' => null]));

    expect($pull->authorLogin)->toBeNull()
        ->and($pull->authorAvatarUrl)->toBeNull();
});

it('keeps only the label names, and skips malformed ones', function () {
    $pull = MergedPullRequest::fromPayload(payload([
        'labels' => [['name' => 'bug'], ['color' => 'ff0000'], 'nonsense', ['name' => 'ui']],
    ]));

    expect($pull->labels)->toBe(['bug', 'ui']);
});

it('treats absent labels as none', function () {
    $pull = MergedPullRequest::fromPayload(payload(['labels' => null]));

    expect($pull->labels)->toBe([]);
});

it('truncates a body far longer than any changelog needs', function () {
    $pull = MergedPullRequest::fromPayload(payload(['body' => str_repeat('a', 70_000)]));

    expect($pull->body)->toHaveLength(65_535);
});

it('truncates an implausibly long title', function () {
    $pull = MergedPullRequest::fromPayload(payload(['title' => str_repeat('a', 600)]));

    expect($pull->title)->toHaveLength(512);
});

it('accepts a missing body', function () {
    expect(MergedPullRequest::fromPayload(payload(['body' => null]))->body)->toBeNull();
});

it('knows which branch it landed on', function () {
    $pull = MergedPullRequest::fromPayload(payload(['base' => ['ref' => 'release']]));

    expect($pull->targets('release'))->toBeTrue()
        ->and($pull->targets('main'))->toBeFalse();
});
