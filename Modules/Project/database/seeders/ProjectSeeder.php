<?php

namespace Modules\Project\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Project\Actions\Project\SeedAdministrativeFeeRateAction;
use Modules\Project\Enums\FundType;
use Modules\Project\Enums\OperationalDeductionType;
use Modules\Project\Enums\ProjectStatus;
use Modules\Project\Models\Category;
use Modules\Project\Models\Project;
use Modules\Settings\Services\SettingService;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $adminFee = 12.0;

        try {
            $resolved = app(SettingService::class)->get('admin_fee_percentage', 12);
            if (is_numeric($resolved)) {
                $adminFee = (float) $resolved;
            }
        } catch (\Throwable) {
            // Settings may not be ready; keep default.
        }

        $water = Category::firstOrCreate(['name' => 'مشاريع مائية']);
        $health = Category::firstOrCreate(['name' => 'مشاريع صحية']);
        $education = Category::firstOrCreate(['name' => 'مشاريع تعليمية']);
        $relief = Category::firstOrCreate(['name' => 'مشاريع إغاثية']);
        $zakat = Category::firstOrCreate(['name' => 'الزكاة']);

        $projects = [
            [
                'name' => 'مشروع مياه نسبية',
                'category_id' => $water->id,
                'fund_type' => FundType::Variable,
                'status' => ProjectStatus::Active,
                'operational_deduction_type' => OperationalDeductionType::Relative,
                'operational_fixed_amount' => null,
                'administrative_exempt' => false,
            ],
            [
                'name' => 'مشروع صحي ثابت',
                'category_id' => $health->id,
                'fund_type' => FundType::Fixed,
                'status' => ProjectStatus::Active,
                'operational_deduction_type' => OperationalDeductionType::Fixed,
                'operational_fixed_amount' => 154,
                'administrative_exempt' => false,
            ],
            [
                'name' => 'مشروع تعليمي معفى',
                'category_id' => $education->id,
                'fund_type' => FundType::Variable,
                'status' => ProjectStatus::Active,
                'operational_deduction_type' => OperationalDeductionType::Exempt,
                'operational_fixed_amount' => null,
                'administrative_exempt' => true,
            ],
            [
                'name' => 'مشروع إغاثة موقوف',
                'category_id' => $relief->id,
                'fund_type' => FundType::Variable,
                'status' => ProjectStatus::Stopped,
                'operational_deduction_type' => OperationalDeductionType::Relative,
                'operational_fixed_amount' => null,
                'administrative_exempt' => false,
            ],
            [
                'name' => 'مشروع زكاة مؤرشف',
                'category_id' => $zakat->id,
                'fund_type' => FundType::Fixed,
                'status' => ProjectStatus::Archived,
                'operational_deduction_type' => OperationalDeductionType::Fixed,
                'operational_fixed_amount' => 200,
                'administrative_exempt' => false,
                'archived_at' => now(),
            ],
            [
                'name' => 'مشروع نسبي معفى إداريا',
                'category_id' => $relief->id,
                'fund_type' => FundType::Variable,
                'status' => ProjectStatus::Active,
                'operational_deduction_type' => OperationalDeductionType::Relative,
                'operational_fixed_amount' => null,
                'administrative_exempt' => true,
            ],
        ];

        foreach ($projects as $project) {
            $model = Project::updateOrCreate(
                ['name' => $project['name']],
                [
                    ...$project,
                    'administrative_fee_percentage' => $adminFee,
                ]
            );

            app(SeedAdministrativeFeeRateAction::class)->execute($model, $adminFee, $model->created_at);
        }
    }
}
