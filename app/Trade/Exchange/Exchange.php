<?php

namespace App\Trade\Exchange;

use App\Models\Exchange as ExchangeModel;
use App\Repositories\ConfigRepository;
use App\Trade\HasName;
use App\Trade\CandleUpdater;
use Illuminate\Support\Facades\App;
use JetBrains\PhpStorm\ArrayShape;

abstract class Exchange
{
    use HasName;

    protected static ?Exchange $instance = null;
    protected ?string $apiKey;
    protected ?string $secretKey;

    protected ExchangeModel $exchange;
    protected Fetcher $fetch;
    protected Orderer $order;
    private CandleUpdater $update;

    private function __construct()
    {
        /** @var ConfigRepository $repo */
        $repo = App::make(ConfigRepository::class);
        $config = $repo->exchangeConfig(static::name());

        if (!$config)
        {
            throw new \InvalidArgumentException('Invalid config for ' . static::name());
        }

        $this->apiKey = $config['apiKey'] ?? null;
        $this->secretKey = $config['secretKey'] ?? null;

        $this->setup();
        $this->register();

        $this->update = App::make(CandleUpdater::class, ['exchange' => $this]);
    }

    protected function setup(): void
    {

    }

    private function register(): void
    {
        /** @noinspection PhpFieldAssignmentTypeMismatchInspection */
        $this->exchange = ExchangeModel::query()
            ->firstOrCreate(['class' => static::class], [
                'class' => static::class,
                'name'  => static::name(),
            ]);
    }

    public static function instance(): static
    {
        if (!static::$instance)
        {
            return static::$instance = new static();
        }

        return static::$instance;
    }

    public function order(): Orderer
    {
        return $this->order;
    }

    public function fetch(): Fetcher
    {
        return $this->fetch;
    }

    public function update(): CandleUpdater
    {
        return $this->update;
    }

    #[ArrayShape(['name'    => "string",
                  'actions' => "string"])]
    public function info(): array
    {
        return [
            'name'    => static::name(),
            'actions' => \implode(', ', $this->order->actions)
        ];
    }

    public function model(): ExchangeModel
    {
        return $this->exchange;
    }
}