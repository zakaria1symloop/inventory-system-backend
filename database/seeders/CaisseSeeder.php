<?php

namespace Database\Seeders;

use App\Models\Caisse;
use App\Models\User;
use Illuminate\Database\Seeder;

class CaisseSeeder extends Seeder
{
    public function run(): void
    {
        $roleTypeMap = [
            'admin' => 'principale',
            'seller' => 'vendeur',
            'livreur' => 'livreur',
        ];

        foreach ($roleTypeMap as $role => $type) {
            $users = User::where('role', $role)
                ->whereDoesntHave('caisse')
                ->get();

            foreach ($users as $user) {
                Caisse::create([
                    'user_id' => $user->id,
                    'type' => $type,
                ]);
            }
        }
    }
}
