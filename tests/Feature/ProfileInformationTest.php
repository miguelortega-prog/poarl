<?php

namespace Tests\Feature;

use App\Livewire\Profile\UpdateProfileInformationForm;
use App\Models\Area;
use App\Models\Subdepartment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_profile_information_is_available(): void
    {
        $area = Area::create(['name' => 'Tecnología']);
        $subdepartment = Subdepartment::create([
            'name' => 'Plataforma',
            'area_id' => $area->id,
        ]);
        $team = Team::create([
            'name' => 'Equipo Alfa',
            'subdepartment_id' => $subdepartment->id,
        ]);
        $role = Role::create(['name' => 'teamMember']);

        $user = User::factory()->create([
            'position' => 'Analista',
            'area_id' => $area->id,
            'subdepartment_id' => $subdepartment->id,
            'team_id' => $team->id,
        ]);
        $user->assignRole($role);

        $this->actingAs($user);

        $component = Livewire::test(UpdateProfileInformationForm::class);

        $this->assertEquals($user->name, $component->state['name']);
        $this->assertEquals($user->email, $component->state['email']);
        $this->assertEquals('Analista', $component->state['position']);
        $this->assertEquals($role->name, $component->state['role']);
        $this->assertEquals($area->id, $component->state['area_id']);
        $this->assertEquals($subdepartment->id, $component->state['subdepartment_id']);
        $this->assertEquals($team->id, $component->state['team_id']);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $initialArea = Area::create(['name' => 'Operaciones']);
        $initialSubdepartment = Subdepartment::create([
            'name' => 'Logística',
            'area_id' => $initialArea->id,
        ]);
        $initialTeam = Team::create([
            'name' => 'Equipo Base',
            'subdepartment_id' => $initialSubdepartment->id,
        ]);

        $targetArea = Area::create(['name' => 'Innovación']);
        $targetSubdepartment = Subdepartment::create([
            'name' => 'Investigación',
            'area_id' => $targetArea->id,
        ]);
        $targetTeam = Team::create([
            'name' => 'Equipo Beta',
            'subdepartment_id' => $targetSubdepartment->id,
        ]);

        $memberRole = Role::create(['name' => 'teamMember']);
        $leadRole = Role::create(['name' => 'teamLead']);

        $user = User::factory()->create([
            'position' => 'Analista',
            'area_id' => $initialArea->id,
            'subdepartment_id' => $initialSubdepartment->id,
            'team_id' => $initialTeam->id,
        ]);
        $user->assignRole($memberRole);

        $this->actingAs($user);

        $originalEmail = $user->email;

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state.name', 'Nuevo Nombre')
            ->set('state.email', $originalEmail)
            ->set('state.position', 'Líder Técnico')
            ->set('state.role', $leadRole->name)
            ->set('state.area_id', $targetArea->id)
            ->set('state.subdepartment_id', $targetSubdepartment->id)
            ->set('state.team_id', $targetTeam->id)
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Nuevo Nombre', $user->name);
        $this->assertEquals('Líder Técnico', $user->position);
        $this->assertEquals($targetArea->id, $user->area_id);
        $this->assertEquals($targetSubdepartment->id, $user->subdepartment_id);
        $this->assertEquals($targetTeam->id, $user->team_id);
        $this->assertEquals($originalEmail, $user->email);
        $this->assertTrue($user->hasRole($leadRole->name));
        $this->assertFalse($user->hasRole($memberRole->name));
    }

    public function test_email_cannot_be_updated_from_profile(): void
    {
        $area = Area::create(['name' => 'Administración']);
        $subdepartment = Subdepartment::create([
            'name' => 'Finanzas',
            'area_id' => $area->id,
        ]);
        $team = Team::create([
            'name' => 'Equipo Gamma',
            'subdepartment_id' => $subdepartment->id,
        ]);
        $role = Role::create(['name' => 'teamCoordinator']);

        $user = User::factory()->create([
            'position' => 'Coordinador',
            'area_id' => $area->id,
            'subdepartment_id' => $subdepartment->id,
            'team_id' => $team->id,
        ]);
        $user->assignRole($role);

        $this->actingAs($user);

        $originalEmail = $user->email;

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state.email', 'nuevo@segurosbolivar.com')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'in']);

        $this->assertEquals($originalEmail, $user->fresh()->email);
    }
}
