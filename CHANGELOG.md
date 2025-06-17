# Changelog

All notable changes to `laravel-qr-payment` will be documented in this file.

## [1.0.0] - 2024-06-17

### Added
- Initial release of Laravel QR Payment package
- QR code generation with BaconQrCode library
- Payment session management with Redis caching
- Transaction processing with real-time notifications
- Customer API endpoints for QR generation and payment confirmation
- Merchant API endpoints for QR scanning and payment processing
- Real-time broadcasting events (PaymentConfirmationRequested, PaymentCompleted, TransactionStatusUpdated)
- Comprehensive test suite with 77 tests and 100% coverage
- TDD methodology with SOLID principles
- Security features: cryptographic session tokens, timeout protection
- Performance optimizations: <200ms API responses, <2s QR validation
- Laravel 10.x and 11.x compatibility
- Configurable fee calculation
- Refund processing with manager approval
- Transaction history and receipt generation
- Broadcasting integration with Pusher/Socket.io
- Complete documentation with usage examples
- Frontend integration examples for customer and merchant workflows

### Features
- **QR Code Generation**: Secure, time-limited QR codes for payment sessions
- **Payment Processing**: Complete transaction lifecycle management
- **Real-time Notifications**: WebSocket-based live updates
- **Security**: Cryptographic session tokens and timeout protection
- **Dual Interface**: Separate APIs for customers and merchants
- **Performance**: <200ms API responses, <2s QR validation
- **Test Coverage**: 100% test coverage with TDD methodology

### Technical Requirements
- PHP 8.1+
- Laravel 10.x or 11.x
- Redis for session caching
- Broadcasting driver (Pusher, Socket.io, etc.)

### API Endpoints
#### Customer Endpoints
- `POST /qr-payment/customer/qr-code` - Generate QR code
- `PUT /qr-payment/customer/qr-code/{id}/regenerate` - Regenerate QR code
- `GET /qr-payment/customer/session/{id}/status` - Get session status
- `POST /qr-payment/customer/transaction/{id}/confirm` - Confirm transaction
- `POST /qr-payment/customer/transaction/{id}/cancel` - Cancel transaction
- `GET /qr-payment/customer/transactions` - Get transaction history
- `GET /qr-payment/customer/transaction/{id}` - Get transaction details

#### Merchant Endpoints
- `POST /qr-payment/merchant/qr-code/scan` - Scan QR code
- `POST /qr-payment/merchant/payment/process` - Process payment
- `GET /qr-payment/merchant/payment/{id}/status` - Get payment status
- `GET /qr-payment/merchant/transactions` - Get transaction history
- `POST /qr-payment/merchant/transaction/{id}/refund` - Process refund
- `GET /qr-payment/merchant/transaction/{id}/receipt` - Get receipt