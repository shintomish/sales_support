<?php

namespace App\Services;

use App\Models\SesContract;
use App\Models\WorkRecord;

/**
 * 請求金額試算サービス（Phase B: 月次集計ビュー用）
 *
 * 計算式:
 *   基本額   = ses_contracts.income_amount
 *   控除     = max(0, client_deduction_hours - actual_hours) × client_deduction_unit_price
 *   超過     = max(0, actual_hours - client_overtime_hours) × client_overtime_unit_price
 *   小計     = 基本額 - 控除 + 超過 + transportation_fee
 *   消費税   = 小計 × 10%（Phase B 一律 10%。軽減税率対応は Phase C）
 *   請求合計 = 小計 + 消費税
 *
 * actual_hours が null の場合は控除/超過の判定を行わない（試算不能扱い）。
 * その他の精算条件・実績は null を 0 として扱う。
 */
class BillingCalculationService
{
    public const TAX_RATE = 0.10;

    /**
     * @return array{
     *   basic: float,
     *   deduction: float,
     *   deduction_hours: float,
     *   deduction_unit: float,
     *   overtime: float,
     *   overtime_hours: float,
     *   overtime_unit: float,
     *   transportation: float,
     *   subtotal: float,
     *   tax: float,
     *   total: float,
     *   actual_hours: float|null,
     *   tax_rate: float,
     * }
     */
    public function calculate(?SesContract $contract, ?WorkRecord $record): array
    {
        $basic = (float) ($contract?->income_amount ?? 0);
        $transportation = (float) ($record?->transportation_fee ?? 0);

        $actualHoursRaw = $record?->actual_hours;
        $actualHours = $actualHoursRaw !== null ? (float) $actualHoursRaw : null;

        $deductionHours = (float) ($contract?->client_deduction_hours ?? 0);
        $deductionUnit  = (float) ($contract?->client_deduction_unit_price ?? 0);
        $overtimeHours  = (float) ($contract?->client_overtime_hours ?? 0);
        $overtimeUnit   = (float) ($contract?->client_overtime_unit_price ?? 0);

        $deductionGap = 0.0;
        $overtimeGap  = 0.0;
        if ($actualHours !== null) {
            if ($deductionHours > 0 && $actualHours < $deductionHours) {
                $deductionGap = $deductionHours - $actualHours;
            }
            if ($overtimeHours > 0 && $actualHours > $overtimeHours) {
                $overtimeGap = $actualHours - $overtimeHours;
            }
        }
        $deduction = $deductionGap * $deductionUnit;
        $overtime  = $overtimeGap  * $overtimeUnit;

        $subtotal = $basic - $deduction + $overtime + $transportation;
        $tax      = $subtotal * self::TAX_RATE;
        $total    = $subtotal + $tax;

        return [
            'basic'           => round($basic, 2),
            'deduction'       => round($deduction, 2),
            'deduction_hours' => round($deductionGap, 2),
            'deduction_unit'  => round($deductionUnit, 2),
            'overtime'        => round($overtime, 2),
            'overtime_hours'  => round($overtimeGap, 2),
            'overtime_unit'   => round($overtimeUnit, 2),
            'transportation'  => round($transportation, 2),
            'subtotal'        => round($subtotal, 2),
            'tax'             => round($tax, 2),
            'total'           => round($total, 2),
            'actual_hours'    => $actualHours !== null ? round($actualHours, 2) : null,
            'tax_rate'        => self::TAX_RATE,
        ];
    }
}
