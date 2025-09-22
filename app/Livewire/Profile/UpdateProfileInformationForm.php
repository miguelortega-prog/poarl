<?php

namespace App\Livewire\Profile;

use App\Models\Area;
use App\Models\Subdepartment;
use App\Models\Team;
use Illuminate\Support\Arr;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm as BaseUpdateProfileInformationForm;
use Spatie\Permission\Models\Role;

class UpdateProfileInformationForm extends BaseUpdateProfileInformationForm
{
    /**
     * Available role options for the profile form.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $availableRoles = [];

    /**
     * Available area options for the profile form.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $availableAreas = [];

    /**
     * Available subdepartment options for the profile form.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $availableSubdepartments = [];

    /**
     * Available team options for the profile form.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $availableTeams = [];

    /**
     * Prepare the component state.
     */
    public function mount(): void
    {
        parent::mount();

        $user = $this->user;

        $this->availableRoles = Role::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])
            ->all();

        $this->availableAreas = Area::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn ($area) => ['id' => $area->id, 'name' => $area->name])
            ->all();

        $this->availableSubdepartments = Subdepartment::query()
            ->select('id', 'name', 'area_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($subdepartment) => [
                'id' => $subdepartment->id,
                'name' => $subdepartment->name,
                'area_id' => $subdepartment->area_id,
            ])
            ->all();

        $this->availableTeams = Team::query()
            ->select('id', 'name', 'subdepartment_id')
            ->orderBy('name')
            ->get()
            ->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'subdepartment_id' => $team->subdepartment_id,
            ])
            ->all();

        $this->state['role'] = $user->getRoleNames()->first();
        $this->state['position'] = Arr::get($this->state, 'position', $user->position);
        $this->state['area_id'] = Arr::get($this->state, 'area_id', $user->area_id);
        $this->state['subdepartment_id'] = Arr::get($this->state, 'subdepartment_id', $user->subdepartment_id);
        $this->state['team_id'] = Arr::get($this->state, 'team_id', $user->team_id);
    }

    /**
     * Reset dependent fields when the selected area changes.
     */
    public function updatedStateAreaId(mixed $_): void
    {
        $this->state['subdepartment_id'] = null;
        $this->state['team_id'] = null;
    }

    /**
     * Reset the team when the selected subdepartment changes.
     */
    public function updatedStateSubdepartmentId(mixed $_): void
    {
        $this->state['team_id'] = null;
    }

    /**
     * Ensure dependent selections stay consistent with the chosen role.
     */
    public function updatedStateRole(mixed $value): void
    {
        if (in_array($value, ['manager', 'administrator'], true)) {
            $this->state['subdepartment_id'] = null;
            $this->state['team_id'] = null;
        } elseif ($value === 'director') {
            $this->state['team_id'] = null;
        }
    }
}
