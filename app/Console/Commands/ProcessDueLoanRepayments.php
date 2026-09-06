<?php

namespace App\Console\Commands;

use App\Jobs\ChargeDueLoanRepayment;
use App\Models\RepaymentSchedule;
use Illuminate\Console\Command;

class ProcessDueLoanRepayments extends Command
{
    protected $signature = 'loans:process-due-repayments';
    protected $description = 'Queue Paystack charges for due loan installments';

    public function handle(): int
    {
        $count = 0;

        RepaymentSchedule::query()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('balance_due', '>', 0)
            ->whereDate('due_date', '<=', today())
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->whereHas('loan', fn ($query) => $query->where('status', 'active'))
            ->whereHas('loan.user.paymentAuthorizations', fn ($query) => $query->where('status', 'active')->where('reusable', true))
            ->select('id')
            ->chunkById(100, function ($schedules) use (&$count) {
                foreach ($schedules as $schedule) {
                    ChargeDueLoanRepayment::dispatch($schedule->id);
                    $count++;
                }
            });

        $this->info("Queued {$count} repayment job(s).");
        return self::SUCCESS;
    }
}