<?php

namespace Trade\Evaluation;

use App\Models\Evaluation;
use App\Trade\Evaluation\Summarizer;
use PHPUnit\Framework\TestCase;

class SummaryTest extends TestCase
{
    public function test_evaluation_count()
    {
        $evaluation = $this->getTenPercentPositiveRoiBuyEvaluation();

        $summarizer = new Summarizer();
        $summary = $summarizer->summarize($evaluations = collect([$evaluation]));

        $this->assertEquals($evaluations->count(), $summary->total);
    }

    public function test_fee_ratio()
    {
        $evaluation = $this->getTenPercentPositiveRoiBuyEvaluation();

        $summarizer = new Summarizer();
        $summary = $summarizer->summarize(collect([$evaluation]));
        $feeRatio = $summarizer->config('feeRatio');

        $this->assertEquals($feeRatio, $summary->fee_ratio);
        $this->assertEquals($this->calcFeeIncludedRoi(10, $feeRatio), $summary->roi);
    }

    protected function calcFeeIncludedRoi(float $roi, float $feeRatio): float
    {
        $balance = (100 - 100 * $feeRatio * 2);
        return $balance + $balance * $roi / 100 - 100;
    }

    protected function getTenPercentPositiveRoiBuyEvaluation(): Evaluation
    {
        /** @var Evaluation $evaluation */
        $evaluation = \Mockery::mock('alias:' . Evaluation::class);
        $evaluation->entry_price = 100;
        $evaluation->stop_price = 50;
        $evaluation->close_price = 200;
        $evaluation->realized_roi = 10;
        $evaluation->relative_roi = 10;
        $evaluation->highest_price = 110;
        $evaluation->lowest_price = 90;
        $evaluation->lowest_roi = -10;
        $evaluation->highest_roi = 10;
        $evaluation->used_size = 100;
        $evaluation->is_closed = true;
        $evaluation->is_stopped = false;
        $evaluation->is_ambiguous = false;
        $evaluation->is_entry_price_valid = 1;
        return $evaluation;
    }

}