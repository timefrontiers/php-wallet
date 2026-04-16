# TimeFrontiers PHP Wallet

Blockchain-like immutable wallet and transaction system with file-based ledger.

## Features

- **Immutable Ledger**: Append-only file-based ledger as source of truth
- **Rolling Files**: Monthly ledger archives prevent file bloat
- **Checkpointing**: Periodic snapshots for fast recovery
- **Integrity Verification**: Checksums detect tampering
- **Batch Transactions**: Efficient bulk transfers
- **Payment Integration**: Interface-based external payment verification
- **Flexible Database**: Pass credentials at initialization

## Installation

```bash
composer require timefrontiers/php-wallet
```

## Quick Start

```php
use TimeFrontiers\Wallet\{Config, Wallet, Transaction};

// Database credentials
$db_cred = [
  'server'   => 'localhost',
  'username' => 'wallet_user',
  'password' => 'secure_password',
  'database' => 'myapp_wallet',  // Your database name (with any prefix)
];

// Configure ledger path
Config::setLedgerPath('/var/data/.txhive/ledgers');

// Create/load wallets with credentials
$alice = new Wallet('USR123456', 'NGN', $db_cred);
$bob = new Wallet('USR789012', 'NGN', $db_cred);

// Check balance
echo $alice->balance(); // 5000.00

// Transfer
$hashes = Transaction::transfer($alice, $bob, 1000.00, 'Payment for services');

// Batch transfer
$result = Transaction::batch($alice)
  ->credit($bob, 500, 'Bonus')
  ->credit('219555555555555', 200, 'Refund')
  ->execute();
```

## Database Setup

### 1. Create Your Database

Create your database manually with any required prefix:

```sql
CREATE DATABASE myprefix_wallet;
```

### 2. Run the Schema

The schema file is included in the package at `schema/schema.sql`:

```bash
mysql -u root -p myprefix_wallet < vendor/timefrontiers/php-wallet/schema/schema.sql
```

Or run directly in MySQL:

```sql
USE myprefix_wallet;
SOURCE /path/to/vendor/timefrontiers/php-wallet/schema/schema.sql;
```

### Schema Tables

| Table | Purpose |
|-------|---------|
| `wallets` | Wallet addresses and ownership |
| `wallet_history` | Immutable transaction log (triggers prevent UPDATE/DELETE) |
| `tranx_alert` | Notification queue |
| `wallet_batches` | Batch transaction metadata (optional) |
| `exchange_rates` | Dynamic rate history (optional) |
| `ledger_integrity` | File checksum tracking (optional) |
| `balance_snapshots` | Daily balance history (optional) |

### Schema Views

| View | Purpose |
|------|---------|
| `v_wallet_balances` | Quick balance lookup |
| `v_recent_transactions` | Last 1000 transactions |
| `v_pending_alerts` | Unsent notifications |

## Database Connection

### Option 1: Pass Credentials to Constructor

```php
$db_cred = [
  'server'   => 'localhost',
  'username' => 'db_user',
  'password' => 'db_pass',
  'database' => 'myprefix_wallet',
];

$wallet = new Wallet('USR123456', 'NGN', $db_cred);
```

### Option 2: Pass PDO Instance

```php
$pdo = new PDO('mysql:host=localhost;dbname=myprefix_wallet', 'user', 'pass');

$wallet = new Wallet('USR123456', 'NGN', $pdo);
```

### Option 3: Set Shared Connection

```php
// Set once at application startup
Wallet::setDatabase([
  'server'   => 'localhost',
  'username' => 'db_user',
  'password' => 'db_pass',
  'database' => 'myprefix_wallet',
]);

// Now credentials are optional
$wallet = new Wallet('USR123456', 'NGN');
```

### Using Wallet's Connection

Each wallet instance has its own db connection:

```php
$wallet = new Wallet('USR123456', 'NGN', $db_cred);

// Access the connection
$db = $wallet->db();

// Transaction methods use wallet's connection automatically
Transaction::transfer($from, $to, 100, 'Payment');
```

## Configuration

```php
use TimeFrontiers\Wallet\Config;

// Ledger storage path
Config::setLedgerPath('/var/data/.txhive/ledgers');

// Decimal precision (default: 8)
Config::setPrecision(8);

// Wallet address format (default: prefix=219, length=15)
Config::setAddressPrefix('219');
Config::setAddressLength(15);

// Batch ID format (default: prefix=127, length=15)
Config::setBatchPrefix('127');
Config::setBatchLength(15);

// Strict integrity mode (fail on ledger/DB mismatch)
Config::setStrictIntegrity(true);

// Payment verifier
Config::setPaymentVerifier(new MyPaymentVerifier());

// Exchange rates
Config::setExchangeProvider(new FixedRateProvider([
  'NGN:DWL' => 1.0,
  'USD:NGN' => 1550.0,
]));
```

## Code Generation

Codes are generated with configurable prefix and length:

```php
// Wallet address: 219 + 12 random digits = 219123456789012
Config::setAddressPrefix('219');
Config::setAddressLength(15);
$address = Config::generateAddress();

// Batch ID: 127 + 12 random digits = 127987654321098
Config::setBatchPrefix('127');
Config::setBatchLength(15);
$batch = Config::generateBatch();

// Generic code generation
$code = Config::generateCode('PRE', 18); // PRE + 15 digits

// Validation
Config::isValidAddress('219123456789012'); // true
Config::isValidBatch('127987654321098');   // true
```

## Wallet Operations

### Create/Load Wallet

