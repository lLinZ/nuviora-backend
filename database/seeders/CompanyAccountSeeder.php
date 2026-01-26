<?php

namespace Database\Seeders;

use App\Models\CompanyAccount;
use Illuminate\Database\Seeder;

class CompanyAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Zelle',
                'icon' => 'AccountBalanceRounded',
                'details' => [
                    ['label' => 'Correo', 'value' => 'ventas@nuviora.com'],
                    ['label' => 'Titular', 'value' => 'Nuviora C.A.'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Binance',
                'icon' => 'AccountBalanceWalletRounded',
                'details' => [
                    ['label' => 'Correo / ID', 'value' => 'pago.empresa@gmail.com'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Pago Móvil / Transferencia BS',
                'icon' => 'PaymentRounded',
                'details' => [
                    ['label' => 'Banco', 'value' => 'Banco Mercantil'],
                    ['label' => 'Cédula / RIF', 'value' => 'J-12345678-9'],
                    ['label' => 'Teléfono', 'value' => '04121234567'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Zinli',
                'icon' => 'AccountBalanceWalletRounded',
                'details' => [
                    ['label' => 'Correo', 'value' => 'zinli@nuviora.com'],
                ],
                'is_active' => true,
            ],
            [
                'name' => 'PayPal',
                'icon' => 'AccountBalanceWalletRounded',
                'details' => [
                    ['label' => 'Correo', 'value' => 'paypal@nuviora.com'],
                ],
                'is_active' => true,
            ],
        ];

        foreach ($accounts as $account) {
            CompanyAccount::create($account);
        }
    }
}
