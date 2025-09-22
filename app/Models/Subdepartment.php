<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subdepartment extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'area_id',
    ];

    /**
     * Área a la que pertenece este subdepartamento.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * Director asignado al subdepartamento.
     */
    public function director(): HasOne
    {
        return $this->hasOne(User::class)
            ->whereHas('roles', fn ($q) => $q->where('name', 'director'));
    }

    /**
     * Equipos que pertenecen a este subdepartamento.
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Usuarios asociados directamente al subdepartamento (por subdepartment_id).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
