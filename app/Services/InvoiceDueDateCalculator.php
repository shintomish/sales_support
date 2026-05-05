<?php

namespace App\Services;

use Carbon\Carbon;
use Yasumi\Yasumi;

/**
 * 請求書 支払期限の算出
 *
 * ロジック:
 *   翌月末日 + (payment_site - 30) 日
 *   結果が土日祝の場合は翌営業日にスライドする。
 *
 * 検算（4月末締め）:
 *   payment_site=30 → 5/31(日)  → 6/1(月)
 *   payment_site=50 → 5/31+20=6/20(土)→ 6/22(月)
 *   payment_site=60 → 5/31+30=6/30(火)→ 6/30
 *
 * payment_site が null の場合は 30 日（=翌月末）扱い。
 */
class InvoiceDueDateCalculator
{
    public function calculate(string $yearMonth, ?int $paymentSite): string
    {
        [$y, $m] = explode('-', $yearMonth);
        $base = Carbon::create((int) $y, (int) $m, 1)
            ->endOfMonth()
            ->addMonthNoOverflow()
            ->endOfMonth()
            ->startOfDay();

        $offset = (int) ($paymentSite ?? 30) - 30;
        if ($offset !== 0) {
            $base->addDays($offset);
        }

        return $this->shiftToNextBusinessDay($base)->toDateString();
    }

    private function shiftToNextBusinessDay(Carbon $date): Carbon
    {
        $cursor = $date->copy();
        while ($this->isWeekend($cursor) || $this->isJapaneseHoliday($cursor)) {
            $cursor->addDay();
        }
        return $cursor;
    }

    private function isWeekend(Carbon $d): bool
    {
        $dow = (int) $d->format('N');
        return $dow >= 6;
    }

    private function isJapaneseHoliday(Carbon $d): bool
    {
        $year = (int) $d->format('Y');
        $holidays = Yasumi::create('Japan', $year, 'ja_JP');
        return $holidays->isHoliday(new \DateTime($d->toDateString()));
    }
}
