<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet;

use TimeFrontiers\Wallet\Payment\PaymentVerifierInterface;
use TimeFrontiers\Wallet\Payment\NullPaymentVerifier;
use TimeFrontiers\Wallet\Exchange\ExchangeRateInterface;
use TimeFrontiers\Wallet\Exchange\SameCurrencyProvider;

/**
 * Wallet system configuration.
 *
 * Centralized configuration for the wallet system.
 *
 * @example
 * ```php
 * Config::setLedgerPath('/var/data/.txhive/ledgers');
 * Config::setPaymentVerifier(new StripePaymentVerifier());
 * Config::setExchangeProvider(new FixedRateProvider(['USD:NGN' => 1550]));
 * ```
 */
class Config {

  private static string $_ledger_path = '/.txhive/ledgers';
  private static ?PaymentVerifierInterface $_payment_verifier = null;
  private static ?ExchangeRateInterface $_exchange_provider = null;
  private static int $_precision = 8;
  private static string $_address_prefix = '219';
  private static int $_address_length = 15;
  private static string $_batch_prefix = '127';
  private static int $_batch_length = 15;
  private static bool $_strict_integrity = true;
  private static ?string $_datetime_format = null;
  private static ?string $_db_name = null;

  /**
   * Set the ledger storage path.
   */
  public static function setLedgerPath(string $path):void {
    self::$_ledger_path = \rtrim($path, '/');
  }

  /**
   * Get the ledger storage path.
   */
  public static function ledgerPath():string {
    return self::$_ledger_path;
  }

  /**
   * Set the payment verifier.
   */
  public static function setPaymentVerifier(PaymentVerifierInterface $verifier):void {
    self::$_payment_verifier = $verifier;
  }

  /**
   * Get the payment verifier.
   */
  public static function paymentVerifier():PaymentVerifierInterface {
    return self::$_payment_verifier ?? new NullPaymentVerifier();
  }

  /**
   * Set the exchange rate provider.
   */
  public static function setExchangeProvider(ExchangeRateInterface $provider):void {
    self::$_exchange_provider = $provider;
  }

  /**
   * Get the exchange rate provider.
   */
  public static function exchangeProvider():ExchangeRateInterface {
    return self::$_exchange_provider ?? new SameCurrencyProvider();
  }

  /**
   * Set decimal precision for amounts.
   */
  public static function setPrecision(int $precision):void {
    self::$_precision = \max(2, \min(18, $precision));
  }

  /**
   * Get decimal precision.
   */
  public static function precision():int {
    return self::$_precision;
  }

  /**
   * Set wallet address prefix.
   */
  public static function setAddressPrefix(string $prefix):void {
    self::$_address_prefix = $prefix;
  }

  /**
   * Get wallet address prefix.
   */
  public static function addressPrefix():string {
    return self::$_address_prefix;
  }

  /**
   * Set wallet address length.
   */
  public static function setAddressLength(int $length):void {
    self::$_address_length = \max(10, \min(32, $length));
  }

  /**
   * Get wallet address length.
   */
  public static function addressLength():int {
    return self::$_address_length;
  }

  /**
   * Set batch ID prefix.
   */
  public static function setBatchPrefix(string $prefix):void {
    self::$_batch_prefix = $prefix;
  }

  /**
   * Get batch ID prefix.
   */
  public static function batchPrefix():string {
    return self::$_batch_prefix;
  }

  /**
   * Set batch ID length.
   */
  public static function setBatchLength(int $length):void {
    self::$_batch_length = \max(10, \min(32, $length));
  }

  /**
   * Get batch ID length.
   */
  public static function batchLength():int {
    return self::$_batch_length;
  }

  /**
   * Set database name.
   *
   * @param string $name Database name (with any required prefix).
   */
  public static function setDatabaseName(string $name):void {
    self::$_db_name = $name;
  }

  /**
   * Get database name.
   */
  public static function databaseName():?string {
    return self::$_db_name;
  }

  /**
   * Set strict integrity mode.
   *
   * When true, transactions fail if ledger/DB mismatch.
   * When false, logs warning but continues (trust ledger).
   */
  public static function setStrictIntegrity(bool $strict):void {
    self::$_strict_integrity = $strict;
  }

  /**
   * Check if strict integrity is enabled.
   */
  public static function strictIntegrity():bool {
    return self::$_strict_integrity;
  }

  /**
   * Set datetime format.
   */
  public static function setDateTimeFormat(string $format):void {
    self::$_datetime_format = $format;
  }

  /**
   * Get datetime format.
   */
  public static function dateTimeFormat():string {
    return self::$_datetime_format ?? 'Y-m-d H:i:s';
  }

  /**
   * Format an amount with configured precision.
   */
  public static function formatAmount(float $amount):string {
    return \number_format($amount, self::$_precision, '.', '');
  }

  /**
   * Round an amount to configured precision.
   */
  public static function roundAmount(float $amount):float {
    return \round($amount, self::$_precision);
  }

  /**
   * Generate a unique hash.
   */
  public static function generateHash(int $length = 32):string {
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $hash = '';

    for ($i = 0; $i < $length; $i++) {
      $hash .= $chars[\random_int(0, \strlen($chars) - 1)];
    }

    return $hash;
  }

  /**
   * Generate a wallet address.
   */
  public static function generateAddress():string {
    return self::generateCode(self::$_address_prefix, self::$_address_length);
  }

  /**
   * Generate a batch ID.
   */
  public static function generateBatch():string {
    return self::generateCode(self::$_batch_prefix, self::$_batch_length);
  }

  /**
   * Generate a unique numeric code with prefix.
   *
   * @param string $prefix Code prefix (e.g., '219', '127').
   * @param int $length Total code length including prefix.
   * @return string Generated code.
   */
  public static function generateCode(string $prefix, int $length):string {
    $remaining = $length - \strlen($prefix);

    if ($remaining <= 0) {
      throw new \InvalidArgumentException("Prefix length must be less than total length");
    }

    $suffix = '';
    for ($i = 0; $i < $remaining; $i++) {
      $suffix .= \random_int(0, 9);
    }

    return $prefix . $suffix;
  }

  /**
   * Validate wallet address format.
   */
  public static function isValidAddress(string $address):bool {
    $prefix = self::$_address_prefix;
    $length = self::$_address_length;

    $pattern = "/^{$prefix}[0-9]{" . ($length - \strlen($prefix)) . "}$/";

    return (bool)\preg_match($pattern, $address);
  }

  /**
   * Validate batch ID format.
   */
  public static function isValidBatch(string $batch):bool {
    $prefix = self::$_batch_prefix;
    $length = self::$_batch_length;

    $pattern = "/^{$prefix}[0-9]{" . ($length - \strlen($prefix)) . "}$/";

    return (bool)\preg_match($pattern, $batch);
  }

  /**
   * Reset all configuration to defaults.
   */
  public static function reset():void {
    self::$_ledger_path = '/.txhive/ledgers';
    self::$_payment_verifier = null;
    self::$_exchange_provider = null;
    self::$_precision = 8;
    self::$_address_prefix = '219';
    self::$_address_length = 15;
    self::$_batch_prefix = '127';
    self::$_batch_length = 15;
    self::$_strict_integrity = true;
    self::$_datetime_format = null;
    self::$_db_name = null;
  }
}
