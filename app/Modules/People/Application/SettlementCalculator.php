<?php

namespace App\Modules\People\Application;

use Carbon\CarbonInterface;

/**
 * Final settlement ("solde de tout compte") — Cameroon Labour Code basics
 * (Law 92/007 of 14 Aug 1992, art. 34–37, 89–92) with simplified rules.
 *
 * NOT VALIDATED: every result carries requires_review = true and must be
 * checked by a payroll expert against the applicable collective agreement
 * (convention collective) before payment.
 *
 * Rules applied:
 * - Notice (préavis): end of a fixed-term contract and gross misconduct give no
 *   notice. Otherwise by seniority — non-executive: <1 y 15 days, 1–5 y 1 month,
 *   >5 y 2 months; executive (cadre): <1 y 1 month, 1–5 y 2 months, >5 y 3 months.
 *   If the employer terminates and the notice is not worked, an indemnity in lieu
 *   of notice is due; if the employee resigns without working it, the same amount
 *   is shown as owed by the employee (deducted).
 * - Paid leave balance: compensatory indemnity = remaining days × monthly / 26.
 * - Severance (indemnité de licenciement): employer termination, ≥ 2 years of
 *   seniority, not gross misconduct: monthly reference salary × (20 % per year for
 *   years 1–5, 25 % years 6–10, 30 % years 11–15, 35 % beyond).
 * - Salary for the days worked in the final month: monthly / 30 × days.
 */
class SettlementCalculator
{
    public const WORKING_DAYS_PER_MONTH = 26;

    /** @return array<string, mixed> */
    public function calculate(string $kind, bool $grossMisconduct, bool $noticeWorked, bool $executive,
        CarbonInterface $hireDate, CarbonInterface $lastDay, int $monthlySalary, float $leaveBalance): array
    {
        $seniorityYears = round($hireDate->diffInDays($lastDay) / 365.25, 2);
        $daily = $monthlySalary / self::WORKING_DAYS_PER_MONTH;
        $lines = [];

        // Notice
        $noticeMonths = 0.0;
        if ($kind !== 'end_of_contract' && ! $grossMisconduct) {
            $noticeMonths = $seniorityYears < 1 ? ($executive ? 1 : 0.5) : ($seniorityYears <= 5 ? ($executive ? 2 : 1) : ($executive ? 3 : 2));
        }
        $noticeAmount = (int) round($noticeMonths * $monthlySalary);
        if ($noticeMonths > 0 && ! $noticeWorked) {
            if ($kind === 'termination') {
                $lines[] = ['code' => 'notice_indemnity', 'amount' => $noticeAmount, 'basis' => "$noticeMonths month(s) × $monthlySalary"];
            } elseif ($kind === 'resignation') {
                $lines[] = ['code' => 'notice_not_worked', 'amount' => -$noticeAmount, 'basis' => "$noticeMonths month(s) × $monthlySalary owed by employee"];
            }
        }

        // Paid leave balance
        $leaveDays = max(0, round($leaveBalance, 1));
        if ($leaveDays > 0) {
            $lines[] = ['code' => 'leave_indemnity', 'amount' => (int) round($leaveDays * $daily), 'basis' => "$leaveDays day(s) × monthly / ".self::WORKING_DAYS_PER_MONTH];
        }

        // Severance
        $severance = 0;
        if ($kind === 'termination' && ! $grossMisconduct && $seniorityYears >= 2) {
            $rate = 0.0;
            foreach ([[5, 0.20], [10, 0.25], [15, 0.30], [PHP_INT_MAX, 0.35]] as $i => [$upTo, $r]) {
                $from = [0, 5, 10, 15][$i];
                $rate += max(0, min($seniorityYears, $upTo) - $from) * $r;
            }
            $severance = (int) round($monthlySalary * $rate);
            $lines[] = ['code' => 'severance', 'amount' => $severance, 'basis' => "$seniorityYears year(s), banded 20/25/30/35 %"];
        }

        // Salary of the final month (days worked)
        $days = (int) $lastDay->day;
        $lines[] = ['code' => 'final_month_salary', 'amount' => (int) round($monthlySalary / 30 * min(30, $days)), 'basis' => "$days day(s) × monthly / 30"];

        return [
            'requires_review' => true,
            'review_note' => 'Simplified Cameroon Labour Code calculation; a payroll expert must confirm against the collective agreement before payment. Gross amounts, before CNPS and tax.',
            'seniority_years' => $seniorityYears, 'monthly_salary' => $monthlySalary,
            'notice_months' => $noticeMonths, 'notice_worked' => $noticeWorked, 'leave_days' => $leaveDays,
            'lines' => $lines, 'total' => (int) collect($lines)->sum('amount'),
        ];
    }
}
