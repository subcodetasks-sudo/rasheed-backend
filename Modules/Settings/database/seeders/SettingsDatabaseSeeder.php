<?php

namespace Modules\Settings\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Settings\app\Models\Setting;

class SettingsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // --- General Info & Branding ---
            ['key' => 'app_name', 'value' => 'My Project', 'type' => 'string'],
            ['key' => 'site_logo', 'value' => null, 'type' => 'string'],
            ['key' => 'site_favicon', 'value' => null, 'type' => 'string'],

            // --- Contact Information ---
            ['key' => 'contact_phone', 'value' => '+966500000000', 'type' => 'string'],
            ['key' => 'contact_whatsapp', 'value' => '966500000000', 'type' => 'string'],
            ['key' => 'contact_email', 'value' => 'info@example.com', 'type' => 'string'],
            ['key' => 'contact_address_ar', 'value' => 'المملكة العربية السعودية، الرياض', 'type' => 'string'],
            ['key' => 'contact_address_en', 'value' => 'Riyadh, Saudi Arabia', 'type' => 'string'],
            ['key' => 'contact_map_location', 'value' => 'https://goo.gl/maps/example', 'type' => 'string'],

            // --- Social Media Links ---
            ['key' => 'social_facebook', 'value' => 'https://facebook.com/', 'type' => 'string'],
            ['key' => 'social_instagram', 'value' => 'https://instagram.com/', 'type' => 'string'],
            ['key' => 'social_twitter', 'value' => 'https://twitter.com/', 'type' => 'string'],
            ['key' => 'social_linkedin', 'value' => 'https://linkedin.com/', 'type' => 'string'],
            ['key' => 'social_snapchat', 'value' => 'https://snapchat.com/', 'type' => 'string'],
            ['key' => 'social_tiktok', 'value' => 'https://tiktok.com/', 'type' => 'string'],

            // --- SEO & Meta Tags ---
            ['key' => 'meta_title_ar', 'value' => 'اسم الموقع الافتراضي', 'type' => 'string'],
            ['key' => 'meta_title_en', 'value' => 'Default Site Title', 'type' => 'string'],
            ['key' => 'meta_description_ar', 'value' => 'وصف الموقع لمحركات البحث بالعربي', 'type' => 'string'],
            ['key' => 'meta_description_en', 'value' => 'Site description for SEO in English', 'type' => 'string'],
            ['key' => 'meta_keywords', 'value' => 'key1, key2, key3', 'type' => 'string'],

            // --- Technical & API Settings ---
            ['key' => 'maintenance_mode', 'value' => 'false', 'type' => 'boolean'],
            ['key' => 'default_currency', 'value' => 'SAR', 'type' => 'string'],
            ['key' => 'default_language', 'value' => 'ar', 'type' => 'string'],
            ['key' => 'google_analytics_id', 'value' => 'UA-00000000-1', 'type' => 'string'],
            ['key' => 'fcm_server_key', 'value' => null, 'type' => 'string'],
            ['key' => 'supported_currencies', 'value' => json_encode(['SAR', 'USD', 'AED']), 'type' => 'json'],
            ['key' => 'admin_fee_percentage', 'value' => '12', 'type' => 'decimal'],
            ['key' => 'total_operational_deduction', 'value' => '1081', 'type' => 'decimal'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'type' => $setting['type'],
                    'is_public' => true,
                ]
            );
        }
    }
}
