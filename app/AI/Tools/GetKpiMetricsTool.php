<?php

namespace App\AI\Tools;

use App\Models\Certificate;
use App\Models\Payment;
use App\Models\Registration;

class GetKpiMetricsTool implements AiToolInterface
{
    public function name(): string
    {
        return 'get_kpi_metrics';
    }

    public function description(): string
    {
        return 'Get high-level KPI metrics: total registrations, revenue, certificates issued, completion rates. Admin/manager only.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period' => [
                    'type'        => 'string',
                    'description' => 'Time period: today, week, month, year',
                    'enum'        => ['today', 'week', 'month', 'year'],
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args, ?int $userId = null): mixed
    {
        if ($userId && ! $this->callerHasAccess($userId)) {
            return ['error' => 'KPI metrics require admin or manager access.'];
        }

        $period = $args['period'] ?? 'month';
        $from   = match ($period) {
            'today' => now()->startOfDay(),
            'week'  => now()->startOfWeek(),
            'year'  => now()->startOfYear(),
            default => now()->startOfMonth(),
        };

        return [
            'period'              => $period,
            'from'                => $from->toDateString(),
            'registrations'       => Registration::query()->where('created_at', '>=', $from)->count(),
            'active_registrations'=> Registration::query()->where('status', 'Registered')->count(),
            'revenue'             => Payment::query()->where('status', 'Payment Confirmed')->where('created_at', '>=', $from)->sum('amount'),
            'certificates_issued' => Certificate::query()->where('status', 'issued')->where('issued_at', '>=', $from)->count(),
        ];
    }

    public function isAllowedForRole(string $role): bool
    {
        return in_array($role, ['admin', 'super_admin', 'finance_manager', 'department_manager', 'quality_manager'], true);
    }

    private function callerHasAccess(int $userId): bool
    {
        $user = \App\Models\User::query()->find($userId);

        return $user && $this->isAllowedForRole($user->role);
    }
}
