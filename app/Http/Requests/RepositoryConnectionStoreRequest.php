<?php

namespace App\Http\Requests;

use App\Concerns\RepositoryConnectionValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class RepositoryConnectionStoreRequest extends FormRequest
{
    use RepositoryConnectionValidationRules;

    /**
     * Managing a project's integration is managing the project: authorize
     * before validating, so a non-owner is refused outright.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('project'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'repository_url' => $this->repositoryUrlRules(),
            'token' => $this->tokenRules(required: true),
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateRepositoryReference($validator));
    }
}
