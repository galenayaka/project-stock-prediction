<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a test user and real-world companies.
     */
    public function run(): void
    {
        // ── Test user ──────────────────────────────────────────────
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // ── Real-world companies ───────────────────────────────────
        // Each entry includes the SEC CIK (Central Index Key) so the
        // SecApiService can pull 10-K / 10-Q financial statements
        // automatically via the "Import Financials" button.
        //
        // To refresh companies without losing data, run:
        //   php artisan db:seed --class=DatabaseSeeder
        // Duplicate tickers are skipped (first-wins).

        $companies = [

            // ── Technology ──────────────────────────────────────
            ['ticker' => 'AAPL',  'name' => 'Apple Inc.',                       'sector' => 'Technology',        'industry' => 'Consumer Electronics',       'cik' => '0000320193'],
            ['ticker' => 'MSFT',  'name' => 'Microsoft Corporation',             'sector' => 'Technology',        'industry' => 'Software - Infrastructure',  'cik' => '0000789019'],
            ['ticker' => 'GOOGL', 'name' => 'Alphabet Inc.',                     'sector' => 'Technology',        'industry' => 'Internet Content & Info',     'cik' => '0001652044'],
            ['ticker' => 'META',  'name' => 'Meta Platforms, Inc.',              'sector' => 'Technology',        'industry' => 'Internet Content & Info',     'cik' => '0001326801'],
            ['ticker' => 'NVDA',  'name' => 'NVIDIA Corporation',                'sector' => 'Technology',        'industry' => 'Semiconductors',              'cik' => '0001045810'],
            ['ticker' => 'AMD',   'name' => 'Advanced Micro Devices, Inc.',      'sector' => 'Technology',        'industry' => 'Semiconductors',              'cik' => '0000002488'],
            ['ticker' => 'INTC',  'name' => 'Intel Corporation',                 'sector' => 'Technology',        'industry' => 'Semiconductors',              'cik' => '0000050863'],
            ['ticker' => 'CRM',   'name' => 'Salesforce, Inc.',                  'sector' => 'Technology',        'industry' => 'Software - Application',      'cik' => '0001108524'],
            ['ticker' => 'ADBE',  'name' => 'Adobe Inc.',                        'sector' => 'Technology',        'industry' => 'Software - Infrastructure',  'cik' => '0000796343'],
            ['ticker' => 'CSCO',  'name' => 'Cisco Systems, Inc.',               'sector' => 'Technology',        'industry' => 'Communication Equipment',     'cik' => '0000858877'],

            // ── Consumer Cyclical ───────────────────────────────
            ['ticker' => 'AMZN',  'name' => 'Amazon.com, Inc.',                  'sector' => 'Consumer Cyclical', 'industry' => 'Internet Retail',              'cik' => '0001018724'],
            ['ticker' => 'TSLA',  'name' => 'Tesla, Inc.',                       'sector' => 'Consumer Cyclical', 'industry' => 'Auto Manufacturers',           'cik' => '0001318605'],
            ['ticker' => 'HD',    'name' => 'The Home Depot, Inc.',              'sector' => 'Consumer Cyclical', 'industry' => 'Home Improvement Retail',      'cik' => '0000354950'],
            ['ticker' => 'NKE',   'name' => 'NIKE, Inc.',                        'sector' => 'Consumer Cyclical', 'industry' => 'Footwear & Accessories',       'cik' => '0000320187'],
            ['ticker' => 'SBUX',  'name' => 'Starbucks Corporation',             'sector' => 'Consumer Cyclical', 'industry' => 'Restaurants',                  'cik' => '0000829224'],

            // ── Financials ──────────────────────────────────────
            ['ticker' => 'JPM',   'name' => 'JPMorgan Chase & Co.',              'sector' => 'Financials',        'industry' => 'Banks - Diversified',          'cik' => '0000019617'],
            ['ticker' => 'BAC',   'name' => 'Bank of America Corporation',       'sector' => 'Financials',        'industry' => 'Banks - Diversified',          'cik' => '0000070858'],
            ['ticker' => 'V',     'name' => 'Visa Inc.',                         'sector' => 'Financials',        'industry' => 'Credit Services',              'cik' => '0001403161'],
            ['ticker' => 'MA',    'name' => 'Mastercard Incorporated',           'sector' => 'Financials',        'industry' => 'Credit Services',              'cik' => '0001141391'],

            // ── Healthcare ──────────────────────────────────────
            ['ticker' => 'JNJ',   'name' => 'Johnson & Johnson',                 'sector' => 'Healthcare',        'industry' => 'Drug Manufacturers - General',  'cik' => '0000200406'],
            ['ticker' => 'PFE',   'name' => 'Pfizer Inc.',                       'sector' => 'Healthcare',        'industry' => 'Drug Manufacturers - General',  'cik' => '0000078003'],
            ['ticker' => 'UNH',   'name' => 'UnitedHealth Group Incorporated',   'sector' => 'Healthcare',        'industry' => 'Healthcare Plans',              'cik' => '0000731766'],

            // ── Energy ─────────────────────────────────────────
            ['ticker' => 'XOM',   'name' => 'Exxon Mobil Corporation',           'sector' => 'Energy',            'industry' => 'Oil & Gas Integrated',          'cik' => '0000034088'],
            ['ticker' => 'CVX',   'name' => 'Chevron Corporation',               'sector' => 'Energy',            'industry' => 'Oil & Gas Integrated',          'cik' => '0000093410'],

            // ── Industrials ─────────────────────────────────────
            ['ticker' => 'CAT',   'name' => 'Caterpillar Inc.',                  'sector' => 'Industrials',       'industry' => 'Farm & Heavy Construction',     'cik' => '0000018230'],
            ['ticker' => 'BA',    'name' => 'The Boeing Company',                'sector' => 'Industrials',       'industry' => 'Aerospace & Defense',           'cik' => '0000012927'],
        ];

        foreach ($companies as $data) {
            Company::firstOrCreate(
                ['ticker' => $data['ticker']],
                [
                    'name' => $data['name'],
                    'sector' => $data['sector'],
                    'industry' => $data['industry'],
                    'cik' => $data['cik'],
                ],
            );
        }

        $this->command?->info('Seeded ' . count($companies) . ' companies successfully.');
    }
}

