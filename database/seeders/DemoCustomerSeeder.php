<?php

namespace Database\Seeders;

use App\Enums\LoanApplicationStatus;
use App\Models\Customer;
use App\Models\Language;
use Illuminate\Database\Seeder;

class DemoCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $ama = Customer::query()->firstOrCreate(
            ['phone_number' => '+233244000001'],
            ['full_name' => 'Ama Mensah', 'preferred_language_id' => Language::findByCode('twi')?->id, 'balance_pesewas' => 125_050],
        );

        $ama->loanApplications()->firstOrCreate(
            ['reference' => 'LN1001'],
            [
                'amount_pesewas' => 200_000,
                'term_months' => 6,
                'status' => LoanApplicationStatus::Disbursed,
                'next_repayment_pesewas' => 36_500,
                'next_repayment_due_on' => now()->addDays(10)->toDateString(),
            ],
        );

        Customer::query()->firstOrCreate(
            ['phone_number' => '+233244000002'],
            ['full_name' => 'Kwame Owusu', 'preferred_language_id' => null, 'balance_pesewas' => 4_500],
        );

        Customer::query()->firstOrCreate(
            ['phone_number' => '+233244000003'],
            ['full_name' => 'Selorm Agbeko', 'preferred_language_id' => Language::findByCode('ewe')?->id, 'balance_pesewas' => 78_000],
        );

        Customer::query()->firstOrCreate(
            ['phone_number' => '+233244000004'],
            ['full_name' => 'Test Caller', 'preferred_language_id' => Language::findByCode('eng')?->id, 'balance_pesewas' => 50_000],
        );
    }
}
