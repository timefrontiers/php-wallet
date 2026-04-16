<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet;

use TimeFrontiers\Wallet\Exception\{
  TransactionException,
  InsufficientBalanceException
};

/**
 * TransactionBatch - builder for batch wallet transactions.
 *
 * Allows multiple transfers from a single source wallet
 * to be executed atomically.
 *
 * @example
 * ```php
 * $result = Transaction::batch($source_wallet)
 *   ->credit('219123456789012', 100.00, 'Bonus payment')
 *   ->credit('219987654321098', 50.00, 'Refund')
 *   ->credit('219555555555555', 25.00, 'Cashback')
 *   ->execute();
 *
 * // $result contains all transaction hashes
 * print_r($result->hashes());
 * echo $result->totalAmount(); // 175.00
 * ```
 */
class TransactionBatch {

  private Wallet $_source;
  private string $_batch_id;
  private array $_operations = [];
  private bool $_executed = false;
  private bool $_validate_before_execute = true;

  public function __construct(Wallet $source) {
    $this->_source = $source;
    $this->_batch_id = $this->_generateBatchId();
  }

  /**
   * Add a credit operation.
   *
   * @param string|Wallet $destination Wallet address or Wallet object.
   * @param float $amount Amount to credit.
   * @param string $narration Description.
   * @return static
   */
  public function credit(string|Wallet $destination, float $amount, string $narration = ''):static {
    $this->_ensureNotExecuted();

    $address = $destination instanceof Wallet 
      ? $destination->address() 
      : $destination;

    $this->_operations[] = [
      'type'       => 'credit',
      'address'    => $address,
      'amount'     => Config::roundAmount(\abs($amount)),
      'narration'  => $narration,
      'wallet'     => $destination instanceof Wallet ? $destination : null,
    ];

    return $this;
  }

  /**
   * Add multiple credits at once.
   *
   * @param array $credits Array of [address, amount, narration].
   * @return static
   */
  public function creditMany(array $credits):static {
    foreach ($credits as $credit) {
      $address = $credit['address'] ?? $credit[0];
      $amount = $credit['amount'] ?? $credit[1];
      $narration = $credit['narration'] ?? $credit[2] ?? '';

      $this->credit($address, $amount, $narration);
    }

    return $this;
  }

  /**
   * Skip validation before execution.
   *
   * Use with caution - may result in partial execution.
   *
   * @return static
   */
  public function skipValidation():static {
    $this->_validate_before_execute = false;
    return $this;
  }

  /**
   * Get total amount of all operations.
   */
  public function totalAmount():float {
    return \array_sum(\array_column($this->_operations, 'amount'));
  }

  /**
   * Get count of operations.
   */
  public function count():int {
    return \count($this->_operations);
  }

  /**
   * Check if batch has operations.
   */
  public function isEmpty():bool {
    return empty($this->_operations);
  }

  /**
   * Validate the batch can be executed.
   *
   * @throws InsufficientBalanceException If insufficient funds.
   * @throws TransactionException On validation errors.
   */
  public function validate():void {
    if ($this->isEmpty()) {
      throw new TransactionException("Batch has no operations");
    }

    $total = $this->totalAmount();
    $balance = $this->_source->balance(true);

    if ($balance < $total) {
      throw new InsufficientBalanceException(
        $total,
        $balance,
        $this->_source->currency()
      );
    }

    // Validate all destination addresses
    foreach ($this->_operations as $op) {
      if (!Config::isValidAddress($op['address'])) {
        throw new TransactionException("Invalid destination address: {$op['address']}");
      }

      if ($op['address'] === $this->_source->address()) {
        throw new TransactionException("Cannot transfer to same wallet");
      }

      if ($op['amount'] <= 0) {
        throw new TransactionException("Amount must be greater than zero");
      }
    }
  }

