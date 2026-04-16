<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet\Ledger;

use TimeFrontiers\Wallet\Exception\LedgerCorruptException;

/**
 * File-based ledger with rolling files and checkpoints.
 *
 * Structure:
 * ```
 * /.txhive/ledgers/{user}/
 *   ├── {address}.2024-01.ledger   # January archive
 *   ├── {address}.2024-02.ledger   # February archive
 *   ├── {address}.ledger           # Current active
 *   └── {address}.checksum         # Integrity checksums
 * ```
 *
 * File format:
 * ```
 * CURRENCY/>VWALLET/>VLEDGER/>CREATED/>CHECKSUM
 * TYPE/>BALANCE/>AMOUNT/>ORIGIN/>HASH/>ORIGIN_HASH/>BATCH/>CREATED
 * ...transactions...
 * #CHECKPOINT/>BALANCE/>TX_COUNT/>HASH/>TIMESTAMP
 * ...more transactions...
 * ```
 */
class FileLedger implements LedgerInterface {

  public const VERSION = '1.0';
  public const SEPARATOR = '/>';
  public const HEADER_COUNT = 5;
  public const TX_FIELD_COUNT = 8;
  public const CHECKPOINT_PREFIX = '#CHECKPOINT';
  public const CHECKPOINT_INTERVAL = 100; // Checkpoint every N transactions
  public const DATETIME_FORMAT = 'Y-m-d H:i:s';

  private string $_address;
  private string $_currency;
  private string $_user;
  private string $_base_dir;
  private string $_file;
  private string $_checksum_file;
  private string $_version;
  private ?string $_created = null;
  private ?string $_updated = null;

  private array $_totals = [
    'credit' => 0.0,
    'debit'  => 0.0,
  ];

  private array $_transactions = [];
  private array $_by_type = [
    'credit' => [],
    'debit'  => [],
  ];

  private ?array $_first = null;
  private ?array $_last = null;
  private int $_tx_since_checkpoint = 0;
  private ?array $_last_checkpoint = null;

  /**
   * @param string $address Wallet address.
   * @param string $currency Currency code.
   * @param string $user User identifier.
   * @param string $base_dir Base storage directory.
   */
  public function __construct(
    string $address,
    string $currency,
    string $user,
    string $base_dir
  ) {
    $this->_address = $address;
    $this->_currency = \strtoupper($currency);
    $this->_user = $user;
    $this->_base_dir = \rtrim($base_dir, '/');
    $this->_version = self::VERSION;

    $this->_initializeStorage();
  }

  private function _initializeStorage():void {
    $user_dir = "{$this->_base_dir}/{$this->_user}";

    if (!\file_exists($user_dir)) {
      if (!\mkdir($user_dir, 0700, true)) {
        throw new \RuntimeException("Failed to create ledger directory: {$user_dir}");
      }
    }

    $this->_file = "{$user_dir}/{$this->_address}.ledger";
    $this->_checksum_file = "{$user_dir}/{$this->_address}.checksum";

    if (!\file_exists($this->_file)) {
      $this->_createLedger();
    } else {
      $this->_readLedger();
    }
  }

  private function _createLedger():void {
    $this->_created = \date(self::DATETIME_FORMAT);
    $checksum = $this->_computeHeaderChecksum();

    $header = \implode(self::SEPARATOR, [
      $this->_currency,
      'V1.0',
      'V' . self::VERSION,
      $this->_created,
      $checksum,
    ]);

    if (\file_put_contents($this->_file, $header . PHP_EOL, LOCK_EX) === false) {
      throw new \RuntimeException("Failed to create ledger file: {$this->_file}");
    }

    $this->_writeChecksum($checksum);
    \chmod($this->_file, 0600);
  }

  private function _readLedger():void {
    $content = @\file_get_contents($this->_file);

    if ($content === false) {
      throw new LedgerCorruptException($this->_file, "Failed to read ledger file");
    }

    $lines = \preg_split("/(\r\n|\n|\r)/", $content, -1, PREG_SPLIT_NO_EMPTY);

    if (empty($lines)) {
      throw new LedgerCorruptException($this->_file, "Ledger file is empty");
    }

    // Parse header
    $header = \explode(self::SEPARATOR, $lines[0]);

    if (\count($header) < self::HEADER_COUNT) {
      throw new LedgerCorruptException($this->_file, "Invalid ledger header");
    }

    if (\trim($header[0]) !== $this->_currency) {
      throw new LedgerCorruptException(
        $this->_file,
        "Currency mismatch. Expected: {$this->_currency}, Found: " . \trim($header[0])
      );
    }

    $this->_version = \ltrim(\trim($header[2]), 'V');
    $this->_created = \trim($header[3]);

    // Verify header checksum
    $stored_checksum = \trim($header[4] ?? '');
    $computed_checksum = $this->_computeHeaderChecksum();

    if (!empty($stored_checksum) && $stored_checksum !== $computed_checksum) {
      // Check external checksum file
      $external_checksum = $this->_readChecksum();
      if ($external_checksum !== $stored_checksum) {
        throw new LedgerCorruptException(
          $this->_file,
          "Header checksum mismatch",
          $stored_checksum,
          $computed_checksum
        );
      }
    }

    // Parse transactions
    $tx_index = 0;
    for ($i = 1; $i < \count($lines); $i++) {
      $line = \trim($lines[$i]);

      if (empty($line)) {
        continue;
      }

      // Check for checkpoint
      if (\str_starts_with($line, self::CHECKPOINT_PREFIX)) {
        $this->_parseCheckpoint($line);
        $this->_tx_since_checkpoint = 0;
        continue;
      }

      $this->_parseTransaction($line, $tx_index);
      $tx_index++;
      $this->_tx_since_checkpoint++;
    }
  }

