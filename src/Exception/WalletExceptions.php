<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet\Exception;

/**
 * Base wallet exception.
 */
class WalletException extends \Exception {
}

/**
 * Thrown when wallet balance is insufficient.
 */
class InsufficientBalanceException extends WalletException {

  private float $_required;
  private float $_available;
  private string $_currency;

  public function __construct(
    float $required,
    float $available,
    string $currency = '',
    string $message = ''
  ) {
    $this->_required = $required;
    $this->_available = $available;
    $this->_currency = $currency;

    if (empty($message)) {
      $message = "Insufficient balance. Required: {$required}, Available: {$available}";
      if ($currency) {
        $message .= " {$currency}";
      }
    }

    parent::__construct($message, 402);
  }

  public function required():float {
    return $this->_required;
  }

  public function available():float {
    return $this->_available;
  }

  public function currency():string {
    return $this->_currency;
  }

  public function shortfall():float {
    return $this->_required - $this->_available;
  }
}

/**
 * Thrown when ledger file is corrupted or invalid.
 */
class LedgerCorruptException extends WalletException {

  private string $_file;
  private ?string $_expected_hash;
  private ?string $_actual_hash;

  public function __construct(
    string $file,
    string $message = '',
    ?string $expected_hash = null,
    ?string $actual_hash = null
  ) {
    $this->_file = $file;
    $this->_expected_hash = $expected_hash;
    $this->_actual_hash = $actual_hash;

    if (empty($message)) {
      $message = "Ledger file corrupted: {$file}";
    }

    parent::__construct($message, 500);
  }

  public function file():string {
    return $this->_file;
  }

  public function expectedHash():?string {
    return $this->_expected_hash;
  }

  public function actualHash():?string {
    return $this->_actual_hash;
  }
}

/**
 * Thrown when ledger and database are out of sync.
 */
class IntegrityException extends WalletException {

  private float $_ledger_balance;
  private float $_db_balance;
  private string $_address;

  public function __construct(
    string $address,
    float $ledger_balance,
    float $db_balance,
    string $message = ''
  ) {
    $this->_address = $address;
    $this->_ledger_balance = $ledger_balance;
    $this->_db_balance = $db_balance;

    if (empty($message)) {
      $message = "Integrity mismatch for {$address}. Ledger: {$ledger_balance}, DB: {$db_balance}";
    }

    parent::__construct($message, 500);
  }

  public function address():string {
    return $this->_address;
  }

  public function ledgerBalance():float {
    return $this->_ledger_balance;
  }

  public function dbBalance():float {
    return $this->_db_balance;
  }

  public function difference():float {
    return \abs($this->_ledger_balance - $this->_db_balance);
  }
}

/**
 * Thrown when transaction creation fails.
 */
class TransactionException extends WalletException {
}

/**
 * Thrown when payment verification fails.
 */
class PaymentVerificationException extends WalletException {

  private string $_reference;

  public function __construct(string $reference, string $message = '') {
    $this->_reference = $reference;

    if (empty($message)) {
      $message = "Payment verification failed for: {$reference}";
    }

    parent::__construct($message, 402);
  }

  public function reference():string {
    return $this->_reference;
  }
}

/**
 * Thrown when wallet is not found.
 */
class WalletNotFoundException extends WalletException {

  public function __construct(string $identifier, string $message = '') {
    if (empty($message)) {
      $message = "Wallet not found: {$identifier}";
    }
    parent::__construct($message, 404);
  }
}

/**
 * Thrown when operation is not permitted.
 */
class OperationNotPermittedException extends WalletException {

  public function __construct(string $operation, string $message = '') {
    if (empty($message)) {
      $message = "Operation not permitted: {$operation}";
    }
    parent::__construct($message, 403);
  }
}