  /**
   * Execute all batch operations.
   *
   * @return BatchResult Result containing all transaction hashes.
   * @throws TransactionException On execution failure.
   */
  public function execute():BatchResult {
    $this->_ensureNotExecuted();

    if ($this->_validate_before_execute) {
      $this->validate();
    }

    $results = [];
    $origin_hash = Config::generateHash(32);
    $created = \date(Config::dateTimeFormat());
    $source_balance = $this->_source->balance();
    $total_debited = 0.0;

    try {
      foreach ($this->_operations as $index => $op) {
        // Get or create destination wallet
        $dest_wallet = $op['wallet'];
        if ($dest_wallet === null) {
          $dest_wallet = new Wallet($op['address']);
        }

        // Credit destination
        $credit_hash = Config::generateHash(32);
        $credit_tx = [
          'type'        => 'credit',
          'balance'     => $dest_wallet->balance() + $op['amount'],
          'amount'      => $op['amount'],
          'origin'      => $this->_source->address(),
          'hash'        => $credit_hash,
          'origin_hash' => $origin_hash,
          'batch'       => $this->_batch_id,
          'created'     => $created,
        ];

        if (!$dest_wallet->ledger()->record($credit_tx)) {
          throw new TransactionException(
            "Failed to record credit #{$index} to ledger: {$op['address']}"
          );
        }

        $this->_recordToDb($dest_wallet->address(), $credit_tx, $op['narration']);
        $this->_queueAlert($credit_hash);

        $results[] = [
          'type'    => 'credit',
          'hash'    => $credit_hash,
          'address' => $dest_wallet->address(),
          'amount'  => $op['amount'],
        ];

        $total_debited += $op['amount'];
      }

      // Single debit from source for total
      $debit_hash = Config::generateHash(32);
      $debit_tx = [
        'type'        => 'debit',
        'balance'     => $source_balance - $total_debited,
        'amount'      => $total_debited,
        'origin'      => '#BATCH:' . $this->_batch_id,
        'hash'        => $debit_hash,
        'origin_hash' => $origin_hash,
        'batch'       => $this->_batch_id,
        'created'     => $created,
      ];

      if (!$this->_source->ledger()->record($debit_tx)) {
        throw new TransactionException(
          "CRITICAL: Credits recorded but batch debit failed. Manual intervention required. " .
          "Batch: {$this->_batch_id}"
        );
      }

      $this->_recordToDb(
        $this->_source->address(),
        $debit_tx,
        "Batch transfer: {$this->count()} recipients"
      );
      $this->_queueAlert($debit_hash);

      $results[] = [
        'type'    => 'debit',
        'hash'    => $debit_hash,
        'address' => $this->_source->address(),
        'amount'  => $total_debited,
      ];

    } catch (\Throwable $e) {
      // Log partial execution
      if (!empty($results)) {
        \error_log("Batch {$this->_batch_id} partial execution: " . \count($results) . " operations");
      }
      throw $e;
    }

    $this->_executed = true;

    return new BatchResult($this->_batch_id, $results);
  }

  /**
   * Get batch ID.
   */
  public function batchId():string {
    return $this->_batch_id;
  }

  /**
   * Get source wallet.
   */
  public function source():Wallet {
    return $this->_source;
  }

  /**
   * Get operations preview.
   */
  public function operations():array {
    return \array_map(fn($op) => [
      'address'   => $op['address'],
      'amount'    => $op['amount'],
      'narration' => $op['narration'],
    ], $this->_operations);
  }

  private function _ensureNotExecuted():void {
    if ($this->_executed) {
      throw new TransactionException("Batch already executed");
    }
  }

  private function _generateBatchId():string {
    return Config::generateBatch();
  }

  private function _recordToDb(string $address, array $tx, string $narration):void {
    $db = $this->_source->db();

    $stmt = $db->prepare(
      "INSERT INTO wallet_history 
       (hash, origin_hash, address, origin, batch, type, amount, balance, narration, _created)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
      $tx['hash'],
      $tx['origin_hash'],
      $address,
      $tx['origin'],
      $tx['batch'],
      $tx['type'],
      $tx['amount'],
      $tx['balance'],
      $narration,
      $tx['created'],
    ]);
  }

  private function _queueAlert(string $hash):void {
    $db = $this->_source->db();

    try {
      $stmt = $db->prepare("INSERT INTO tranx_alert (sent, tx) VALUES (0, ?)");
      $stmt->execute([$hash]);
    } catch (\Throwable $e) {
      \error_log("Failed to queue alert for tx {$hash}: " . $e->getMessage());
    }
  }
}

/**
 * Result of a batch execution.
 */
class BatchResult {

  private string $_batch_id;
  private array $_results;

  public function __construct(string $batch_id, array $results) {
    $this->_batch_id = $batch_id;
    $this->_results = $results;
  }

  /**
   * Get batch ID.
   */
  public function batchId():string {
    return $this->_batch_id;
  }

  /**
   * Get all results.
   */
  public function all():array {
    return $this->_results;
  }

  /**
   * Get all transaction hashes.
   */
  public function hashes():array {
    return \array_column($this->_results, 'hash');
  }

  /**
   * Get credit hashes only.
   */
  public function creditHashes():array {
    return \array_column(
      \array_filter($this->_results, fn($r) => $r['type'] === 'credit'),
      'hash'
    );
  }

  /**
   * Get debit hash.
   */
  public function debitHash():?string {
    foreach ($this->_results as $r) {
      if ($r['type'] === 'debit') {
        return $r['hash'];
      }
    }
    return null;
  }

  /**
   * Get total amount transferred.
   */
  public function totalAmount():float {
    $debit = \array_filter($this->_results, fn($r) => $r['type'] === 'debit');
    return !empty($debit) ? (float)\array_values($debit)[0]['amount'] : 0.0;
  }

  /**
   * Get count of credits.
   */
  public function creditCount():int {
    return \count(\array_filter($this->_results, fn($r) => $r['type'] === 'credit'));
  }

  /**
   * Check if successful.
   */
  public function successful():bool {
    return !empty($this->_results);
  }

  /**
   * Convert to array.
   */
  public function toArray():array {
    return [
      'batch_id'     => $this->_batch_id,
      'total_amount' => $this->totalAmount(),
      'credit_count' => $this->creditCount(),
      'transactions' => $this->_results,
    ];
  }
}
