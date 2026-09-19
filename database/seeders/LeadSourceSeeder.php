<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            // One source for the whole Meta family: every Facebook / Instagram
            // campaign, lead ad and click-to-WhatsApp ad lands here.
            ['name' => 'Meta',               'channel' => 'meta',       'is_paid' => true],
            ['name' => 'Google Ads',         'channel' => 'google',     'is_paid' => true],
            ['name' => 'Google Organic',     'channel' => 'google',     'is_paid' => false],
            ['name' => 'Website',            'channel' => 'website',    'is_paid' => false],
            ['name' => 'Landing Page',       'channel' => 'website',    'is_paid' => false],
            ['name' => 'WhatsApp',           'channel' => 'whatsapp',   'is_paid' => false],
            ['name' => 'Practo',             'channel' => 'practo',     'is_paid' => true],
            ['name' => 'JustDial',           'channel' => 'justdial',   'is_paid' => true],
            ['name' => 'IndiaMart',          'channel' => 'indiamart',  'is_paid' => true],
            ['name' => 'Referral',           'channel' => 'referral',   'is_paid' => false],
            ['name' => 'Doctor Referral',    'channel' => 'referral',   'is_paid' => false],
            ['name' => 'Walk-In',            'channel' => 'walkin',     'is_paid' => false],
            ['name' => 'Camp Lead',          'channel' => 'camp',       'is_paid' => false],
            ['name' => 'Event Lead',         'channel' => 'event',      'is_paid' => false],
            ['name' => 'CSV Upload',         'channel' => 'csv',        'is_paid' => false],
            ['name' => 'API Integration',    'channel' => 'api',        'is_paid' => false],
            ['name' => 'Other',              'channel' => 'other',      'is_paid' => false],
        ];

        foreach ($sources as $source) {
            LeadSource::firstOrCreate(
                ['slug' => Str::slug($source['name'])],
                array_merge($source, ['is_active' => true])
            );
        }
    }
}
