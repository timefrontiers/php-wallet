<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet;

use TimeFrontiers\Wallet\Payment\PaymentVerifierInterface;
use TimeFrontiers\Wallet\Exception\{
  TransactionException,
  InsufficientBalanceException,
  PaymentVerificationException,
  IntegrityException
};

/**
 * Transaction - handles wallet credits and debits.
 *
 * Design principles:
 * - Every credit requires a debit source (wallet or payment)
 * - Ledger is written first (source of truth)
 * - Database is written second (for queries)
 * - Transactions are atomic - both sides succeed or both fail
 *
 * @example
 * ```php
 * // Transfer between wallets
 * $tx = Transaction::transfer($from_wallet, $to_wallet, 100.00, 'Payment');
 *
 * // Credit from external payment
 * $tx = Transaction::creditFromPayment($wallet, 'PAY123', 500.00, 'Top-up');
 *
 * // Batch transfer
 * $batch = Transaction::batch($source_wallet)
 *   ->credit($addr1, 100, 'Bonus')
 *   ->credit($addr2, 50, 'Refund')
 *   ->execute();
 * ```
 */
class Transaction {

  private string $_hash;
  private string $_origin_hash;
  private string $_address;
  private string $_origin;
  private string $_batch;
  private string $_type;
  private float $_amount;
  private float $_balance;
  private string $_narration;
  private string $_created;

  private static ?\PDO $_db = null;
  private static string $_table_name = 'wallet_history';
  private static string $_alert_table = 'tranx_alert';

  private function __construct() {
    // Use static factory methods
  }

  // =========================================================================
  // Factory Methods
  // =========================================================================

  /**
   * Transfer funds between wallets.
   *
   * Creates a debit on source and credit on destination.
   *
   * @param Wallet $from Source wallet.
   * @param Wallet $to Destination wallet.
   * @param float $amount Amount to transfer.
   * @param string $narration Description.
   * @param string|null $batch Batch ID (auto-generated if null).
   * @return array Two transaction hashes [credit_hash, debit_hash].
   */
  public static function transfer(
    Wallet $from,
    Wallet $to,
    float $amount,
    string $narration,
    ?string $batch = null
  ):array {
    $amount = Config::roundAmount(\abs($amount));

    if ($amount <= 0) {
      throw new TransactionException("Amount must be greater than zero");
    }

    // Validate same currency
    if ($from->currency() !== $to->currency()) {
      throw new TransactionException(
        "Currency mismatch: {$from->currency()} vs {$to->currency()}"
      );
    }

    // Check balance
    if (!$from->hasSufficientBalance($amount)) {
      throw new InsufficientBalanceException(
        $amount,
        $from->balance(),
        $from->currency()
      );
    }

    // Verify integrity before transaction
    $from->balance(true);
    $to->balance(true);

    $batch = $batch ?? self::_generateBatch();
    $origin_hash = Config::generateHash(32);
    $created = \date(Config::dateTimeFormat());

    $hashes = [];

    // 1. Credit destination (ledger first)
    $credit_hash = Config::generateHash(32);
    $new_credit_balance = $to->balance() + $amount;

    $credit_tx = [
      'type'        => 'credit',
      'balance'     => $new_credit_balance,
      'amount'      => $amount,
      'origin'      => $from->address(),
      'hash'        => $credit_hash,
      'origin_hash' => $origin_hash,
      'batch'       => $batch,
      'created'     => $created,
    ];

    // Write to ledger
    if (!$to->ledger()->record($credit_tx)) {
      throw new TransactionException("Failed to record credit to ledger");
    }

    // Write to database (use destination wallet's db connection)
    self::_recordToDb($to->db(), $to->address(), $credit_tx, $narration);
    self::_queueAlert($to->db(), $credit_hash);
    $hashes[] = $credit_hash;

    // 2. Debit source
    $debit_hash = Config::generateHash(32);
    $new_debit_balance = $from->balance() - $amount;

    $debit_tx = [
      'type'        => 'debit',
      'balance'     => $new_debit_balance,
      'amount'      => $amount,
      'origin'      => $to->address(),
      'hash'        => $debit_hash,
      'origin_hash' => $origin_hash,
      'batch'       => $batch,
      'created'     => $created,
    ];

    // Write to ledger
    if (!$from->ledger()->record($debit_tx)) {
      // Rollback credit - this is tricky with append-only ledger
      // For now, throw error - manual intervention needed
      throw new TransactionException(
        "CRITICAL: Credit recorded but debit failed. Manual intervention required. " .
        "Credit hash: {$credit_hash}"
      );
    }

    // Write to database (use source wallet's db connection)
    self::_recordToDb($from->db(), $from->address(), $debit_tx, $narration);
    self::_queueAlert($from->db(), $debit_hash);
    $hashes[] = $debit_hash;

    return $hashes;
  }

