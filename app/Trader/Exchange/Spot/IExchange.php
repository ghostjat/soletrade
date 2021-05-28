<?php

namespace App\Trader\Exchange\Spot;

use App\Models\Order;
use App\Trader\Exchange\AccountBalance;
use App\Trader\Exchange\OrderBook;

interface IExchange
{
    public const BUY = true;
    public const SELL = false;

    public function market(bool $side, string $symbol, float $size): Order|false;

    public function limit(bool $side, string $symbol, float $price, float $size): Order|false;

    public function stopLimit(bool $side,
                              string $symbol,
                              float $stopPrice,
                              float $price,
                              float $size): Order|false;

    /**
     * @return Order[]
     *
     */
    public function getOpenOrders(): array;

    public function getAccountBalance(): AccountBalance;

    public function getLastPrice(string $symbol): float;

    public function getOrderBook(string $symbol): OrderBook;
}