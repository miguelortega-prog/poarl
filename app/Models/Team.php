<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'subdepartment_id',
    ];

    /**
     * Subdepartamento al que pertenece este equipo.
     */
    public function subdepartment(): BelongsTo
    {
        return $this->belongsTo(Subdepartment::class);
    }

    /**
     * Usuarios asociados a este equipo.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Team Lead asignado a este equipo.
     */
    public function lead(): HasOne
    {
        return $this->hasOne(User::class)
            ->whereHas('roles', fn ($q) => $q->where('name', 'teamLead'));
    }

    /**
     * Coordinador asignado a este equipo.
     */
    public function coordinator(): HasOne
    {
        return $this->hasOne(User::class)
            ->whereHas('roles', fn ($q) => $q->where('name', 'teamCoordinator'));
    }
}
