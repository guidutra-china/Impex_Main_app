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
                'name' => 'Business Credentials & Legal Compliance',
                'description' => 'Legal documentation, business licenses, export registrations, and relevant certifications',
                'weight' => 10.00,
                'sort_order' => 1,
                'criteria' => [
                    ['name' => 'Valid business/operating license', 'description' => 'Verify current and valid business license issued by local authorities', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 1],
                    ['name' => 'Export registration/license', 'description' => 'Confirm the factory has valid export registration and is authorized to export', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 2],
                    ['name' => 'Relevant certifications (ISO, CE, FDA, etc.)', 'description' => 'Evaluate the scope and validity of product and system certifications', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Tax registration and fiscal compliance', 'description' => 'Verify VAT/tax registration and regular fiscal status', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Intellectual property awareness', 'description' => 'Evaluate understanding and respect for IP, patents, and trademarks', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Production Capacity & Equipment',
                'description' => 'Manufacturing capability, equipment condition, automation level, and maintenance practices',
                'weight' => 20.00,
                'sort_order' => 2,
                'criteria' => [
                    ['name' => 'Production capacity meets demand requirements', 'description' => 'Evaluate if current production lines can handle expected order volumes', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Equipment condition and maintenance', 'description' => 'Inspect machines for wear, functionality, and overall condition', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Level of automation', 'description' => 'Assess automation vs manual processes in production lines', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Backup equipment availability', 'description' => 'Check if backup machines exist to handle breakdowns without production stoppage', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Preventive maintenance program', 'description' => 'Verify documented preventive maintenance schedule and execution records', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Technical staff competency', 'description' => 'Evaluate skill level and training of production operators and engineers', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 6],
                    ['name' => 'Sample/prototype development capability', 'description' => 'Assess ability to develop samples and prototypes for new products', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 7],
                ],
            ],
            [
                'name' => 'Quality Management System',
                'description' => 'Quality control procedures, inspection processes, testing equipment, and traceability systems',
                'weight' => 20.00,
                'sort_order' => 3,
                'criteria' => [
                    ['name' => 'Documented QC system', 'description' => 'Verify existence of written quality control procedures and work instructions', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 1],
                    ['name' => 'Incoming raw material inspection', 'description' => 'Evaluate procedures for inspecting and accepting raw materials', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'In-line quality control (in-process)', 'description' => 'Assess quality checks performed during production process', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Final inspection before shipment', 'description' => 'Evaluate final QC procedures and acceptance criteria (AQL)', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Testing/measurement equipment calibrated', 'description' => 'Verify calibration records and schedules for all testing instruments', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Batch/lot traceability', 'description' => 'Assess ability to trace products back to raw material batches and production runs', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 6],
                    ['name' => 'Defect tracking and corrective actions', 'description' => 'Evaluate how defects are recorded, analyzed, and corrected', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 7],
                    ['name' => 'Dedicated QC team/department', 'description' => 'Verify existence of independent QC personnel or department', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 8],
                ],
            ],
            [
                'name' => 'Raw Materials & Supply Chain',
                'description' => 'Raw material quality, supplier management, storage conditions, and inventory control',
                'weight' => 10.00,
                'sort_order' => 4,
                'criteria' => [
                    ['name' => 'Raw material quality', 'description' => 'Evaluate the quality standards of raw materials used in production', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Supplier management for raw materials', 'description' => 'Assess how sub-suppliers are selected, evaluated, and monitored', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Raw material storage conditions', 'description' => 'Inspect storage areas for proper conditions (temperature, humidity, organization)', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Inventory control system', 'description' => 'Evaluate FIFO practices, stock management, and inventory accuracy', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                ],
            ],
            [
                'name' => 'Warehouse & Packaging',
                'description' => 'Finished goods storage, packaging quality, and product protection practices',
                'weight' => 10.00,
                'sort_order' => 5,
                'criteria' => [
                    ['name' => 'Finished goods warehouse conditions', 'description' => 'Inspect warehouse for cleanliness, organization, and proper stacking', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Temperature/humidity control', 'description' => 'Verify adequate environmental controls for product preservation', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Packaging quality and product protection', 'description' => 'Evaluate packaging materials, methods, and drop/transit protection', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Separation between RM, WIP, and FG', 'description' => 'Verify clear separation and labeling of raw materials, work-in-progress, and finished goods', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Shipping marks and labeling accuracy', 'description' => 'Assess correctness of carton marks, barcodes, and shipping labels', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Workplace Safety & Environment',
                'description' => 'Worker safety, fire protection, emergency procedures, and environmental compliance',
                'weight' => 10.00,
                'sort_order' => 6,
                'criteria' => [
                    ['name' => 'Safety equipment available and in use', 'description' => 'Verify PPE availability (gloves, goggles, masks) and that workers actually use them', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 1],
                    ['name' => 'Emergency exits clearly marked and accessible', 'description' => 'Check that all exits are signed, unobstructed, and meet fire codes', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Fire extinguishers and fire safety systems', 'description' => 'Verify presence, inspection dates, and accessibility of fire extinguishers and alarms', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Waste management and disposal', 'description' => 'Evaluate handling and disposal of production waste and hazardous materials', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Ventilation and lighting adequacy', 'description' => 'Assess air circulation, ventilation systems, and lighting in production areas', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'First aid facilities', 'description' => 'Check availability of first aid kits, trained personnel, and medical support', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 6],
                ],
            ],
            [
                'name' => 'Social Compliance & Labor',
                'description' => 'Labor practices, working conditions, fair wages, and human rights compliance',
                'weight' => 10.00,
                'sort_order' => 7,
                'criteria' => [
                    ['name' => 'No child labor or forced labor', 'description' => 'Verify all workers are of legal age and employed voluntarily — zero tolerance', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 1],
                    ['name' => 'Formal employment contracts', 'description' => 'Verify workers have signed contracts with clear terms', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Working hours within legal limits', 'description' => 'Check daily/weekly hours, overtime records, and rest day compliance', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Fair wages and benefits', 'description' => 'Verify wages meet or exceed local minimum wage and benefits are provided', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Dormitory conditions (if applicable)', 'description' => 'Inspect living quarters for cleanliness, space, safety, and sanitation', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Freedom of association', 'description' => 'Verify workers can freely organize and voice concerns without retaliation', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 6],
                ],
            ],
            [
                'name' => 'Export & Logistics Experience',
                'description' => 'Export capability, delivery reliability, communication, and documentation experience',
                'weight' => 10.00,
                'sort_order' => 8,
                'criteria' => [
                    ['name' => 'Experience exporting to target market', 'description' => 'Evaluate track record and familiarity with destination country requirements', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Delivery lead time reliability', 'description' => 'Assess historical on-time delivery performance and production planning', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Flexibility for custom/small orders', 'description' => 'Evaluate willingness and ability to handle customized or smaller quantities', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Communication and response time', 'description' => 'Assess responsiveness, English proficiency, and communication quality', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Export documentation capability', 'description' => 'Verify ability to produce correct invoices, packing lists, certificates of origin, etc.', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
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
