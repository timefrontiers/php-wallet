<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet\Ledger;

/**
 * Interface for wallet ledger implementations.
 *
 * The ledger is the source of truth for wallet balances.
 * It provides an immutable, append-only record of transactions.
 */
interface LedgerInterface {

  /**
   * Record a transaction to the ledger.
   *
   * @param array $tx Transaction data.
   * @return bool True on success.
   */
  public function record(array $tx):bool;

  /**
   * Get current balance from ledger.
   *
   * @return float Balance (credits - debits).
   */
  public function balance():float;

  /**
   * Get transaction by hash.
   *
   * @param string $hash Transaction hash.
   * @return array|null Transaction data or null.
   */
  public function getTransaction(string $hash):?array;

  /**
   * Get all transactions.
   *
   * @param string|null $type Filter by 'credit' or 'debit'.
   * @return array List of transactions.
   */
  public function getTransactions(?string $type = null):array;

  /**
   * Get first transaction.
   *
   * @return array|null Transaction with hash and data.
   */
  public function first():?array;

  /**
   * Get last transaction.
   *
   * @return array|null Transaction with hash and data.
   */
  public function last():?array;

  /**
   * Get transaction count.
   *
   * @param string|null $type Filter by 'credit' or 'debit'.
   * @return int Count.
   */
  public function count(?string $type = null):int;

  /**
   * Get credit and debit totals.
   *
   * @return array ['credit' => float, 'debit' => float]
   */
  public function totals():array;

  /**
   * Verify ledger integrity.
   *
   * @return bool True if ledger is valid.
   */
  public function verify():bool;

  /**
   * Get ledger version.
   *
   * @return string Version string.
   */
  public function version():string;

  /**
   * Get ledger creation date.
   *
   * @return string|null DateTime string.
   */
  public function created():?string;

  /**
   * Get last update date.
   *
   * @return string|null DateTime string.
   */
  public function updated():?string;
}
