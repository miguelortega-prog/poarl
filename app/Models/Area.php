<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Area extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * Usuarios asociados directamente al área (ej. Managers).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Subdepartamentos dentro de esta área.
     */
    public function subdepartments(): HasMany
    {
        return $this->hasMany(Subdepartment::class);
    }

    /**
     * Manager asignado al área.
     */
    public function manager(): HasOne
    {
        return $this->hasOne(User::class)
            ->whereHas('roles', fn ($q) => $q->where('name', 'manager'));
    }
}
