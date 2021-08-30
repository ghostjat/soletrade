<?php

namespace App\Trade\Strategy;

use App\Models\Signal;
use App\Models\Symbol;
use App\Models\TradeSetup;
use App\Repositories\SymbolRepository;
use App\Trade\HasConfig;
use App\Trade\HasName;
use App\Trade\HasSignature;
use App\Trade\Indicator\AbstractIndicator;
use App\Trade\Log;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

abstract class AbstractStrategy
{
    use HasName;
    use HasSignature;
    use HasConfig;

    protected array $config = [
        'maxCandles' => 1000,
        'startDate'  => null,
        'endDate'    => null
    ];

    protected array $signals = [];
    protected array $indicatorSetup;
    protected array $tradeSetup;

    protected SymbolRepository $symbolRepo;

    public function __construct(array $config = [])
    {
        $this->mergeConfig($config);

        $this->symbolRepo = App::make(SymbolRepository::class);
        $this->indicatorSetup = $this->indicatorSetup();
        $this->tradeSetup = $this->tradeSetup();
        $this->signature = $this->register([
            'contents' => $this->contents()
        ]);
    }

    abstract protected function indicatorSetup(): array;

    abstract protected function tradeSetup(): array;

    public function run(Symbol $symbol): array
    {
        Log::execTime(function () use (&$symbol) {
            $this->initIndicators($symbol);
        }, 'AbstractStrategy::initIndicators()');

        Log::execTime(function () use (&$trades, &$symbol) {
            $trades = $this->findTradeSetups($symbol);
        }, 'AbstractStrategy::findTrades()');

        return $trades;
    }

    protected function initIndicators(Symbol $symbol): void
    {
        $candles = $symbol->candles(limit: $this->config['maxCandles'],
            start: $this->config['startDate'],
            end: $this->config['endDate']);

        foreach ($this->indicatorSetup as $class => $setup)
        {
            $indicator = new $class(symbol: $symbol,
                candles: $candles,
                config: is_array($setup) ? $setup['config'] ?? [] : [],
                signalCallback: $setup instanceof \Closure ? $setup : $setup['signal'] ?? null);

            $symbol->addIndicator(indicator: $indicator);
        }
    }

    /**
     * @return TradeSetup[]
     */
    protected function findTradeSetups(Symbol $symbol): array
    {
        if (!$signals = $this->getConfigSignals($symbol))
        {
            return [];
        }

        $setups = [];

        foreach ($this->tradeSetup as $key => $config)
        {
            if (!$indicators = $this->getConfigIndicators($config))
            {
                throw new \UnexpectedValueException('Invalid signal config for trade setup: ' . $key);
            }

            $requiredTotal = count($config['signals']);
            $requiredSignals = [];
            $firstIndicator = $indicators[0];
            $index = 0;

            while (isset($signals[$firstIndicator][$index]))
            {
                foreach ($indicators as $k => $indicator)
                {
                    $isFirst = $k === 0;
                    /* @var Signal $signal */
                    foreach ($signals[$indicator] as $i => $signal)
                    {
                        if ($isFirst)
                        {
                            if ($i < $index)
                            {
                                continue;
                            }

                            $index = $i + 1;
                        }

                        /** @var Signal $lastSignal */
                        $lastSignal = end($requiredSignals);

                        if (!$lastSignal || ($signal->timestamp >= $lastSignal->timestamp && $lastSignal->side == $signal->side))
                        {
                            if ($this->validateSignal($indicator, $config, $signal))
                            {
                                $requiredSignals[] = $signal;
                                break;
                            }
                        }
                    }
                }

                if ($requiredTotal == count($requiredSignals))
                {
                    if ($tradeSetup = $this->prepareSetup($config, $requiredSignals))
                    {
                        $tradeSetup->symbol_id = $symbol->id;
                        $setups[$key][$tradeSetup->timestamp] = $this->saveTrade($tradeSetup);
                    }
                }

                $requiredSignals = [];
            }
        }

        return $setups;
    }

    protected function getConfigSignals(Symbol $symbol): array
    {
        $signals = [];
        foreach ($this->tradeSetup as $config)
        {
            /* @var AbstractIndicator $indicator */
            foreach ($this->getConfigIndicators($config) as $indicator)
            {
                $indicator = $symbol->indicator($indicator::name());
                $signals[$indicator::class] = $indicator->signals();
            }
        }

        return $signals;
    }

    /**
     * @return string[]
     */
    protected function getConfigIndicators(array $config): array
    {
        $indicators = [];
        foreach ($config['signals'] as $key => $indicator)
        {
            $indicators[] = is_array($indicator) ? $key : $indicator;
        }

        return $indicators;
    }

    protected function validateSignal(string $indicator, array $config, Signal $signal): bool
    {
        return !is_array($config['signals'][$indicator] ?? null) ||
            in_array($signal->name, $config['signals'][$indicator]);
    }

    /**
     * @param Signal[] $signals
     */
    protected function setupTrade(array $config, array $signals): TradeSetup
    {
        $signature = $this->register([
            'strategy'        => [
                'config' => $this->config,
                'hash'   => $this->signature->hash
            ],
            'trade_setup'     => $config,
            'indicator_setup' => array_map(
                fn(string $class): array => $this->indicatorSetup[$class],
                $this->getConfigIndicators($config))
        ]);

        $tradeSetup = new TradeSetup();
        $tradeSetup->signals = $signals;
        $lastSignal = end($signals);

        $tradeSetup->signal_count = count($signals);
        $tradeSetup->name = implode('|', array_map(
            static fn(Signal $signal) => $signal->name, $signals));
        $tradeSetup->price = $lastSignal->price;
        $tradeSetup->side = $lastSignal->side;
        $tradeSetup->timestamp = $lastSignal->timestamp;
        $tradeSetup->signature_id = $signature->id;

        return $tradeSetup;
    }

    /**
     * @throws \Exception
     */
    protected function saveTrade(TradeSetup $tradeSetup): TradeSetup
    {
        DB::transaction(static function () use (&$tradeSetup) {
            $tradeSetup = TradeSetup::query()->updateOrCreate(
                $tradeSetup->uniqueAttributesToArray(),
                $tradeSetup->attributesToArray());
            $tradeSetup->signals()->saveMany($tradeSetup->signals);
        });

        return $tradeSetup;
    }


    protected function prepareSetup(mixed $config, array $requiredSignals): ?TradeSetup
    {
        $tradeSetup = $this->setupTrade($config, $requiredSignals);
        $callback = $config['callback'] ?? null;

        if ($callback instanceof \Closure)
        {
            $tradeSetup = $callback($tradeSetup);
        }

        return $tradeSetup;
    }
}