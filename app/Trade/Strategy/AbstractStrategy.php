<?php

declare(strict_types=1);

namespace App\Trade\Strategy;

use App\Models\Binding;
use App\Models\Signal;
use App\Models\Signature;
use App\Models\Symbol;
use App\Models\TradeSetup;
use App\Repositories\SymbolRepository;
use App\Trade\Binding\CanBind;
use App\Trade\Evaluation\TradeLoop;
use App\Trade\HasConfig;
use App\Trade\HasName;
use App\Trade\HasSignature;
use App\Trade\Indicator\AbstractIndicator;
use App\Trade\Log;
use App\Trade\Strategy\TradeAction\AbstractTradeActionHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

abstract class AbstractStrategy
{
    use HasName;
    use HasSignature;
    use HasConfig;
    use CanBind;

    protected array $config = [];
    protected \WeakMap $actions;
    protected SymbolRepository $symbolRepo;
    private array $indicatorConfig;
    private array $tradeConfig;
    private ?Signal $lastSignal;
    private array $bindMap;
    /**
     * @var TradeSetup[]
     */
    private Collection $trades;
    /**
     * @var Collection[]
     */
    private Collection $signals;
    /**
     * @var AbstractIndicator[]
     */
    private Collection $indicators;
    /**
     * @var AbstractIndicator[]
     */
    private Collection $helperIndicators;

    public function __construct(array $config = [])
    {
        $this->mergeConfig($config);

        $this->symbolRepo = App::make(SymbolRepository::class);

        $this->indicators = new Collection();
        $this->indicatorConfig = $this->indicatorConfig();
        $this->tradeConfig = $this->tradeConfig();
        $this->signature = $this->register([
            'contents' => $this->contents()
        ]);

        $this->bindMap = $this->getBindMap();

        $this->actions = new \WeakMap();
    }

    abstract protected function indicatorConfig(): array;

    abstract protected function tradeConfig(): array;

    protected function getBindMap(): array
    {
        return ['last_signal_price' => 'price'];
    }

    public function getFirstTrade(): ?TradeSetup
    {
        return $this->trades->first();
    }

    protected function findNextOppositeTrade(TradeSetup $tradeSetup): ?TradeSetup
    {
        $isBuy = $tradeSetup->isBuy();

        while ($next = $this->findNextTrade($next ?? $tradeSetup))
        {
            if ($next->isBuy() !== $isBuy)
            {
                return $next;
            }
        }

        return null;
    }

    public function getNextTrade(TradeSetup $tradeSetup): ?TradeSetup
    {
        if ($this->config('oppositeOnly'))
        {
            return $this->findNextOppositeTrade($tradeSetup);
        }
        return $this->findNextTrade($tradeSetup);
    }

    protected function findNextTrade(TradeSetup $tradeSetup): ?TradeSetup
    {
        $timestamp = $tradeSetup->timestamp;

        $iterator = $this->trades->getIterator();

        while ($iterator->valid())
        {
            if ($iterator->key() == $timestamp)
            {
                $iterator->next();
                return $iterator->current();
            }

            $iterator->next();
        }

        return null;
    }

    public function newAction(TradeSetup $setup, string $actionClass, array $config): void
    {
        if (!is_subclass_of($actionClass, AbstractTradeActionHandler::class))
        {
            throw new \InvalidArgumentException('Invalid trade action class: ' . $actionClass);
        }

        if (!isset($this->actions[$setup]))
        {
            $this->actions[$setup] = new Collection();
        }

        $this->actions[$setup][$actionClass] = $config;
    }

    public function signals(): Collection
    {
        return $this->signals;
    }

    public function run(Symbol $symbol): void
    {
        $symbol->updateCandles();

        Log::execTimeStart('AbstractStrategy::initIndicators()');
        $this->initIndicators($symbol);
        Log::execTimeFinish('AbstractStrategy::initIndicators()');

        $this->signals = $this->getConfigSignals($symbol);

        Log::execTimeStart('AbstractStrategy::findTrades()');
        $this->trades = $this->findTrades($symbol);
        Log::execTimeFinish('AbstractStrategy::findTrades()');
    }

    protected function initIndicators(Symbol $symbol): void
    {
        $candles = $symbol->candles(limit: $this->config['maxCandles'],
            start: $this->config['startDate'],
            end: $this->config['endDate']);

        $this->initHelperIndicators($symbol, $candles);

        foreach ($this->indicatorConfig as $class => $setup)
        {
            /** @var AbstractIndicator $indicator */
            $indicator = new $class(symbol: $symbol,
                candles: $candles,
                config: is_array($setup) ? $setup['config'] ?? [] : [],
                signalCallback: $setup instanceof \Closure ? $setup : $setup['signal'] ?? null);

            $this->indicators[$indicator->id()] = $indicator;
            $symbol->addIndicator(indicator: $indicator);
        }

    }

