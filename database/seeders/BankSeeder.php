<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $banks = [
            ['name' => 'BANCO DE VENEZUELA', 'code' => '0102'],
            ['name' => 'BANESCO', 'code' => '0134'],
            ['name' => 'MERCANTIL', 'code' => '0105'],
            ['name' => 'BBVA PROVINCIAL', 'code' => '0108'],
            ['name' => 'BANCAMIGA', 'code' => '0172'],
            ['name' => 'BANPLUS', 'code' => '0175'],
            ['name' => 'BNC (BANCO NACIONAL DE CREDITO)', 'code' => '0191'],
            ['name' => 'BANCO DEL TESORO', 'code' => '0163'],
            ['name' => 'BANCO EXTERIOR', 'code' => '0115'],
            ['name' => 'Bancaribe', 'code' => '0114'],
        ];

        foreach ($banks as $bank) {
            \App\Models\Bank::updateOrCreate(['code' => $bank['code']], $bank);
        }
    }
}