```php
// By user + currency (creates if not exists)
$wallet = new Wallet('USR123456', 'NGN', $db_cred);

// By address (must exist)
$wallet = new Wallet('219123456789012', '', $db_cred);

// Check existence
Wallet::exists('219123456789012', $pdo);

// Find user's wallets
$wallets = Wallet::findByUser('USR123456', $pdo);
```

### Balance

```php
// Get balance (from ledger - source of truth)
$balance = $wallet->balance();

// Verify against database (throws IntegrityException on mismatch)
$balance = $wallet->balance(verify: true);

// Check sufficient funds
$wallet->hasSufficientBalance(1000.00); // bool
```

## Transactions

### Transfer Between Wallets

```php
$hashes = Transaction::transfer($from, $to, 1000.00, 'Payment');
// Returns: ['credit_hash', 'debit_hash']
```

### Credit from External Payment

```php
use TimeFrontiers\Wallet\Payment\MockPaymentVerifier;

$verifier = new MockPaymentVerifier();
$verifier->addPayment('PAY123456', 5000.00, 'NGN', 'paid');

$hash = Transaction::creditFromPayment(
  $wallet,
  'PAY123456',
  5000.00,
  'Top-up via Stripe',
  $verifier
);
```

### Batch Transfers

```php
$result = Transaction::batch($source_wallet)
  ->credit('219111111111111', 100.00, 'Bonus')
  ->credit('219222222222222', 50.00, 'Refund')
  ->credit('219333333333333', 25.00, 'Cashback')
  ->execute();

// Result
$result->batchId();        // '127123456789012'
$result->totalAmount();    // 175.00
$result->creditCount();    // 3
$result->hashes();         // All tx hashes
$result->creditHashes();   // Credit hashes only
$result->debitHash();      // Single debit hash
```

### Bulk Credits

```php
$result = Transaction::batch($source)
  ->creditMany([
    ['address' => '219111111111111', 'amount' => 100, 'narration' => 'Bonus'],
    ['address' => '219222222222222', 'amount' => 50, 'narration' => 'Refund'],
  ])
  ->execute();
```

### Query Transactions

```php
// Find by hash
$tx = Transaction::find('abc123...', $pdo);

// Find by address
$txs = Transaction::findByAddress('219123456789012', type: 'credit', limit: 50, db: $pdo);

// Find by batch
$txs = Transaction::findByBatch('127123456789012', $pdo);
```

## Ledger System

### File Structure

```
/.txhive/ledgers/USR123456/
  ├── 219123456789012.ledger           # Current active
  ├── 219123456789012.2024-01.ledger   # January archive
  ├── 219123456789012.2024-02.ledger   # February archive
  └── 219123456789012.checksum         # Integrity hash
```

### Direct Ledger Access

```php
$ledger = $wallet->ledger();

// Read operations
$ledger->balance();
$ledger->count();
$ledger->count('credit');
$ledger->totals();
$ledger->first();
$ledger->last();
$ledger->getTransaction('abc123');
$ledger->getTransactions('credit');

// Verification
$ledger->verify();

// Archives
$ledger->archives();
```

### Recovery

```php
// Rebuild ledger from database
$transactions = Transaction::findByAddress($address, db: $pdo);
$wallet->ledger()->rebuild($transactions);
```

## Payment Integration

### Implement Custom Verifier

```php
use TimeFrontiers\Wallet\Payment\PaymentVerifierInterface;

class StripePaymentVerifier implements PaymentVerifierInterface {

  public function verify(string $reference, float $amount, string $currency): bool {
    $payment = \Stripe\PaymentIntent::retrieve($reference);
    
    return $payment->status === 'succeeded'
        && $payment->amount >= $amount * 100
        && strtoupper($payment->currency) === $currency;
  }

  public function availableBalance(string $reference): float {
    // Return available balance
  }

  public function markSpent(string $reference, float $amount, string $tx_hash): bool {
    // Record the claim
  }

  public function getPayment(string $reference): ?array {
    // Return payment details
  }

  public function isValidReference(string $reference): bool {
    return preg_match('/^pi_[a-zA-Z0-9]+$/', $reference);
  }
}
```

## Error Handling

```php
use TimeFrontiers\Wallet\Exception\{
  WalletException,
  WalletNotFoundException,
  InsufficientBalanceException,
  IntegrityException,
  PaymentVerificationException,
  TransactionException
};

try {
  Transaction::transfer($from, $to, 1000000, 'Big payment');
} catch (InsufficientBalanceException $e) {
  echo "Need {$e->required()}, have {$e->available()}";
  echo "Shortfall: {$e->shortfall()}";
} catch (IntegrityException $e) {
  echo "Ledger: {$e->ledgerBalance()}, DB: {$e->dbBalance()}";
} catch (PaymentVerificationException $e) {
  echo "Payment {$e->reference()} failed";
}
```

## Security

### Why File Ledger is More Secure

| Aspect | File Ledger | Database |
|--------|-------------|----------|
| Attack Surface | Server access only | SQL injection, leaked creds |
| Remote Tampering | Requires server access | Multiple vectors |
| Access Control | OS permissions | App + DB users |
| Detection | Checksums | Requires audit logs |

### Best Practices

1. **Store ledgers outside web root**: `/var/data/.txhive/`
2. **Restrict file permissions**: `chmod 600` on ledger files
3. **Use strict integrity mode**: Fail on mismatch
4. **Regular backups**: Archive ledger files
5. **Monitor alerts table**: Process pending notifications

## License

[MIT License](LICENSE)
