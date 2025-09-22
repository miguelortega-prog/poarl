<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Jetstream\Jetstream;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public const EMAIL_DOMAIN = '@segurosbolivar.com';

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $registerableRoles = config('roles.registerable', []);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:150',
                Rule::unique('users', 'email'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! str_ends_with(strtolower($value), self::EMAIL_DOMAIN)) {
                        $fail('The email must belong to the domain ' . self::EMAIL_DOMAIN);
                    }
                },
            ],
            'password' => $this->passwordRules(),
            'role' => ['required', Rule::in($registerableRoles)],
            'position'      => ['required', 'string', 'max:100'],
            'supervisor_id' => ['nullable', 'exists:users,id'],
            'area_id'       => ['required', 'exists:areas,id'],

            // Subdepartment requerido excepto para manager y administrator
            'subdepartment_id' => [
                Rule::requiredIf(fn () => ! in_array($input['role'], ['manager', 'administrator'])),
                'nullable',
                'exists:subdepartments,id',
            ],

            // Team requerido excepto para manager, director y administrator
            'team_id' => [
                Rule::requiredIf(fn () => ! in_array($input['role'], ['manager', 'director', 'administrator'])),
                'nullable',
                'exists:teams,id',
            ],
        ])->validate();

        $user = User::create([
            'name'            => $validated['name'],
            'email'           => $validated['email'],
            'password'        => Hash::make($validated['password']),
            'position'        => $validated['position'] ?? null,
            'supervisor_id'   => $validated['supervisor_id'] ?? null,
            'area_id'         => $validated['area_id'] ?? null,
            'subdepartment_id'=> $validated['subdepartment_id'] ?? null,
            'team_id'         => $validated['team_id'] ?? null,
        ]);

        // Asigna rol con Spatie
        $user->assignRole($validated['role']);

        return $user;
    }
}