  private function _parseTransaction(string $line, int $index):void {
    $parts = \explode(self::SEPARATOR, $line);

    if (\count($parts) < self::TX_FIELD_COUNT) {
      return; // Skip invalid lines
    }

    $type = (int)\trim($parts[0]) === 1 ? 'credit' : 'debit';
    $hash = \trim($parts[4]);

    $tx = [
      'type'        => $type,
      'balance'     => (float)\trim($parts[1]),
      'amount'      => (float)\trim($parts[2]),
      'origin'      => \trim($parts[3]),
      'hash'        => $hash,
      'origin_hash' => \trim($parts[5]),
      'batch'       => \trim($parts[6]),
      'created'     => \trim($parts[7]),
    ];

    $this->_transactions[$hash] = $tx;
    $this->_by_type[$type][$hash] = $tx;
    $this->_totals[$type] += $tx['amount'];

    if ($index === 0) {
      $this->_first = ['hash' => $hash, 'data' => $tx];
    }

    $this->_last = ['hash' => $hash, 'data' => $tx];
    $this->_updated = $tx['created'];
  }

  private function _parseCheckpoint(string $line):void {
    $parts = \explode(self::SEPARATOR, $line);

    if (\count($parts) >= 5) {
      $this->_last_checkpoint = [
        'balance'   => (float)\trim($parts[1]),
        'tx_count'  => (int)\trim($parts[2]),
        'hash'      => \trim($parts[3]),
        'timestamp' => \trim($parts[4]),
      ];
    }
  }

