<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmailBlacklistSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $blacklistEmails = [
            // Emails genéricos no válidos
            ['email' => 'notengo@hotmail.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'a@a.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'no@gmail.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'notengo@gmail.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'no@hotmail.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'notengo@notengo.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'a@b.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'notiene@gmail.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'notiene@hotmail.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'notiene@go.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'notiene@notie.com', 'reason' => 'Email genérico no válido'],
            ['email' => 'notiene@notiene.com', 'reason' => 'Email genérico no válido'],
        ];

        // Generar emails de una sola letra (a-z) para @gmail.com y @hotmail.com
        foreach (range('a', 'z') as $letter) {
            $blacklistEmails[] = [
                'email' => $letter . '@gmail.com',
                'reason' => 'Email de una sola letra',
            ];
            $blacklistEmails[] = [
                'email' => $letter . '@hotmail.com',
                'reason' => 'Email de una sola letra',
            ];
        }

        // Insertar en la tabla
        foreach ($blacklistEmails as $email) {
            DB::table('email_blacklist')->insertOrIgnore([
                'email' => strtolower($email['email']),
                'reason' => $email['reason'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
