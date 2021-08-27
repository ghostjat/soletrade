<?php

namespace App\Repositories;

use App\Models\Symbol;
use App\Trade\CandleMap;
use App\Trade\Exchange\AbstractExchange;
use App\Trade\Indicator\AbstractIndicator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SymbolRepository
{
    public function candles(AbstractExchange $exchange,
                            string           $symbol,
                            string           $interval,
                            int              $limit = null,
                            array            $indicators = [],
                            int              $end = null,
                            int              $start = null): ?Symbol
    {
        /** @var Symbol $symbol */
        /** @noinspection CallableParameterUseCaseInTypeContextInspection */
        $symbol = Symbol::query()
            ->where('exchange_id', $exchange::instance()->id())
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->first();

        if ($symbol)
        {
            $this->initIndicators($symbol,
                $symbol->candles(limit: $limit, start: $start, end: $end),
                $indicators);
        }

        return $symbol;
    }

    public function mapCandles(array $candles, int $symbolId, CandleMap $map): array
    {
        $mapped = [];
        foreach ($candles as $candle)
        {
            $mapped[] = [
                'symbol_id' => $symbolId,
                't'         => $candle[$map->t],
                'o'         => $candle[$map->o],
                'c'         => $candle[$map->c],
                'h'         => $candle[$map->h],
                'l'         => $candle[$map->l],
                'v'         => $candle[$map->v],
            ];
        }
        return $mapped;
    }

    public function fetchLowestHighestPriceBetween(int $symbolId, int $startDate, int $endDate): object
    {
        $candle = DB::table('candles')
            ->select(DB::raw('max(h) as h, min(l) as l'))
            ->where('symbol_id', $symbolId)
            ->where('t', '>=', $startDate)
            ->where('t', '<=', $endDate)
            ->first();

        if (!$candle)
        {
            throw new \UnexpectedValueException('Highest/lowest price between the two dates was not found.');
        }

        $candle->h = (float)$candle->h;
        $candle->l = (float)$candle->l;

        return $candle;
    }

    public function fetchCandlesBetween(int $symbolId, int $startDate, int $endDate): \Illuminate\Support\Collection
    {
        $candles = DB::table('candles')
            ->where('symbol_id', $symbolId)
            ->where('t', '>=', $startDate)
            ->where('t', '<=', $endDate)
            ->orderBy('t', 'ASC')
            ->get();

        if (!$candles)
        {
            /** @var Symbol $symbol */
            $symbol = Symbol::query()->findOrFail($symbolId);
            throw new \UnexpectedValueException("$symbol->symbol-$symbol->interval candles are missing.");
        }
        return $candles;
    }

    public function insertIgnoreSymbols(array $symbols, int $exchangeId, string $interval): void
    {
        $inserts = [];

        foreach ($symbols as $symbol)
        {
            $inserts[] = [
                'symbol'      => $symbol,
                'exchange_id' => $exchangeId,
                'interval'    => $interval
            ];
        }

        DB::table('symbols')->insertOrIgnore($inserts);
    }

    public function updateCandle(int $id, array $values): int
    {
        return DB::table('candles')
            ->where('id', $id)
            ->update($values);
    }

    /**
     * @param Symbol $symbol
     */
    public function latestCandles(Symbol $symbol, string $direction = 'DESC', int $limit = 10): Collection
    {
        return DB::table('candles')
            ->where('symbol_id', $symbol->id)
            ->orderBy('t', $direction)
            ->limit($limit)
            ->get();
    }

    /**
     * @return Symbol[]
     */
    public function fetchSymbols(array $symbols, string $interval, int $exchangeId): Collection
    {
        return Symbol::query()
            ->whereIn('symbol', $symbols)
            ->where('interval', $interval)
            ->where('exchange_id', $exchangeId)
            ->get();
    }

    public function intervals(): Collection
    {
        return DB::table('symbols')->distinct()->get('interval')->pluck('interval');
    }

    /**
     * @param string[] $indicators
     */
    public function initIndicators(Symbol     $symbol,
                                   Collection $candles,
                                   array      $indicators): void
    {
        foreach ($indicators as $class)
        {
            $symbol->addIndicator(new $class(symbol: $symbol, candles: $candles));
        }
    }

    /**
     * @param AbstractIndicator $indicator
     */
    public function initIndicator(Symbol     $symbol,
                                  Collection $candles,
                                  string     $indicator,
                                  array      $config = [],
                                  ?\Closure  $signalCallback = null)
    {
        $symbol->addIndicator(new $indicator($symbol, $candles, $config, $signalCallback));
    }
}