<?php

namespace Database\Seeders;

use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Domain\SupplierAudits\Models\AuditCriterion;
use Illuminate\Database\Seeder;

class AuditCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Quality Management',
                'description' => 'Quality systems, certifications, and quality control processes',
                'weight' => 25.00,
                'sort_order' => 1,
                'criteria' => [
                    ['name' => 'ISO 9001 or equivalent certification', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Incoming material inspection process', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'In-process quality control', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Final inspection procedures', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Defect tracking and corrective actions', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Testing equipment calibration', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 6],
                ],
            ],
            [
                'name' => 'Production Capability',
                'description' => 'Manufacturing capacity, equipment, and technical capabilities',
                'weight' => 20.00,
                'sort_order' => 2,
                'criteria' => [
                    ['name' => 'Production capacity meets requirements', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Equipment condition and maintenance', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Technical staff competency', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Production planning and scheduling', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Sample development capability', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Compliance & Documentation',
                'description' => 'Legal compliance, business licenses, and documentation',
                'weight' => 15.00,
                'sort_order' => 3,
                'criteria' => [
                    ['name' => 'Valid business license', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 1],
                    ['name' => 'Export license (if applicable)', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Product certifications (CE, FDA, etc.)', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Traceability documentation', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Contract and NDA compliance', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Facility & Safety',
                'description' => 'Factory conditions, cleanliness, and worker safety',
                'weight' => 15.00,
                'sort_order' => 4,
                'criteria' => [
                    ['name' => 'Factory cleanliness and organization (5S)', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Worker safety equipment and practices', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Fire safety and emergency exits', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 3],
                    ['name' => 'Hazardous material handling', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Storage conditions for raw materials', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Supply Chain & Logistics',
                'description' => 'Delivery reliability, packaging, and supply chain management',
                'weight' => 15.00,
                'sort_order' => 5,
                'criteria' => [
                    ['name' => 'On-time delivery track record', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Packaging quality and standards', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Warehouse management', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Sub-supplier management', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Shipping documentation accuracy', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Social & Environmental',
                'description' => 'Labor practices, environmental compliance, and social responsibility',
                'weight' => 10.00,
                'sort_order' => 6,
                'criteria' => [
                    ['name' => 'No child labor or forced labor', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 1],
                    ['name' => 'Working hours compliance', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Fair wages and benefits', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Environmental waste management', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Environmental certifications (ISO 14001)', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 5],
                ],
            ],
        ];

        foreach ($categories as $categoryData) {
            $criteria = $categoryData['criteria'];
            unset($categoryData['criteria']);

            $category = AuditCategory::updateOrCreate(
                ['name' => $categoryData['name']],
                $categoryData
            );

            foreach ($criteria as $criterionData) {
                AuditCriterion::updateOrCreate(
                    [
                        'audit_category_id' => $category->id,
                        'name' => $criterionData['name'],
                    ],
                    $criterionData
                );
            }
        }
    }
}