    protected function initHelperIndicators(Symbol $symbol, Collection $candles): void
    {
        $helpers = [];
        foreach ($this->helperIndicators() as $class => $config)
        {
            /** @var Symbol $helperSymbol */
            $helperSymbol = Symbol::query()
                ->where('exchange_id', $symbol->exchange_id)
                ->where('symbol', $config['symbol'] ?? $symbol->symbol)
                ->where('interval', $config['interval'] ?? $symbol->interval)
                ->firstOrFail();

            $helperSymbol->updateCandles();

            $nextCandle = $this->symbolRepo->fetchNextCandle($symbol->id, $candles->last()->t);
            $helperCandles = $helperSymbol->candles(start: $candles->first()->t, end: $nextCandle ? $nextCandle->t : null);

            unset($config['interval']);
            unset($config['symbol']);

            /** @var AbstractIndicator $helperIndicator */
            $helperIndicator = new $class(symbol: $helperSymbol, candles: $helperCandles, config: $config);
            if ($helperIndicator->symbol() === $symbol)
            {
                $symbol->addIndicator($helperIndicator);
            }
            $helpers[$class] = $helperIndicator;
        }

        $this->helperIndicators = new Collection($helpers);
    }

    protected function helperIndicators(): array
    {
        return [];
    }

    protected function getConfigSignals(Symbol $symbol): Collection
    {
        $signals = [];

        /* @var \App\Trade\Indicator\AbstractIndicator $indicator */
        foreach ($this->getConfigIndicators($this->tradeConfig) as $indicator)
        {
            $indicator = $symbol->indicator($indicator::name());
            $signals[$indicator::class] = $indicator->signals();
        }

        return new Collection($signals);
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

    /**
     * @return TradeSetup[]
     * @throws \Exception
     */
    protected function findTrades(Symbol $symbol): Collection
    {
        $setups = [];

        if (!$indicators = $this->getConfigIndicators($this->tradeConfig))
        {
            throw new \UnexpectedValueException('Invalid signal config for trade setup: ' . static::name());
        }

        $required = count($this->tradeConfig['signals']);
        $signals = [];
        $firstIndicator = $indicators[0];
        $index = 0;

        while (isset($this->signals[$firstIndicator][$index]))
        {
            foreach ($indicators as $k => $indicator)
            {
                $isFirst = $k === 0;
                /* @var Signal $signal */
                foreach ($this->signals[$indicator] as $i => $signal)
                {
                    if ($isFirst)
                    {
                        if ($i < $index) continue;
                        $index = $i + 1;
                    }

                    /** @var Signal $lastSignal */
                    $lastSignal = end($signals);

                    if (!$lastSignal || ($signal->timestamp >= $lastSignal->timestamp && $lastSignal->side === $signal->side))
                    {
                        if ($this->validateSignal($indicator, $this->tradeConfig, $signal))
                        {
                            $signals[] = $signal;
                            break;
                        }
                    }
                }
            }

            if ($required === count($signals))
            {
                $this->lastSignal = $signal;
                $signals = new Collection($signals);
                $tradeSetup = $this->setupTrade($symbol, $this->tradeConfig, $signals);

                if ($tradeSetup = $this->applyTradeSetupConfig($tradeSetup, $signals, $this->tradeConfig))
                {
                    $setups[$tradeSetup->timestamp] = $this->saveTrade($tradeSetup, $signals);
                }
                $this->lastSignal = null;
            }

            $signals = [];
        }

        return new Collection($setups);
    }

    protected function validateSignal(string $indicator, array $config, Signal $signal): bool
    {
        return !is_array($config['signals'][$indicator] ?? null) ||
            in_array($signal->name, $config['signals'][$indicator]);
    }

    /**
     * @param Signal[] $signals
     */
    protected function setupTrade(Symbol $symbol, array $config, Collection $signals): TradeSetup
    {
        $signature = $this->registerTradeSetupSignature($config);
        $tradeSetup = $this->newTrade($symbol, $signature);

        return $this->fillTrade($tradeSetup, $signals);
    }

    protected function registerTradeSetupSignature(array $config): Signature
    {
        return $this->register([
            'strategy'        => [
                'signature' => $this->signature->hash
            ],
            'trade_setup'     => $config,
            'indicator_setup' => array_map(
                fn(string $class): array => $this->indicatorConfig[$class],
                $this->getConfigIndicators($config))
        ]);
    }

    protected function newTrade(Symbol $symbol, Signature $signature): TradeSetup
    {
        $tradeSetup = new TradeSetup();

        $tradeSetup->symbol()->associate($symbol);
        $tradeSetup->signature()->associate($signature);

        return $tradeSetup;
    }

    protected function fillTrade(TradeSetup $tradeSetup, Collection $signals): TradeSetup
    {
        /** @var Signal $lastSignal */
        $lastSignal = $signals->last();

        $tradeSetup->signal_count = count($signals);
        $tradeSetup->name = $signals->map(static fn(Signal $signal): string => $signal->name)->implode('|');
        $tradeSetup->side = $lastSignal->side;
        $tradeSetup->timestamp = $lastSignal->timestamp;
        $tradeSetup->price = $lastSignal->price;
        $tradeSetup->price_date = $lastSignal->price_date;

        return $tradeSetup;
    }

    protected function applyTradeSetupConfig(TradeSetup $tradeSetup, Collection $signals, mixed $config): ?TradeSetup
    {
        $callback = $config['callback'] ?? null;

        if ($callback instanceof \Closure)
        {
            $tradeSetup = $callback($tradeSetup, $signals);
        }

        return $tradeSetup;
    }

    /**
     * @throws \Exception
     */
    protected function saveTrade(TradeSetup $tradeSetup, Collection $signals): TradeSetup
    {
        $old = $tradeSetup;
        $actions = $this->actions($old);
        DB::transaction(function () use (&$tradeSetup, &$signals, &$actions) {

            /** @var TradeSetup $tradeSetup */
            $tradeSetup = $tradeSetup->updateUniqueOrCreate();
            $tradeSetup->actions()->delete();

            if ($actions)
            {
                foreach ($actions as $class => $config)
                {
                    $tradeSetup->actions()->create([
                        'class'  => $class,
                        'config' => $config
                    ]);
                }
            }

            $tradeSetup->signals()->sync($signals->map(static fn(Signal $signal): int => $signal->id)->all());
        });

        $this->replaceBindable($old, $tradeSetup);
        $this->saveBindings($tradeSetup);

        foreach ($this->indicators as $indicator)
        {
            $indicator->replaceBindable($old, $tradeSetup);
            $indicator->saveBindings($tradeSetup);
        }

        return $tradeSetup;
    }

    public function actions(TradeSetup $setup): ?Collection
    {
        return $this->actions[$setup] ?? null;
    }

    public function newLoop(TradeSetup $entry): TradeLoop
    {
        $symbol = $this->getEvaluationSymbol($entry);

        if (time() * 1000 >= $symbol->last_update + 60 * 1000)
        {
            $symbol->updateCandles();
        }

        return new TradeLoop($entry, $symbol, $this->config('loop'));
    }

    public function trades()
    {
        return $this->trades;
    }

    protected function getEvaluationSymbol(TradeSetup $entry): Symbol
    {
        $symbol = $entry->symbol;
        $exchange = $symbol->exchange();
        $symbolName = $symbol->symbol;
        $evaluationInterval = $this->config('evaluationInterval');
        return $this->symbolRepo->fetchSymbol($exchange, $symbolName, $evaluationInterval)
            ?? $this->symbolRepo->fetchSymbolFromExchange($exchange, $symbolName, $evaluationInterval);
    }

    public function indicator(int $id): AbstractIndicator
    {
        return $this->indicators[$id];
    }

    public function helperIndicator(string $class): AbstractIndicator
    {
        return $this->helperIndicators[$class];
    }

    protected final function getDefaultConfig(): array
    {
        return [
            'maxCandles'         => 1000,
            'startDate'          => null,
            'endDate'            => null,
            'oppositeOnly'       => false, //if true, multiple trades to the same direction won't be allowed
            //evaluation
            'loop'               => [
                'timeout'    => 1440, //trade duration in minutes - exceeding trades will be closed
                'stopAtExit' => true, //if true, close trade at exit setup
            ],
            'evaluationInterval' => '1m',

            //summary
            'feeRatio'           => 0.001
        ];
    }

    protected function getSavePoints(string|int $bind, Signature $signature): array
    {
        $data = $signature->data;

        if ($bind === 'last_signal_price' && ($id = $data['extra']['last_signal_binding_signature_id']))
        {
            return DB::table('save_points')
                ->where('binding_signature_id', $id)
                ->where('timestamp', '>=', $this->config['startDate'])
                ->where('timestamp', '<=', $this->config['endDate'])
                ->get(['timestamp', 'value'])
                ->map(fn($v) => (array)$v)
                ->all();
        }

        throw new \InvalidArgumentException("Could not get save points for binding: {$bind}");
    }

    protected function getBindable(): array
    {
        return ['last_signal_price'];
    }

    protected function getBindingSignatureExtra(string|int $bind): array
    {
        return [
            'last_signal_binding_signature_id' => $this->fetchSignalBindingSignature($this->lastSignal,
                $this->bindMap['last_signal_price'])->id
        ];
    }

    private function fetchSignalBindingSignature(Signal $signal, string $column): Signature
    {
        /** @var Binding $binding */
        $binding = $signal->bindings()
            ->with('signature')
            ->where('column', $column)
            ->first();

        return $binding->signature;
    }

    protected function getBindValue(int|string $bind, ?int $timestamp = null): mixed
    {
        $column = $this->bindMap[$bind] ?? $bind;

        return $this->lastSignal->getAttribute($column);
    }
}