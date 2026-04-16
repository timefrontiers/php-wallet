<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet\Payment;

/**
 * Null payment verifier that always fails.
 *
 * Use this as a placeholder when no payment system is configured.
 * For testing, use MockPaymentVerifier instead.
 */
class NullPaymentVerifier implements PaymentVerifierInterface {

  public function verify(string $reference, float $amount, string $currency):bool {
    return false;
  }

  public function availableBalance(string $reference):float {
    return 0.0;
  }

  public function markSpent(string $reference, float $amount, string $tx_hash):bool {
    return false;
  }

  public function getPayment(string $reference):?array {
    return null;
  }

  public function isValidReference(string $reference):bool {
    return false;
  }
}

/**
 * Mock payment verifier for testing.
 *
 * Allows adding fake payments for testing wallet credits from external sources.
 */
class MockPaymentVerifier implements PaymentVerifierInterface {

  private array $_payments = [];
  private array $_spent = [];

  /**
   * Add a mock payment.
   */
  public function addPayment(
    string $reference,
    float $amount,
    string $currency,
    string $status = 'paid'
  ):self {
    $this->_payments[$reference] = [
      'reference' => $reference,
      'amount'    => $amount,
      'currency'  => $currency,
      'status'    => $status,
      'created'   => \date('Y-m-d H:i:s'),
    ];
    return $this;
  }

  public function verify(string $reference, float $amount, string $currency):bool {
    if (!isset($this->_payments[$reference])) {
      return false;
    }

    $payment = $this->_payments[$reference];

    if ($payment['status'] !== 'paid') {
      return false;
    }

    if (\strtoupper($payment['currency']) !== \strtoupper($currency)) {
      return false;
    }

    return $this->availableBalance($reference) >= $amount;
  }

  public function availableBalance(string $reference):float {
    if (!isset($this->_payments[$reference])) {
      return 0.0;
    }

    $total = $this->_payments[$reference]['amount'];
    $spent = $this->_spent[$reference] ?? 0.0;

    return \max(0.0, $total - $spent);
  }

  public function markSpent(string $reference, float $amount, string $tx_hash):bool {
    if (!isset($this->_payments[$reference])) {
      return false;
    }

    if ($this->availableBalance($reference) < $amount) {
      return false;
    }

    $this->_spent[$reference] = ($this->_spent[$reference] ?? 0.0) + $amount;
    return true;
  }

  public function getPayment(string $reference):?array {
    return $this->_payments[$reference] ?? null;
  }

  public function isValidReference(string $reference):bool {
    return !empty($reference) && \strlen($reference) >= 8;
  }

  /**
   * Clear all mock payments.
   */
  public function clear():self {
    $this->_payments = [];
    $this->_spent = [];
    return $this;
  }
}
