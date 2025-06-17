# Laravel QR Payment Package

A comprehensive Laravel package for QR code-based payment processing with real-time notifications, supporting both customer and merchant workflows.

## Features

- 🔗 **QR Code Generation**: Secure, time-limited QR codes for payment sessions
- 💳 **Payment Processing**: Complete transaction lifecycle management
- 📱 **Real-time Notifications**: WebSocket-based live updates
- 🔐 **Security**: Cryptographic session tokens and timeout protection
- 🏪 **Dual Interface**: Separate APIs for customers and merchants
- ⚡ **Performance**: <200ms API responses, <2s QR validation
- 🧪 **Test Coverage**: 100% test coverage with TDD methodology

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x
- Redis (for session caching)
- Broadcasting driver (Pusher, Socket.io, etc.)

## Installation

### 1. Install via Composer

```bash
composer require xavierau/laravel-qr-payment
```

### 2. Publish Configuration and Assets

```bash
# Publish config file
php artisan vendor:publish --provider="XavierAu\LaravelQrPayment\LaravelQrPaymentServiceProvider" --tag="config"

# Publish migrations
php artisan vendor:publish --provider="XavierAu\LaravelQrPayment\LaravelQrPaymentServiceProvider" --tag="migrations"

# Publish views (optional - for customization)
php artisan vendor:publish --provider="XavierAu\LaravelQrPayment\LaravelQrPaymentServiceProvider" --tag="views"
```

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Configure Broadcasting

Update your `.env` file:

```env
# Broadcasting Configuration
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1

# QR Payment Configuration
QR_PAYMENT_ENCRYPTION_KEY=your-32-character-encryption-key
QR_PAYMENT_SESSION_TIMEOUT=2
QR_PAYMENT_QR_EXPIRY=5
```

## Configuration

The configuration file `config/qr-payment.php` allows you to customize:

```php
return [
    'qr_code' => [
        'expiry_minutes' => env('QR_PAYMENT_QR_EXPIRY', 5),
        'size' => 300,
        'format' => 'svg', // svg, png
    ],
    
    'session' => [
        'timeout_minutes' => env('QR_PAYMENT_SESSION_TIMEOUT', 2),
        'cleanup_frequency' => 'hourly',
    ],
    
    'security' => [
        'encryption_key' => env('QR_PAYMENT_ENCRYPTION_KEY'),
    ],
    
    'broadcasting' => [
        'enabled' => true,
        'connection' => 'pusher',
    ],
    
    'fees' => [
        'percentage' => 2.5,
        'fixed' => 0.30,
    ],
];
```

## Usage

### Customer Workflow

#### 1. Generate QR Code

```php
use XavierAu\LaravelQrPayment\Contracts\QrCodeServiceInterface;

class PaymentController extends Controller
{
    public function generateQr(QrCodeServiceInterface $qrService)
    {
        $qrData = $qrService->generatePaymentQrCode(
            customerId: 'customer-123',
            options: [
                'currency' => 'USD',
                'size' => 400,
                'format' => 'svg'
            ]
        );
        
        return view('payment.qr-code', [
            'qrCode' => $qrData['qr_code'],
            'sessionId' => $qrData['session_id'],
            'expiresAt' => $qrData['expires_at']
        ]);
    }
}
```

#### 2. Monitor Payment Status

```javascript
// JavaScript for real-time updates
import Echo from 'laravel-echo';

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: process.env.MIX_PUSHER_APP_KEY,
    cluster: process.env.MIX_PUSHER_APP_CLUSTER,
});

// Listen for payment updates
Echo.private(`customer.${customerId}`)
    .listen('.payment.confirmation.requested', (e) => {
        showPaymentConfirmation(e);
    })
    .listen('.payment.completed', (e) => {
        showPaymentSuccess(e);
    });
```

#### 3. Confirm Payment

```php
use XavierAu\LaravelQrPayment\Contracts\TransactionServiceInterface;

public function confirmPayment(TransactionServiceInterface $transactionService, $transactionId)
{
    $result = $transactionService->confirmTransaction(
        transactionId: $transactionId,
        authMethod: 'biometric', // biometric, pin, face_id
        authData: [
            'fingerprint_hash' => 'secure_hash_value'
        ]
    );
    
    return response()->json($result);
}
```

### Merchant Workflow

#### 1. Scan QR Code

```php
use XavierAu\LaravelQrPayment\Contracts\PaymentSessionServiceInterface;

class MerchantController extends Controller
{
    public function scanQr(PaymentSessionServiceInterface $sessionService, Request $request)
    {
        $qrData = $request->input('qr_data');
        $sessionData = json_decode($qrData, true);
        
        $session = $sessionService->getSession($sessionData['session_id']);
        
        if (!$session || !$sessionService->isSessionActive($sessionData['session_id'])) {
            return response()->json(['error' => 'Invalid or expired QR code'], 400);
        }
        
        return view('merchant.payment-form', [
            'session' => $session,
            'customer' => $session->customer_id
        ]);
    }
}
```

