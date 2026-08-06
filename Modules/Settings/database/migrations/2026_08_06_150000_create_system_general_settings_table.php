<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_general_settings', function (Blueprint $table) {
            $table->id();
            $table->string('organization_name');
            $table->string('currency', 10);
            $table->decimal('admin_fee_percentage', 5, 2)->default(12);
            $table->timestamps();
        });

        $organizationName = $this->settingValue(['organization_name', 'app_name'], 'Rashid Financial');
        $currency = $this->settingValue(['default_currency'], 'SAR');
        $adminFee = $this->settingValue(['admin_fee_percentage'], '12');

        if (Schema::hasColumn('settings', 'admin_fee_percentage')) {
            $columnFee = DB::table('settings')->value('admin_fee_percentage');
            if (is_numeric($columnFee)) {
                $adminFee = (string) $columnFee;
            }
        }

        DB::table('system_general_settings')->insert([
            'organization_name' => $organizationName,
            'currency' => $currency,
            'admin_fee_percentage' => is_numeric($adminFee) ? $adminFee : 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_general_settings');
    }

    /**
     * @param  list<string>  $keys
     */
    private function settingValue(array $keys, string $default): string
    {
        if (! Schema::hasTable('settings')) {
            return $default;
        }

        foreach ($keys as $key) {
            $value = DB::table('settings')->where('key', $key)->value('value');
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return $default;
    }
};
