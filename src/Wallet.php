<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet;

use TimeFrontiers\Wallet\Ledger\LedgerInterface;
use TimeFrontiers\Wallet\Ledger\FileLedger;
use TimeFrontiers\Wallet\Exception\{
  WalletException,
  WalletNotFoundException,
  IntegrityException
};

/**
 * Wallet - manages a single-currency wallet.
 *
 * Each wallet:
 * - Belongs to one user
 * - Holds one currency
 * - Has an immutable ledger file as source of truth
 * - May have a database record for querying
 *
 * @example
 * ```php
 * // Initialize with database credentials
 * $db_cred = [
 *   'server'   => 'localhost',
 *   'username' => 'db_user',
 *   'password' => 'db_pass',
 *   'database' => 'myprefix_wallet',
 * ];
 *
 * // Create or find wallet
 * $wallet = new Wallet('USR123456', 'NGN', $db_cred);
 *
 * // Or with existing PDO connection
 * $wallet = new Wallet('USR123456', 'NGN', $pdo);
 *
 * // Check balance
 * echo $wallet->balance(); // 1500.00
 * ```
 */
class Wallet {

  public const VERSION = '1.0';

  private string $_address;
  private string $_user;
  private string $_currency;
  private string $_created;
  private LedgerInterface $_ledger;
  private \PDO $_db;

  private static ?\PDO $_shared_db = null;
  private static string $_table_name = 'wallets';

  /**
   * Create or load a wallet.
   *
   * @param string $identifier Wallet address OR user code.
   * @param string $currency Currency code (required if creating by user).
   * @param \PDO|array|null $database PDO instance or credentials array.
   *   Array format: ['server' => '', 'username' => '', 'password' => '', 'database' => '']
   * @throws WalletNotFoundException If wallet not found.
   * @throws WalletException On other errors.
   */
  public function __construct(
    string $identifier,
    string $currency = '',
    \PDO|array|null $database = null
  ) {
    $currency = \strtoupper(\trim($currency));

    // Initialize database connection
    $this->_initDatabase($database);

    // Check if identifier is a wallet address
    if (Config::isValidAddress($identifier)) {
      $this->_loadByAddress($identifier);
    } else {
      // Identifier is a user code
      if (empty($currency)) {
        throw new \InvalidArgumentException("Currency required when loading by user");
      }
      $this->_loadOrCreateByUser($identifier, $currency);
    }

    // Initialize ledger
    $this->_ledger = new FileLedger(
      $this->_address,
      $this->_currency,
      $this->_user,
      Config::ledgerPath()
    );
  }

  /**
   * Initialize database connection.
   *
   * @param \PDO|array|null $database PDO instance or credentials array.
   */
  private function _initDatabase(\PDO|array|null $database):void {
    if ($database instanceof \PDO) {
      $this->_db = $database;
    } elseif (\is_array($database)) {
      $this->_db = self::_createConnection($database);
    } elseif (self::$_shared_db !== null) {
      $this->_db = self::$_shared_db;
    } else {
      throw new WalletException(
        "Database connection required. Pass PDO instance or credentials array."
      );
    }
  }