#### 2. Process Payment

```php
use XavierAu\LaravelQrPayment\Contracts\TransactionServiceInterface;

public function processPayment(TransactionServiceInterface $transactionService, Request $request)
{
    $transaction = $transactionService->processPayment(
        sessionId: $request->input('session_id'),
        customerId: $request->input('customer_id'),
        merchantId: $request->input('merchant_id'),
        amount: $request->input('amount'),
        metadata: [
            'currency' => 'USD',
            'merchant_info' => [
                'name' => 'Coffee Shop',
                'location' => 'Downtown'
            ],
            'transaction_details' => [
                'items' => $request->input('items', []),
                'description' => $request->input('description'),
                'tip_amount' => $request->input('tip_amount', 0)
            ]
        ]
    );
    
    return response()->json([
        'transaction_id' => $transaction->transaction_id,
        'amount' => $transaction->amount,
        'status' => $transaction->status
    ]);
}
```

## Frontend Integration

### Customer Views

Create customer payment views in `resources/views/payment/`:

#### QR Code Display (`qr-code.blade.php`)

```blade
@extends('layouts.app')

@section('content')
<div class="payment-container">
    <div class="qr-code-section">
        <h2>Scan to Pay</h2>
        <div class="qr-display">
            {!! $qrCode !!}
        </div>
        <p class="expiry-info">QR Code expires at: {{ $expiresAt }}</p>
    </div>
    
    <div class="payment-status" id="payment-status">
        <p>Waiting for merchant to scan...</p>
        <div class="spinner"></div>
    </div>
</div>

<script>
// Real-time payment monitoring
window.Echo.private('customer.{{ auth()->id() }}')
    .listen('.payment.confirmation.requested', function(e) {
        showConfirmationModal(e);
    })
    .listen('.payment.completed', function(e) {
        showSuccessMessage(e);
        window.location.href = '/payment/success';
    });

function showConfirmationModal(data) {
    const modal = document.getElementById('confirmation-modal');
    document.getElementById('payment-amount').textContent = '$' + data.amount;
    document.getElementById('merchant-name').textContent = data.merchant_info.name;
    modal.style.display = 'block';
}
</script>
@endsection
```

#### Payment Confirmation Modal

```blade
<!-- Confirmation Modal -->
<div id="confirmation-modal" class="modal">
    <div class="modal-content">
        <h3>Confirm Payment</h3>
        <p>Amount: <span id="payment-amount"></span></p>
        <p>Merchant: <span id="merchant-name"></span></p>
        
        <div class="confirmation-buttons">
            <button onclick="confirmPayment()" class="btn-confirm">
                Confirm with Fingerprint
            </button>
            <button onclick="cancelPayment()" class="btn-cancel">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
async function confirmPayment() {
    try {
        const response = await fetch(`/qr-payment/customer/transaction/${transactionId}/confirm`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                auth_method: 'biometric',
                auth_data: {
                    fingerprint_hash: await getFingerprint()
                }
            })
        });
        
        const result = await response.json();
        if (result.success) {
            document.getElementById('confirmation-modal').style.display = 'none';
        }
    } catch (error) {
        console.error('Payment confirmation failed:', error);
    }
}
</script>
```

### Merchant Views

Create merchant views in `resources/views/merchant/`:

#### QR Scanner (`scanner.blade.php`)

```blade
@extends('layouts.merchant')

@section('content')
<div class="scanner-container">
    <h2>Scan Customer QR Code</h2>
    
    <div class="scanner-section">
        <div id="qr-scanner"></div>
        <button onclick="startScanner()" class="btn-scan">Start Scanner</button>
    </div>
    
    <div class="manual-entry">
        <h3>Or Enter Manually</h3>
        <textarea id="qr-data" placeholder="Paste QR code data here..."></textarea>
        <button onclick="processQrData()" class="btn-process">Process</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>
function startScanner() {
    // QR scanner implementation
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(function(stream) {
            const video = document.createElement('video');
            video.srcObject = stream;
            video.play();
            
            video.addEventListener('loadedmetadata', () => {
                scanQRCode(video);
            });
        });
}

function processQrData() {
    const qrData = document.getElementById('qr-data').value;
    
    fetch('/qr-payment/merchant/qr-code/scan', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            qr_data: qrData,
            merchant_id: '{{ $merchantId }}'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = `/merchant/payment-form?session=${data.data.session_id}`;
        }
    });
}
</script>
@endsection
```

#### Payment Form (`payment-form.blade.php`)