  public function record(array $tx):bool {
    // Validate required fields
    $required = ['type', 'balance', 'amount', 'origin', 'hash', 'origin_hash', 'batch'];
    foreach ($required as $field) {
      if (!isset($tx[$field])) {
        throw new \InvalidArgumentException("Missing required field: {$field}");
      }
    }

    $type_int = $tx['type'] === 'credit' ? '1' : '0';
    $created = $tx['created'] ?? \date(self::DATETIME_FORMAT);

    $line = \implode(self::SEPARATOR, [
      $type_int,
      \number_format((float)$tx['balance'], 8, '.', ''),
      \number_format((float)$tx['amount'], 8, '.', ''),
      $tx['origin'],
      $tx['hash'],
      $tx['origin_hash'],
      $tx['batch'],
      $created,
    ]);

    // Write with exclusive lock
    $result = \file_put_contents($this->_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

    if ($result === false) {
      return false;
    }

    // Update in-memory state
    $tx['created'] = $created;
    $this->_transactions[$tx['hash']] = $tx;
    $this->_by_type[$tx['type']][$tx['hash']] = $tx;
    $this->_totals[$tx['type']] += (float)$tx['amount'];

    if ($this->_first === null) {
      $this->_first = ['hash' => $tx['hash'], 'data' => $tx];
    }

    $this->_last = ['hash' => $tx['hash'], 'data' => $tx];
    $this->_updated = $created;
    $this->_tx_since_checkpoint++;

    // Auto-checkpoint
    if ($this->_tx_since_checkpoint >= self::CHECKPOINT_INTERVAL) {
      $this->_writeCheckpoint();
    }

    // Check if we should roll the file (new month)
    $this->_checkRolling();

    return true;
  }

  private function _writeCheckpoint():void {
    $checkpoint = \implode(self::SEPARATOR, [
      self::CHECKPOINT_PREFIX,
      \number_format($this->balance(), 8, '.', ''),
      \count($this->_transactions),
      $this->_computeTransactionHash(),
      \date(self::DATETIME_FORMAT),
    ]);

    \file_put_contents($this->_file, $checkpoint . PHP_EOL, FILE_APPEND | LOCK_EX);
    $this->_tx_since_checkpoint = 0;
    $this->_updateChecksum();
  }

  private function _checkRolling():void {
    // Roll to new file on first transaction of new month
    $current_month = \date('Y-m');
    $file_month = null;

    if ($this->_created) {
      $file_month = \date('Y-m', \strtotime($this->_created));
    }

    if ($file_month && $file_month !== $current_month && \count($this->_transactions) > 0) {
      $this->_rollFile($file_month);
    }
  }

  private function _rollFile(string $month):void {
    $archive_file = \str_replace('.ledger', ".{$month}.ledger", $this->_file);

    // Write final checkpoint
    $this->_writeCheckpoint();

    // Copy current to archive
    if (!\copy($this->_file, $archive_file)) {
      return; // Silently fail, we can try again later
    }

    \chmod($archive_file, 0400); // Read-only archive

    // Create new ledger with carried-over balance
    $balance = $this->balance();
    $this->_transactions = [];
    $this->_by_type = ['credit' => [], 'debit' => []];
    $this->_totals = ['credit' => 0.0, 'debit' => 0.0];
    $this->_first = null;
    $this->_last = null;

    $this->_createLedger();

    // Record opening balance if non-zero
    if ($balance > 0) {
      $this->record([
        'type'        => 'credit',
        'balance'     => $balance,
        'amount'      => $balance,
        'origin'      => 'OPENING_BALANCE',
        'hash'        => \hash('sha256', $this->_address . $month . 'OPENING'),
        'origin_hash' => '#ROLLOVER',
        'batch'       => '#SYSTEM',
        'created'     => \date(self::DATETIME_FORMAT),
      ]);
    }
  }

  public function balance():float {
    return \round($this->_totals['credit'] - $this->_totals['debit'], 8);
  }

  public function getTransaction(string $hash):?array {
    return $this->_transactions[$hash] ?? null;
  }

  public function getTransactions(?string $type = null):array {
    if ($type !== null) {
      return $this->_by_type[$type] ?? [];
    }
    return $this->_transactions;
  }

  public function first():?array {
    return $this->_first;
  }

  public function last():?array {
    return $this->_last;
  }

  public function count(?string $type = null):int {
    if ($type !== null) {
      return \count($this->_by_type[$type] ?? []);
    }
    return \count($this->_transactions);
  }

  public function totals():array {
    return $this->_totals;
  }

  public function verify():bool {
    // Verify file exists
    if (!\file_exists($this->_file)) {
      return false;
    }

    // Verify checksum
    $stored = $this->_readChecksum();
    if ($stored && $stored !== $this->_computeFileChecksum()) {
      return false;
    }

    // Verify balance calculation
    $computed_balance = $this->_totals['credit'] - $this->_totals['debit'];
    $last = $this->_last;

    if ($last && isset($last['data']['balance'])) {
      // Last recorded balance should match computed
      $recorded_balance = (float)$last['data']['balance'];
      if (\abs($computed_balance - $recorded_balance) > 0.00000001) {
        return false;
      }
    }

    return true;
  }

  public function version():string {
    return $this->_version;
  }

  public function created():?string {
    return $this->_created;
  }

  public function updated():?string {
    return $this->_updated;
  }

  // =========================================================================
  // Checksum helpers
  // =========================================================================

  private function _computeHeaderChecksum():string {
    return \hash('sha256', $this->_address . $this->_currency . $this->_created);
  }

  private function _computeTransactionHash():string {
    $hashes = \array_keys($this->_transactions);
    return \hash('sha256', \implode('', $hashes));
  }

  private function _computeFileChecksum():string {
    return \hash_file('sha256', $this->_file);
  }

  private function _writeChecksum(string $checksum):void {
    \file_put_contents($this->_checksum_file, $checksum, LOCK_EX);
    \chmod($this->_checksum_file, 0600);
  }

  private function _readChecksum():?string {
    if (!\file_exists($this->_checksum_file)) {
      return null;
    }
    return \trim(\file_get_contents($this->_checksum_file));
  }

  private function _updateChecksum():void {
    $this->_writeChecksum($this->_computeFileChecksum());
  }

  // =========================================================================
  // Recovery
  // =========================================================================

  /**
   * Rebuild ledger from database transactions.
   *
   * Use when ledger is corrupted and DB is trusted.
   *
   * @param array $transactions Ordered list of transactions from DB.
   * @return bool True on success.
   */
  public function rebuild(array $transactions):bool {
    // Backup current file
    $backup = $this->_file . '.bak.' . \time();
    @\copy($this->_file, $backup);

    // Reset state
    $this->_transactions = [];
    $this->_by_type = ['credit' => [], 'debit' => []];
    $this->_totals = ['credit' => 0.0, 'debit' => 0.0];
    $this->_first = null;
    $this->_last = null;

    // Create fresh ledger
    $this->_createLedger();

    // Re-record all transactions
    foreach ($transactions as $tx) {
      if (!$this->record($tx)) {
        // Restore backup
        @\copy($backup, $this->_file);
        return false;
      }
    }

    // Final checkpoint
    $this->_writeCheckpoint();

    return true;
  }

  /**
   * Get archived ledger files.
   *
   * @return array List of archive files with dates.
   */
  public function archives():array {
    $dir = \dirname($this->_file);
    $pattern = "{$this->_address}.*.ledger";
    $files = \glob("{$dir}/{$pattern}");

    $archives = [];
    foreach ($files as $file) {
      if (\preg_match('/\.(\d{4}-\d{2})\.ledger$/', $file, $matches)) {
        $archives[] = [
          'file'  => $file,
          'month' => $matches[1],
        ];
      }
    }

    \usort($archives, fn($a, $b) => $a['month'] <=> $b['month']);

    return $archives;
  }

  /**
   * Get file path.
   */
  public function file():string {
    return $this->_file;
  }
}