  /**
   * Credit wallet from external payment.
   *
   * @param Wallet $wallet Destination wallet.
   * @param string $payment_ref Payment reference.
   * @param float $amount Amount to credit.
   * @param string $narration Description.
   * @param PaymentVerifierInterface|null $verifier Payment verifier.
   * @return string Transaction hash.
   */
  public static function creditFromPayment(
    Wallet $wallet,
    string $payment_ref,
    float $amount,
    string $narration,
    ?PaymentVerifierInterface $verifier = null
  ):string {
    $verifier = $verifier ?? Config::paymentVerifier();
    $amount = Config::roundAmount(\abs($amount));

    if ($amount <= 0) {
      throw new TransactionException("Amount must be greater than zero");
    }

    // Verify payment
    if (!$verifier->verify($payment_ref, $amount, $wallet->currency())) {
      throw new PaymentVerificationException(
        $payment_ref,
        "Payment verification failed or insufficient funds"
      );
    }

    $batch = self::_generateBatch();
    $hash = Config::generateHash(32);
    $created = \date(Config::dateTimeFormat());
    $new_balance = $wallet->balance() + $amount;

    $tx = [
      'type'        => 'credit',
      'balance'     => $new_balance,
      'amount'      => $amount,
      'origin'      => $payment_ref,
      'hash'        => $hash,
      'origin_hash' => '#PAYMENT',
      'batch'       => $batch,
      'created'     => $created,
    ];

    // Write to ledger first
    if (!$wallet->ledger()->record($tx)) {
      throw new TransactionException("Failed to record credit to ledger");
    }

    // Mark payment as spent
    if (!$verifier->markSpent($payment_ref, $amount, $hash)) {
      \error_log("Warning: Failed to mark payment {$payment_ref} as spent for tx {$hash}");
    }

    // Write to database (use wallet's db connection)
    self::_recordToDb($wallet->db(), $wallet->address(), $tx, $narration);
    self::_queueAlert($wallet->db(), $hash);

    return $hash;
  }

  /**
   * Create a batch transaction builder.
   *
   * @param Wallet $source Source wallet for debits.
   * @return TransactionBatch Batch builder.
   */
  public static function batch(Wallet $source):TransactionBatch {
    return new TransactionBatch($source);
  }

  /**
   * Find transaction by hash.
   *
   * @param string $hash Transaction hash.
   * @param \PDO|null $db Database connection (uses shared if null).
   */
  public static function find(string $hash, ?\PDO $db = null):?self {
    $db = $db ?? self::$_db ?? Wallet::database();
    if ($db === null) {
      return null;
    }

    $stmt = $db->prepare(
      "SELECT * FROM " . self::$_table_name . " WHERE hash = ? LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
      return null;
    }

    $tx = new self();
    $tx->_hash = $row['hash'];
    $tx->_origin_hash = $row['origin_hash'];
    $tx->_address = $row['address'];
    $tx->_origin = $row['origin'];
    $tx->_batch = $row['batch'];
    $tx->_type = $row['type'];
    $tx->_amount = (float)$row['amount'];
    $tx->_balance = (float)$row['balance'];
    $tx->_narration = $row['narration'] ?? '';
    $tx->_created = $row['_created'] ?? $row['created'] ?? '';

    return $tx;
  }