```blade
@extends('layouts.merchant')

@section('content')
<div class="payment-form-container">
    <h2>Process Payment</h2>
    
    <div class="customer-info">
        <h3>Customer: {{ $session->customer_id }}</h3>
        <p>Session: {{ $session->session_id }}</p>
    </div>
    
    <form id="payment-form">
        <div class="amount-section">
            <label for="amount">Amount ($)</label>
            <input type="number" id="amount" name="amount" step="0.01" required>
        </div>
        
        <div class="items-section">
            <label for="items">Items (Optional)</label>
            <textarea id="items" name="description" placeholder="Coffee, Muffin, etc."></textarea>
        </div>
        
        <div class="tip-section">
            <label for="tip">Tip Amount ($)</label>
            <input type="number" id="tip" name="tip_amount" step="0.01">
        </div>
        
        <button type="submit" class="btn-process-payment">Process Payment</button>
    </form>
</div>

<script>
document.getElementById('payment-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const paymentData = {
        session_id: '{{ $session->session_id }}',
        customer_id: '{{ $session->customer_id }}',
        merchant_id: '{{ $merchantId }}',
        amount: parseFloat(formData.get('amount')),
        description: formData.get('description'),
        tip_amount: parseFloat(formData.get('tip_amount')) || 0,
        currency: 'USD'
    };
    
    try {
        const response = await fetch('/qr-payment/merchant/payment/process', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(paymentData)
        });
        
        const result = await response.json();
        if (result.success) {
            window.location.href = `/merchant/payment-status/${result.data.transaction_id}`;
        }
    } catch (error) {
        console.error('Payment processing failed:', error);
    }
});
</script>
@endsection
```

## API Endpoints

### Customer Endpoints

```
POST   /qr-payment/customer/qr-code                    Generate QR code
PUT    /qr-payment/customer/qr-code/{id}/regenerate    Regenerate QR code
GET    /qr-payment/customer/session/{id}/status        Get session status
POST   /qr-payment/customer/transaction/{id}/confirm   Confirm transaction
POST   /qr-payment/customer/transaction/{id}/cancel    Cancel transaction
GET    /qr-payment/customer/transactions               Get transaction history
GET    /qr-payment/customer/transaction/{id}           Get transaction details
```

### Merchant Endpoints

```
POST   /qr-payment/merchant/qr-code/scan               Scan QR code
POST   /qr-payment/merchant/payment/process            Process payment
GET    /qr-payment/merchant/payment/{id}/status        Get payment status
GET    /qr-payment/merchant/transactions               Get transaction history
POST   /qr-payment/merchant/transaction/{id}/refund    Process refund
GET    /qr-payment/merchant/transaction/{id}/receipt   Get receipt
```

## Customization

### Custom Views

Publish and customize views:

```bash
php artisan vendor:publish --provider="XavierAu\LaravelQrPayment\LaravelQrPaymentServiceProvider" --tag="views"
```

Views will be published to `resources/views/vendor/qr-payment/`.

### Custom Services

Extend or replace services by binding custom implementations:

```php
// In AppServiceProvider
use XavierAu\LaravelQrPayment\Contracts\QrCodeServiceInterface;

public function register()
{
    $this->app->bind(QrCodeServiceInterface::class, function ($app) {
        return new CustomQrCodeService();
    });
}
```

### Custom Events

Listen for package events:

```php
// In EventServiceProvider
protected $listen = [
    'XavierAu\LaravelQrPayment\Events\PaymentConfirmationRequested' => [
        'App\Listeners\SendCustomNotification',
    ],
];
```

## Event Broadcasting

The package dispatches several events for real-time functionality:

- `PaymentConfirmationRequested` - When merchant initiates payment
- `PaymentCompleted` - When payment is confirmed
- `TransactionStatusUpdated` - When transaction status changes

## Security Considerations

1. **Session Tokens**: Use cryptographically secure random tokens
2. **QR Expiry**: Implement time-based QR code expiration
3. **HTTPS**: Always use HTTPS in production
4. **Rate Limiting**: Implement API rate limiting
5. **Validation**: Validate all inputs and transactions

## Testing

Run the test suite:

```bash
# Run all tests
vendor/bin/phpunit

# Run with test documentation
vendor/bin/phpunit --testdox

# Run specific test group
vendor/bin/phpunit --group=customer
```

## Performance

The package is optimized for:

- **API Response Time**: <200ms (p95)
- **QR Validation**: <2 seconds
- **Real-time Notifications**: <2 seconds dispatch time

## Contributing

1. Fork the repository
2. Create a feature branch
3. Write tests first (TDD)
4. Implement functionality
5. Ensure all tests pass
6. Submit a pull request

## License

MIT License. See LICENSE file for details.

## Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/xavierau/laravel-qr-payment/issues) page.