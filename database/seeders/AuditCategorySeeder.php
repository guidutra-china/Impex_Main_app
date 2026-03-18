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
            // === Mold/Tooling Specific Categories (mark N/A for non-tooling companies) ===
            [
                'name' => 'Engineering & Design Capability (Tooling)',
                'description' => 'CAD/CAM software, mold flow analysis, DFM capability, and engineering change management — specific to mold/tooling companies',
                'weight' => 15.00,
                'sort_order' => 9,
                'criteria' => [
                    ['name' => 'CAD/CAM software (NX, CATIA, PowerMill, etc.)', 'description' => 'Evaluate available design software, licenses, and operator proficiency', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Mold flow analysis capability (Moldflow/CAE)', 'description' => 'Assess ability to simulate and optimize injection flow, cooling, and warpage', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Reverse engineering / 3D scanning', 'description' => 'Check equipment and capability for 3D scanning and reverse engineering', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Dedicated mold design team', 'description' => 'Verify existence and experience of dedicated mold design engineers', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'DFM (Design for Manufacturing) capability', 'description' => 'Evaluate ability to provide DFM feedback and optimize part design for moldability', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Engineering Change Notice (ECN) management', 'description' => 'Verify documented process for managing design changes and version control', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 6],
                ],
            ],
            [
                'name' => 'Machining & Manufacturing (Tooling)',
                'description' => 'CNC machining, EDM, grinding, and mold manufacturing capabilities — specific to mold/tooling companies',
                'weight' => 20.00,
                'sort_order' => 10,
                'criteria' => [
                    ['name' => 'CNC machining centers (3/5 axis)', 'description' => 'Evaluate quantity, capacity, precision level, and brand of CNC machines', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'EDM wire-cut and sinker', 'description' => 'Assess EDM equipment availability, precision, and condition', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Precision grinding (CNC/conventional)', 'description' => 'Evaluate surface and cylindrical grinding capabilities', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Deep hole drilling capability', 'description' => 'Check gun drilling / deep hole drilling for cooling channels', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Large mold capacity (max tonnage)', 'description' => 'Evaluate maximum mold size and weight the facility can handle', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Texturing and polishing capability', 'description' => 'Assess surface finishing capabilities (mirror polish, texture, VDI)', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 6],
                    ['name' => 'Hot runner system experience', 'description' => 'Evaluate knowledge and experience with hot runner brands and configurations', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 7],
                ],
            ],
            [
                'name' => 'Quality & Metrology (Tooling)',
                'description' => 'APQP/PPAP process, CMM measurement, dimensional inspection — specific to mold/tooling companies',
                'weight' => 20.00,
                'sort_order' => 11,
                'criteria' => [
                    ['name' => 'APQP/PPAP process implemented', 'description' => 'Verify documented APQP process and ability to submit PPAP packages', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 1],
                    ['name' => 'CMM (coordinate measuring machine)', 'description' => 'Verify CMM availability, brand, capacity, and calibration status', 'type' => 'pass_fail', 'is_critical' => true, 'sort_order' => 2],
                    ['name' => 'IATF 16949 or equivalent automotive QMS', 'description' => 'Check automotive quality management system certification scope and validity', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'In-process dimensional control during machining', 'description' => 'Evaluate on-machine probing and in-process measurement practices', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Final dimensional report with GD&T', 'description' => 'Assess ability to produce full dimensional reports with GD&T compliance', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Steel material traceability and certificates', 'description' => 'Verify that all steel has mill certificates and is traceable to heat/lot', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 6],
                    ['name' => 'Process FMEA implemented', 'description' => 'Check for documented failure mode and effects analysis for critical operations', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 7],
                    ['name' => 'Heat treatment control (hardness verification)', 'description' => 'Evaluate heat treatment process and hardness testing records', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 8],
                ],
            ],
            [
                'name' => 'Assembly, Try-out & After-Sales (Tooling)',
                'description' => 'Mold assembly, injection try-out, delivery documentation, and post-delivery support — specific to mold/tooling companies',
                'weight' => 15.00,
                'sort_order' => 12,
                'criteria' => [
                    ['name' => 'Dedicated mold assembly area', 'description' => 'Inspect assembly area for cleanliness, tooling, and adequate space', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 1],
                    ['name' => 'Injection molding machines for try-out', 'description' => 'Evaluate available tonnage range and condition of try-out presses', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 2],
                    ['name' => 'Post try-out adjustment capability', 'description' => 'Assess ability to correct issues identified during try-out efficiently', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 3],
                    ['name' => 'Mold manual and delivery documentation', 'description' => 'Verify delivery includes mold manual, dimensional report, steel certificates, and maintenance guide', 'type' => 'pass_fail', 'is_critical' => false, 'sort_order' => 4],
                    ['name' => 'Packaging and transport protection', 'description' => 'Evaluate mold packaging, rust protection, and transport safety practices', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 5],
                    ['name' => 'Post-delivery technical support', 'description' => 'Assess responsiveness and capability for remote and on-site support after delivery', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 6],
                    ['name' => 'Spare parts availability', 'description' => 'Check if critical spare parts (inserts, slides, ejector pins) are provided or available', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 7],
                    ['name' => 'Warranty terms (cycles/time)', 'description' => 'Evaluate warranty conditions offered on mold lifecycle and shot count', 'type' => 'scored', 'is_critical' => false, 'sort_order' => 8],
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