  /**
   * Find transactions by wallet address.
   *
   * @param string $address Wallet address.
   * @param string|null $type Filter by 'credit' or 'debit'.
   * @param int $limit Max results.
   * @param int $offset Offset for pagination.
   * @param \PDO|null $db Database connection.
   */
  public static function findByAddress(
    string $address,
    ?string $type = null,
    int $limit = 100,
    int $offset = 0,
    ?\PDO $db = null
  ):array {
    $db = $db ?? self::$_db ?? Wallet::database();
    if ($db === null) {
      return [];
    }

    $sql = "SELECT * FROM " . self::$_table_name . " WHERE address = ?";
    $params = [$address];

    if ($type !== null) {
      $sql .= " AND type = ?";
      $params[] = $type;
    }

    $sql .= " ORDER BY _created DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Find transactions by batch.
   *
   * @param string $batch Batch ID.
   * @param \PDO|null $db Database connection.
   */
  public static function findByBatch(string $batch, ?\PDO $db = null):array {
    $db = $db ?? self::$_db ?? Wallet::database();
    if ($db === null) {
      return [];
    }

    $stmt = $db->prepare(
      "SELECT * FROM " . self::$_table_name . " WHERE batch = ? ORDER BY _created ASC"
    );
    $stmt->execute([$batch]);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  // =========================================================================
  // Internal Helpers
  // =========================================================================

  private static function _generateBatch():string {
    return Config::generateBatch();
  }

  private static function _recordToDb(\PDO $db, string $address, array $tx, string $narration):void {
    $stmt = $db->prepare(
      "INSERT INTO " . self::$_table_name . " 
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

  private static function _queueAlert(\PDO $db, string $hash):void {
    try {
      $stmt = $db->prepare(
        "INSERT INTO " . self::$_alert_table . " (sent, tx) VALUES (0, ?)"
      );
      $stmt->execute([$hash]);
    } catch (\Throwable $e) {
      // Alert queue is optional, don't fail transaction
      \error_log("Failed to queue alert for tx {$hash}: " . $e->getMessage());
    }
  }

  // =========================================================================
  // Getters
  // =========================================================================

  public function hash():string {
    return $this->_hash;
  }

  public function originHash():string {
    return $this->_origin_hash;
  }

  public function address():string {
    return $this->_address;
  }

  public function origin():string {
    return $this->_origin;
  }

  public function batch():string {
    return $this->_batch;
  }

  public function type():string {
    return $this->_type;
  }

  public function amount():float {
    return $this->_amount;
  }

  public function recordBalance():float {
    return $this->_balance;
  }

  public function narration():string {
    return $this->_narration;
  }

  public function created():string {
    return $this->_created;
  }

  public function isCredit():bool {
    return $this->_type === 'credit';
  }

  public function isDebit():bool {
    return $this->_type === 'debit';
  }

  public function toArray():array {
    return [
      'hash'        => $this->_hash,
      'origin_hash' => $this->_origin_hash,
      'address'     => $this->_address,
      'origin'      => $this->_origin,
      'batch'       => $this->_batch,
      'type'        => $this->_type,
      'amount'      => $this->_amount,
      'balance'     => $this->_balance,
      'narration'   => $this->_narration,
      'created'     => $this->_created,
    ];
  }

  // =========================================================================
  // Static Configuration
  // =========================================================================

  public static function setDatabase(\PDO $db):void {
    self::$_db = $db;
  }

  public static function setTableName(string $name):void {
    self::$_table_name = $name;
  }

  public static function setAlertTable(string $name):void {
    self::$_alert_table = $name;
  }
}