  /**
   * Create PDO connection from credentials.
   *
   * @param array $cred Credentials: server, username, password, database.
   * @return \PDO
   */
  private static function _createConnection(array $cred):\PDO {
    $required = ['server', 'username', 'password', 'database'];
    foreach ($required as $key) {
      if (!isset($cred[$key])) {
        throw new \InvalidArgumentException("Missing database credential: {$key}");
      }
    }

    $dsn = "mysql:host={$cred['server']};dbname={$cred['database']};charset=utf8mb4";

    $options = [
      \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    return new \PDO($dsn, $cred['username'], $cred['password'], $options);
  }

  private function _loadByAddress(string $address):void {
    $stmt = $this->_db->prepare(
      "SELECT * FROM " . self::$_table_name . " WHERE address = ? LIMIT 1"
    );
    $stmt->execute([$address]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
      throw new WalletNotFoundException($address);
    }

    $this->_address = $row['address'];
    $this->_user = $row['user'];
    $this->_currency = $row['currency'];
    $this->_created = $row['_created'] ?? \date(Config::dateTimeFormat());
  }

  private function _loadOrCreateByUser(string $user, string $currency):void {
    $stmt = $this->_db->prepare(
      "SELECT * FROM " . self::$_table_name . " 
       WHERE user = ? AND currency = ? LIMIT 1"
    );
    $stmt->execute([$user, $currency]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($row) {
      $this->_address = $row['address'];
      $this->_user = $row['user'];
      $this->_currency = $row['currency'];
      $this->_created = $row['_created'] ?? \date(Config::dateTimeFormat());
      return;
    }

    // Create new wallet
    $this->_user = $user;
    $this->_currency = $currency;
    $this->_address = $this->_generateUniqueAddress();
    $this->_created = \date(Config::dateTimeFormat());

    $this->_insertToDb();
  }

  private function _generateUniqueAddress():string {
    $attempts = 0;
    $max_attempts = 100;

    while ($attempts < $max_attempts) {
      $address = Config::generateAddress();

      // Check uniqueness
      $stmt = $this->_db->prepare(
        "SELECT 1 FROM " . self::$_table_name . " WHERE address = ? LIMIT 1"
      );
      $stmt->execute([$address]);

      if (!$stmt->fetch()) {
        return $address;
      }

      $attempts++;
    }

    throw new WalletException("Failed to generate unique address after {$max_attempts} attempts");
  }

  private function _insertToDb():void {
    $stmt = $this->_db->prepare(
      "INSERT INTO " . self::$_table_name . " (address, user, currency, _created) 
       VALUES (?, ?, ?, ?)"
    );

    $stmt->execute([
      $this->_address,
      $this->_user,
      $this->_currency,
      $this->_created,
    ]);
  }

  // =========================================================================
  // Balance
  // =========================================================================

  /**
   * Get wallet balance.
   *
   * Returns ledger balance (source of truth).
   * Optionally verifies against database.
   *
   * @param bool $verify Verify against DB.
   * @return float Balance.
   * @throws IntegrityException If verification fails.
   */
  public function balance(bool $verify = false):float {
    $ledger_balance = Config::roundAmount($this->_ledger->balance());

    if ($verify) {
      $db_balance = $this->_getDbBalance();

      if (\abs($ledger_balance - $db_balance) > 0.00000001) {
        if (Config::strictIntegrity()) {
          throw new IntegrityException(
            $this->_address,
            $ledger_balance,
            $db_balance
          );
        }
        // Log warning but trust ledger
        \error_log("Wallet integrity warning: {$this->_address} ledger={$ledger_balance} db={$db_balance}");
      }
    }

    return $ledger_balance;
  }

  private function _getDbBalance():float {
    $stmt = $this->_db->prepare(
      "SELECT 
        COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as balance
       FROM wallet_history 
       WHERE address = ?"
    );
    $stmt->execute([$this->_address]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return Config::roundAmount((float)($row['balance'] ?? 0));
  }

  /**
   * Check if wallet has sufficient balance.
   */
  public function hasSufficientBalance(float $amount):bool {
    return $this->balance() >= Config::roundAmount(\abs($amount));
  }

  // =========================================================================
  // Getters
  // =========================================================================

  public function address():string {
    return $this->_address;
  }

  public function user():string {
    return $this->_user;
  }

  public function currency():string {
    return $this->_currency;
  }

  public function created():string {
    return $this->_created;
  }

  public function ledger():LedgerInterface {
    return $this->_ledger;
  }

  /**
   * Get database connection for this wallet.
   */
  public function db():\PDO {
    return $this->_db;
  }

  // =========================================================================
  // Static Configuration
  // =========================================================================

  /**
   * Set shared database connection.
   *
   * Used when not passing credentials to constructor.
   *
   * @param \PDO|array $database PDO instance or credentials array.
   */
  public static function setDatabase(\PDO|array $database):void {
    if ($database instanceof \PDO) {
      self::$_shared_db = $database;
    } else {
      self::$_shared_db = self::_createConnection($database);
    }
  }

  /**
   * Get shared database connection.
   */
  public static function database():?\PDO {
    return self::$_shared_db;
  }

  /**
   * Set table name.
   */
  public static function setTableName(string $name):void {
    self::$_table_name = $name;
  }

  /**
   * Check if wallet exists.
   *
   * @param string $address Wallet address.
   * @param \PDO|null $db Database connection.
   */
  public static function exists(string $address, ?\PDO $db = null):bool {
    if (!Config::isValidAddress($address)) {
      return false;
    }

    $db = $db ?? self::$_shared_db;
    if ($db === null) {
      return false;
    }

    $stmt = $db->prepare(
      "SELECT 1 FROM " . self::$_table_name . " WHERE address = ? LIMIT 1"
    );
    $stmt->execute([$address]);
    return (bool)$stmt->fetch();
  }

  /**
   * Find wallets by user.
   *
   * @param string $user User code.
   * @param \PDO|null $db Database connection.
   * @return array List of wallet info.
   */
  public static function findByUser(string $user, ?\PDO $db = null):array {
    $db = $db ?? self::$_shared_db;
    if ($db === null) {
      return [];
    }

    $stmt = $db->prepare(
      "SELECT address, currency, _created FROM " . self::$_table_name . " 
       WHERE user = ? ORDER BY _created ASC"
    );
    $stmt->execute([$user]);

    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
  }

  // =========================================================================
  // Serialization
  // =========================================================================

  /**
   * Convert to array.
   */
  public function toArray():array {
    return [
      'address'  => $this->_address,
      'user'     => $this->_user,
      'currency' => $this->_currency,
      'balance'  => $this->balance(),
      'created'  => $this->_created,
    ];
  }

  /**
   * JSON serialization.
   */
  public function __toString():string {
    return \json_encode($this->toArray());
  }
}
