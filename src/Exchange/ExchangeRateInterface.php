<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet\Exchange;

/**
 * Interface for currency exchange rates.
 *
 * Implement this to provide exchange rates from external sources.
 */
interface ExchangeRateInterface {

  /**
   * Get exchange rate from one currency to another.
   *
   * @param string $from Source currency code.
   * @param string $to Target currency code.
   * @return float Exchange rate (multiply source amount by this).
   * @throws \InvalidArgumentException If currency pair not supported.
   */
  public function rate(string $from, string $to):float;

  /**
   * Convert an amount from one currency to another.
   *
   * @param float $amount Amount to convert.
   * @param string $from Source currency code.
   * @param string $to Target currency code.
   * @return float Converted amount.
   */
  public function convert(float $amount, string $from, string $to):float;

  /**
   * Check if a currency pair is supported.
   *
   * @param string $from Source currency code.
   * @param string $to Target currency code.
   * @return bool True if conversion is supported.
   */
  public function supports(string $from, string $to):bool;

  /**
   * Get all supported currency codes.
   *
   * @return array List of currency codes.
   */
  public function currencies():array;
}
