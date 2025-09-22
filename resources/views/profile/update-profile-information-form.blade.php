<x-form-section submit="updateProfileInformation">
    <x-slot name="title">
        {{ __('Profile Information') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Update your account\'s profile information and email address.') }}
    </x-slot>

    <x-slot name="form">
        @php
            $selectedRole = $state['role'] ?? null;
            $selectedAreaId = $state['area_id'] ?? null;
            $selectedSubdepartmentId = $state['subdepartment_id'] ?? null;

            $availableSubdepartments = collect($availableSubdepartments ?? []);
            $availableTeams = collect($availableTeams ?? []);

            $showSubdepartment = $selectedRole && ! in_array($selectedRole, ['manager', 'administrator']);
            $showTeam = $selectedRole && ! in_array($selectedRole, ['manager', 'director', 'administrator']);

            $filteredSubdepartments = $availableSubdepartments->where('area_id', $selectedAreaId);
            $filteredTeams = $availableTeams->where('subdepartment_id', $selectedSubdepartmentId);
        @endphp

        <!-- Profile Photo -->
        @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
            <div x-data="{photoName: null, photoPreview: null}" class="col-span-6 sm:col-span-4">
                <!-- Profile Photo File Input -->
                <input type="file" id="photo" class="hidden"
                            wire:model.live="photo"
                            x-ref="photo"
                            x-on:change="
                                    photoName = $refs.photo.files[0].name;
                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        photoPreview = e.target.result;
                                    };
                                    reader.readAsDataURL($refs.photo.files[0]);
                            " />

                <x-label for="photo" value="{{ __('Photo') }}" />

                <!-- Current Profile Photo -->
                <div class="mt-2" x-show="! photoPreview">
                    <img src="{{ $this->user->profile_photo_url }}" alt="{{ $this->user->name }}" class="rounded-full size-20 object-cover">
                </div>

                <!-- New Profile Photo Preview -->
                <div class="mt-2" x-show="photoPreview" style="display: none;">
                    <span class="block rounded-full size-20 bg-cover bg-no-repeat bg-center"
                          x-bind:style="'background-image: url(\'' + photoPreview + '\');'">
                    </span>
                </div>

                <x-secondary-button class="mt-2 me-2" type="button" x-on:click.prevent="$refs.photo.click()">
                    {{ __('Select A New Photo') }}
                </x-secondary-button>

                @if ($this->user->profile_photo_path)
                    <x-secondary-button type="button" class="mt-2" wire:click="deleteProfilePhoto">
                        {{ __('Remove Photo') }}
                    </x-secondary-button>
                @endif

                <x-input-error for="photo" class="mt-2" />
            </div>
        @endif

        <!-- Name -->
        <div class="col-span-6 sm:col-span-3">
            <x-label for="name" value="{{ __('Name') }}" />
            <x-input id="name" type="text" class="mt-1 block w-full" wire:model="state.name" required autocomplete="name" />
            <x-input-error for="name" class="mt-2" />
        </div>

        <!-- Email -->
        <div class="col-span-6 sm:col-span-3">
            <x-label for="email" value="{{ __('Email') }}" />
            <x-input id="email" type="email" class="mt-1 block w-full" wire:model="state.email" autocomplete="username" disabled />
            <x-input-error for="email" class="mt-2" />

            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::emailVerification()) && ! $this->user->hasVerifiedEmail())
                <p class="text-sm mt-2 dark:text-white">
                    {{ __('Your email address is unverified.') }}

                    <button type="button" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" wire:click.prevent="sendEmailVerification">
                        {{ __('Click here to re-send the verification email.') }}
                    </button>
                </p>

                @if ($this->verificationLinkSent)
                    <p class="mt-2 font-medium text-sm text-green-600 dark:text-green-400">
                        {{ __('A new verification link has been sent to your email address.') }}
                    </p>
                @endif
            @endif
        </div>

        <!-- Position -->
        <div class="col-span-6 sm:col-span-3">
            <x-label for="position" value="{{ __('Cargo') }}" />
            <x-input id="position" type="text" class="mt-1 block w-full" wire:model="state.position" autocomplete="organization-title" />
            <x-input-error for="position" class="mt-2" />
        </div>

        <!-- Role -->
        <div class="col-span-6 sm:col-span-3">
            <x-label for="role" value="{{ __('Role') }}" />
            <select id="role" wire:model.live="state.role" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="" disabled>-- Selecciona un rol --</option>
                @foreach ($availableRoles ?? [] as $role)
                    <option value="{{ $role['name'] }}">{{ $role['name'] }}</option>
                @endforeach
            </select>
            <x-input-error for="role" class="mt-2" />
        </div>

        <!-- Area -->
        <div class="col-span-6 sm:col-span-3">
            <x-label for="area_id" value="{{ __('Área') }}" />
            <select id="area_id" wire:model.live="state.area_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                <option value="" disabled>-- Selecciona un área --</option>
                @foreach ($availableAreas ?? [] as $area)
                    <option value="{{ $area['id'] }}">{{ $area['name'] }}</option>
                @endforeach
            </select>
            <x-input-error for="area_id" class="mt-2" />
        </div>

        <!-- Subdepartment -->
        @if ($showSubdepartment)
            <div class="col-span-6 sm:col-span-3">
                <x-label for="subdepartment_id" value="{{ __('Subdepartamento') }}" />
                <select id="subdepartment_id" wire:model.live="state.subdepartment_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="" disabled>-- Selecciona un subdepartamento --</option>
                    @foreach ($filteredSubdepartments as $subdepartment)
                        <option value="{{ $subdepartment['id'] }}">{{ $subdepartment['name'] }}</option>
                    @endforeach
                </select>
                <x-input-error for="subdepartment_id" class="mt-2" />
            </div>
        @endif

        <!-- Team -->
        @if ($showTeam)
            <div class="col-span-6 sm:col-span-3">
                <x-label for="team_id" value="{{ __('Equipo') }}" />
                <select id="team_id" wire:model.live="state.team_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="" disabled>-- Selecciona un equipo --</option>
                    @foreach ($filteredTeams as $team)
                        <option value="{{ $team['id'] }}">{{ $team['name'] }}</option>
                    @endforeach
                </select>
                <x-input-error for="team_id" class="mt-2" />
            </div>
        @endif
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('Saved.') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled" wire:target="photo">
            {{ __('Save') }}
        </x-button>
    </x-slot>
</x-form-section>
