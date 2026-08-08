<?php

namespace App\Concerns;

use App\Services\GitHub\RepositoryVerifier;
use App\Support\GitHub\RepositoryReference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

/**
 * Shape checks for a repository connection form.
 *
 * These only cover what can be judged without leaving the server: whether the
 * address names a repository on github.com at all, and whether the token
 * looks like a token. Whether either one actually works is settled by
 * {@see RepositoryVerifier}.
 */
trait RepositoryConnectionValidationRules
{
    /**
     * Trim the pasted values before validating.
     *
     * Both fields are copy-pasted, and both routinely arrive with a trailing
     * newline or a stray space.
     */
    protected function prepareForValidation(): void
    {
        foreach (['repository_url', 'token'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }

    /**
     * Rules for the repository address.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function repositoryUrlRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Rules for the token.
     *
     * No prefix is enforced: GitHub has changed its token formats before, and
     * the live API call is the only check that matters.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function tokenRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:255',
            'regex:/^\S+$/',
        ];
    }

    /**
     * Reject an address that names no repository we can reach.
     *
     * Done after the base rules rather than as a rule of its own so that the
     * message stays a single, actionable sentence.
     */
    protected function validateRepositoryReference(Validator $validator): void
    {
        if ($validator->errors()->has('repository_url')) {
            return;
        }

        $input = $this->input('repository_url');

        if (! is_string($input) || RepositoryReference::parse($input) === null) {
            $validator->errors()->add(
                'repository_url',
                __('Enter a GitHub repository address, like https://github.com/owner/name.'),
            );
        }
    }

    /**
     * The repository the owner addressed, once validation has passed.
     */
    public function reference(): RepositoryReference
    {
        /** @var string $input */
        $input = $this->validated('repository_url');

        /** @var RepositoryReference $reference */
        $reference = RepositoryReference::parse($input);

        return $reference;
    }

    /**
     * The token the owner submitted, or null when they left the field blank.
     */
    public function token(): ?string
    {
        $token = $this->validated('token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.regex' => __('A token contains no spaces. Paste it again.'),
        ];
    }
}
