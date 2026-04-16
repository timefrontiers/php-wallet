<?php

declare(strict_types=1);

namespace TimeFrontiers\Wallet\Payment;

/**
 * Interface for payment verification.
 *
 * Implement this interface to integrate external payment systems
 * (Stripe, PayPal, bank transfers, etc.) with the wallet system.
 *
 * @example
 * ```php
 * class StripePaymentVerifier implements PaymentVerifierInterface {
 *   public function verify(string $reference, float $amount, string $currency): bool {
 *     $payment = \Stripe\PaymentIntent::retrieve($reference);
 *     return $payment->status === 'succeeded'
 *         && $payment->amount >= $amount * 100
 *         && $payment->currency === strtolower($currency);
 *   }
 * }
 * ```
 */
interface PaymentVerifierInterface {

  /**
   * Verify a payment exists and has sufficient balance.
   *
   * @param string $reference Payment reference/ID.
   * @param float $amount Amount to verify.
   * @param string $currency Currency code.
   * @return bool True if payment is valid and has sufficient funds.
   */
  public function verify(string $reference, float $amount, string $currency):bool;

  /**
   * Get available balance from a payment.
   *
   * This is the amount that can still be credited to wallets.
   *
   * @param string $reference Payment reference/ID.
   * @return float Available balance.
   */
  public function availableBalance(string $reference):float;

  /**
   * Mark an amount as spent/claimed from a payment.
   *
   * Called after successful wallet credit to prevent double-spending.
   *
   * @param string $reference Payment reference/ID.
   * @param float $amount Amount being claimed.
   * @param string $tx_hash Transaction hash for audit trail.
   * @return bool True on success.
   */
  public function markSpent(string $reference, float $amount, string $tx_hash):bool;

  /**
   * Get payment details.
   *
   * @param string $reference Payment reference/ID.
   * @return array|null Payment details or null if not found.
   */
  public function getPayment(string $reference):?array;

  /**
   * Check if a payment reference is valid format.
   *
   * @param string $reference Payment reference/ID.
   * @return bool True if format is valid.
   */
  public function isValidReference(string $reference):bool;
}
