<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id), Rule::in([$user->email])],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
            'position' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in([
                'administrator',
                'manager',
                'director',
                'teamLead',
                'teamCoordinator',
                'teamMember',
            ])],
            'area_id' => ['required', 'exists:areas,id'],
            'subdepartment_id' => [
                Rule::requiredIf(fn () => ! in_array($input['role'] ?? null, ['manager', 'administrator'], true)),
                'nullable',
                'exists:subdepartments,id',
            ],
            'team_id' => [
                Rule::requiredIf(fn () => ! in_array($input['role'] ?? null, ['manager', 'director', 'administrator'], true)),
                'nullable',
                'exists:teams,id',
            ],
        ])->validateWithBag('updateProfileInformation');

        if (isset($validated['photo'])) {
            $user->updateProfilePhoto($validated['photo']);
        }

        if ($validated['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $validated);
        }

        $shouldResetSubdepartment = in_array($validated['role'], ['manager', 'administrator'], true);
        $shouldResetTeam = in_array($validated['role'], ['manager', 'director', 'administrator'], true);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $user->email,
            'position' => $validated['position'],
            'area_id' => $validated['area_id'],
            'subdepartment_id' => $shouldResetSubdepartment ? null : ($validated['subdepartment_id'] ?? null),
            'team_id' => $shouldResetTeam ? null : ($validated['team_id'] ?? null),
        ])->save();

        $user->syncRoles([$validated['role']]);
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
