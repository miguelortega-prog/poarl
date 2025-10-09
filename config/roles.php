<?php

return [
    /**
     * Roles que pueden ser seleccionados durante el registro de usuarios.
     *
     * Nota: El rol 'administrator' fue removido por seguridad.
     * Los administradores se crean automáticamente vía AdminUserSeeder.
     */
    'registerable' => [
        'manager',
        'director',
        'teamLead',
        'teamCoordinator',
        'teamMember',
    ],
];
