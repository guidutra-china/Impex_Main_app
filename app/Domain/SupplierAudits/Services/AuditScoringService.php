<?php

namespace App\Domain\SupplierAudits\Services;

use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Domain\SupplierAudits\Models\SupplierAudit;

class AuditScoringService
{
    public function calculate(SupplierAudit $audit): array
    {
        $audit->loadMissing(['responses.criterion.category']);

        $categories = AuditCategory::active()
            ->ordered()
            ->with('activeCriteria')
            ->get();

        $categoryScores = [];
        $totalWeightedScore = 0;
        $totalWeight = 0;
        $hasFailedCritical = false;

        foreach ($categories as $category) {
            $criteria = $category->activeCriteria;

            if ($criteria->isEmpty()) {
                continue;
            }

            $scoredValues = [];
            $allPassed = true;
            $applicableCriteria = 0;

            foreach ($criteria as $criterion) {
                $response = $audit->responses
                    ->firstWhere('audit_criterion_id', $criterion->id);

                if (! $response) {
                    continue;
                }

                // Skip N/A responses — they don't count in scoring
                if ($response->is_not_applicable) {
                    continue;
                }

                $applicableCriteria++;

                if ($criterion->type->value === 'scored' && $response->score !== null) {
                    $scoredValues[] = $response->score;
                }

                if ($criterion->type->value === 'pass_fail' && $response->passed === false) {
                    $allPassed = false;
                    if ($criterion->is_critical) {
                        $hasFailedCritical = true;
                    }
                }
            }

            $categoryAverage = count($scoredValues) > 0
                ? array_sum($scoredValues) / count($scoredValues)
                : null;

            $responsesForCategory = $audit->responses
                ->whereIn('audit_criterion_id', $criteria->pluck('id'));

            $categoryScores[$category->id] = [
                'name' => $category->name,
                'weight' => $category->weight,
                'average' => $categoryAverage,
                'all_passed' => $allPassed,
                'criteria_count' => $criteria->count(),
                'applicable_count' => $applicableCriteria,
                'na_count' => $responsesForCategory->where('is_not_applicable', true)->count(),
                'answered_count' => $responsesForCategory
                    ->filter(fn ($r) => $r->is_not_applicable || $r->score !== null || $r->passed !== null)
                    ->count(),
            ];

            if ($categoryAverage !== null) {
                $totalWeightedScore += $categoryAverage * (float) $category->weight;
                $totalWeight += (float) $category->weight;
            }
        }

        $finalScore = $totalWeight > 0
            ? round($totalWeightedScore / $totalWeight, 2)
            : null;

        $result = $this->determineResult($finalScore, $hasFailedCritical);

        return [
            'total_score' => $finalScore,
            'result' => $result,
            'category_scores' => $categoryScores,
            'has_failed_critical' => $hasFailedCritical,
        ];
    }

    public function determineResult(?float $score, bool $hasFailedCritical): ?AuditResult
    {
        if ($hasFailedCritical) {
            return AuditResult::REJECTED;
        }

        if ($score === null) {
            return null;
        }

        if ($score >= 4.0) {
            return AuditResult::APPROVED;
        }

        if ($score >= 3.0) {
            return AuditResult::CONDITIONAL;
        }

        return AuditResult::REJECTED;
    }

    public function suggestNextAuditDate(AuditResult $result): \Carbon\Carbon
    {
        return match ($result) {
            AuditResult::APPROVED => now()->addMonths(12),
            AuditResult::CONDITIONAL => now()->addMonths(6),
            AuditResult::REJECTED => now()->addMonths(3),
        };
    }
}
