<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet\Exchange;

/**
 * Fixed exchange rate provider.
 *
 * Uses predefined rates. Good for stable/pegged currencies.
 *
 * @example
 * ```php
 * $provider = new FixedRateProvider([
 *   'NGN:DWL' => 1.0,      // 1:1 peg
 *   'USD:NGN' => 1550.0,
 *   'EUR:NGN' => 1680.0,
 * ]);
 *
 * $ngn = $provider->convert(100, 'USD', 'NGN'); // 155000.0
 * ```
 */
class FixedRateProvider implements ExchangeRateInterface {

  private array $_rates = [];
  private array $_currencies = [];

  /**
   * @param array $rates Rates as 'FROM:TO' => rate pairs.
   */
  public function __construct(array $rates = []) {
    foreach ($rates as $pair => $rate) {
      $this->setRate($pair, $rate);
    }
  }

  /**
   * Set a rate for a currency pair.
   *
   * Automatically creates the inverse rate.
   *
   * @param string $pair Currency pair as 'FROM:TO'.
   * @param float $rate Exchange rate.
   * @return static
   */
  public function setRate(string $pair, float $rate):static {
    [$from, $to] = $this->_parsePair($pair);

    $this->_rates["{$from}:{$to}"] = $rate;

    // Auto-create inverse if not 1:1
    if ($rate > 0) {
      $this->_rates["{$to}:{$from}"] = 1 / $rate;
    }

    // Track currencies
    $this->_currencies[$from] = true;
    $this->_currencies[$to] = true;

    return $this;
  }

  public function rate(string $from, string $to):float {
    $from = \strtoupper(\trim($from));
    $to = \strtoupper(\trim($to));

    // Same currency
    if ($from === $to) {
      return 1.0;
    }

    $pair = "{$from}:{$to}";

    if (!isset($this->_rates[$pair])) {
      throw new \InvalidArgumentException("Exchange rate not defined for: {$pair}");
    }

    return $this->_rates[$pair];
  }

  public function convert(float $amount, string $from, string $to):float {
    return \round($amount * $this->rate($from, $to), 8);
  }

  public function supports(string $from, string $to):bool {
    $from = \strtoupper(\trim($from));
    $to = \strtoupper(\trim($to));

    if ($from === $to) {
      return isset($this->_currencies[$from]);
    }

    return isset($this->_rates["{$from}:{$to}"]);
  }

  public function currencies():array {
    return \array_keys($this->_currencies);
  }

  /**
   * Get all defined rates.
   */
  public function rates():array {
    return $this->_rates;
  }

  private function _parsePair(string $pair):array {
    $parts = \explode(':', \strtoupper(\trim($pair)));

    if (\count($parts) !== 2) {
      throw new \InvalidArgumentException("Invalid currency pair format: {$pair}. Expected 'FROM:TO'");
    }

    return [\trim($parts[0]), \trim($parts[1])];
  }
}

/**
 * Same-currency-only provider (no conversion).
 *
 * Use when multi-currency is not supported.
 */
class SameCurrencyProvider implements ExchangeRateInterface {

  private array $_currencies;

  public function __construct(array $currencies = ['NGN']) {
    $this->_currencies = \array_map('strtoupper', $currencies);
  }

  public function rate(string $from, string $to):float {
    $from = \strtoupper(\trim($from));
    $to = \strtoupper(\trim($to));

    if ($from !== $to) {
      throw new \InvalidArgumentException("Cross-currency conversion not supported: {$from} to {$to}");
    }

    return 1.0;
  }

  public function convert(float $amount, string $from, string $to):float {
    if (\strtoupper($from) !== \strtoupper($to)) {
      throw new \InvalidArgumentException("Cross-currency conversion not supported");
    }
    return $amount;
  }

  public function supports(string $from, string $to):bool {
    return \strtoupper($from) === \strtoupper($to)
        && \in_array(\strtoupper($from), $this->_currencies, true);
  }

  public function currencies():array {
    return $this->_currencies;
  }
}
