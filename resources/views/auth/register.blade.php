<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('register') }}"
              x-data="registrationForm({
                roles: {{ $roles->toJson() }},
                areas: {{ $areas->toJson() }},
                subdepartments: {{ $subdepartments->toJson() }},
                teams: {{ $teams->toJson() }}
              })"
              @submit.prevent="validateForm($event)">
            @csrf

            {{-- Fila 1: Name | Email | Cargo --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <x-label for="name" value="{{ __('Name') }}" />
                    <x-input id="name" class="block mt-1 w-full" type="text" name="name"
                        x-model="name" @blur="validateField('name')" />
                    <p x-show="errors.name" class="text-red-500 text-sm mt-1" x-text="errors.name"></p>
                </div>

                <div>
                    <x-label for="email" value="{{ __('Email') }}" />
                    <x-input id="email" class="block mt-1 w-full" type="email" name="email"
                        x-model="email" @blur="validateField('email')" />
                    <p x-show="errors.email" class="text-red-500 text-sm mt-1" x-text="errors.email"></p>
                </div>

                <div>
                    <x-label for="position" value="{{ __('Cargo') }}" />
                    <x-input id="position" class="block mt-1 w-full" type="text" name="position"
                        x-model="position" @blur="validateField('position')" />
                    <p x-show="errors.position" class="text-red-500 text-sm mt-1" x-text="errors.position"></p>
                </div>
            </div>

            {{-- Fila 2: Role | Area --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <x-label for="role" value="{{ __('Role') }}" />
                    <select x-model="role" id="role" name="role"
                        class="block mt-1 w-full rounded-md border-gray-300 dark:border-gray-700"
                        @blur="validateField('role')">
                        <option value="" disabled>-- Selecciona un rol --</option>
                        <template x-for="r in roles" :key="r.id">
                            <option :value="r.name" x-text="r.name"></option>
                        </template>
                    </select>
                    <p x-show="errors.role" class="text-red-500 text-sm mt-1" x-text="errors.role"></p>
                </div>

                <div>
                    <x-label for="area_id" value="{{ __('Área') }}" />
                    <select x-model="area_id" id="area_id" name="area_id"
                        class="block mt-1 w-full rounded-md border-gray-300 dark:border-gray-700"
                        @blur="validateField('area_id')">
                        <option value="" disabled>-- Selecciona un área --</option>
                        <template x-for="a in areas" :key="a.id">
                            <option :value="a.id" x-text="a.name"></option>
                        </template>
                    </select>
                    <p x-show="errors.area_id" class="text-red-500 text-sm mt-1" x-text="errors.area_id"></p>
                </div>
            </div>

            {{-- Fila 3: Subdepartamento | Team --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div x-show="showSubdepartment">
                    <x-label for="subdepartment_id" value="{{ __('Subdepartamento') }}" />
                    <select x-model="subdepartment_id" id="subdepartment_id" name="subdepartment_id"
                        class="block mt-1 w-full rounded-md border-gray-300 dark:border-gray-700"
                        @blur="validateField('subdepartment_id')">
                        <option value="" disabled>-- Selecciona un subdepartamento --</option>
                        <template x-for="s in filteredSubdepartments" :key="s.id">
                            <option :value="s.id" x-text="s.name"></option>
                        </template>
                    </select>
                    <p x-show="errors.subdepartment_id" class="text-red-500 text-sm mt-1" x-text="errors.subdepartment_id"></p>
                </div>

                <div x-show="showTeam">
                    <x-label for="team_id" value="{{ __('Team') }}" />
                    <select x-model="team_id" id="team_id" name="team_id"
                        class="block mt-1 w-full rounded-md border-gray-300 dark:border-gray-700"
                        @blur="validateField('team_id')">
                        <option value="" disabled>-- Selecciona un equipo --</option>
                        <template x-for="t in filteredTeams" :key="t.id">
                            <option :value="t.id" x-text="t.name"></option>
                        </template>
                    </select>
                    <p x-show="errors.team_id" class="text-red-500 text-sm mt-1" x-text="errors.team_id"></p>
                </div>
            </div>

            {{-- Fila 4: Password | Confirm Password --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                    <x-label for="password" value="{{ __('Password') }}" />
                    <x-input id="password" class="block mt-1 w-full" type="password" name="password"
                        x-model="password" @blur="validateField('password')" />
                    <p x-show="errors.password" class="text-red-500 text-sm mt-1" x-text="errors.password"></p>
                </div>

                <div>
                    <x-label for="password_confirmation" value="{{ __('Confirm Password') }}" />
                    <x-input id="password_confirmation" class="block mt-1 w-full" type="password"
                        name="password_confirmation" x-model="password_confirmation" @blur="validateField('password_confirmation')" />
                    <p x-show="errors.password_confirmation" class="text-red-500 text-sm mt-1" x-text="errors.password_confirmation"></p>
                </div>
            </div>

            {{-- Botones --}}
            <div class="flex items-center justify-end mt-6">
                <a href="{{ route('login') }}"
                   class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
                    {{ __('Already registered?') }}
                </a>

                <x-button class="ms-4">
                    {{ __('Register') }}
                </x-button>
            </div>
        </form>
    </x-authentication-card>

    {{-- Script Alpine con validaciones --}}
    <script>
        function registrationForm({ roles, areas, subdepartments, teams }) {
            return {
                roles, areas, subdepartments, teams,
                role: '', area_id: '', subdepartment_id: '', team_id: '',
                position: '', name: '', email: '', password: '', password_confirmation: '',
                errors: {},

                get showSubdepartment() {
                    return this.role && !['manager', 'administrator'].includes(this.role);
                },
                get showTeam() {
                    return this.role && !['manager', 'director', 'administrator'].includes(this.role);
                },
                get filteredSubdepartments() {
                    if (!this.area_id) return [];
                    return this.subdepartments.filter(s => s.area_id == this.area_id);
                },
                get filteredTeams() {
                    if (!this.subdepartment_id) return [];
                    return this.teams.filter(t => t.subdepartment_id == this.subdepartment_id);
                },

                validateField(field) {
                    switch (field) {
                        case 'name':
                            if (!this.name) this.errors.name = 'El nombre es obligatorio';
                            else if (this.name.length > 255) this.errors.name = 'Máximo 255 caracteres';
                            else this.errors.name = '';
                            break;

                        case 'email':
                            if (!this.email) this.errors.email = 'El correo es obligatorio';
                            else if (!this.email.includes('@')) this.errors.email = 'Formato inválido';
                            else if (!this.email.endsWith('@segurosbolivar.com')) {
                                this.errors.email = 'Debe usar un correo @segurosbolivar.com';
                            } else if (this.email.length > 150) {
                                this.errors.email = 'Máximo 150 caracteres';
                            } else this.errors.email = '';
                            break;

                        case 'position':
                            if (!this.position) this.errors.position = 'El cargo es obligatorio';
                            else if (this.position.length > 100) this.errors.position = 'Máximo 100 caracteres';
                            else this.errors.position = '';
                            break;

                        case 'role':
                            const validRoles = this.roles.map(r => r.name);
                            if (!this.role) this.errors.role = 'Debe seleccionar un rol';
                            else if (!validRoles.includes(this.role)) {
                                this.errors.role = 'Rol inválido';
                            } else this.errors.role = '';
                            break;

                        case 'area_id':
                            if (!this.area_id) {
                                this.errors.area_id = 'Debe seleccionar un área';
                            } else this.errors.area_id = '';
                            break;

                        case 'subdepartment_id':
                            if (this.showSubdepartment && !this.subdepartment_id) {
                                this.errors.subdepartment_id = 'Debe seleccionar un subdepartamento';
                            } else this.errors.subdepartment_id = '';
                            break;

                        case 'team_id':
                            if (this.showTeam && !this.team_id) {
                                this.errors.team_id = 'Debe seleccionar un equipo';
                            } else this.errors.team_id = '';
                            break;

                        case 'password':
                            if (!this.password) this.errors.password = 'La contraseña es obligatoria';
                            else if (this.password.length < 8) this.errors.password = 'Mínimo 8 caracteres';
                            else this.errors.password = '';
                            break;

                        case 'password_confirmation':
                            if (this.password !== this.password_confirmation) {
                                this.errors.password_confirmation = 'Las contraseñas no coinciden';
                            } else this.errors.password_confirmation = '';
                            break;
                    }
                },

                validateForm(event) {
                    ['name','email','position','role','area_id','subdepartment_id','team_id','password','password_confirmation']
                        .forEach(f => this.validateField(f));

                    if (Object.values(this.errors).every(e => !e)) {
                        event.target.submit();
                    }
                }
            };
        }
    </script>

</x-guest-layout>
