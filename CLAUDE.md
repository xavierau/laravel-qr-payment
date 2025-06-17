# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Laravel package** for QR-based payment processing that other Laravel applications can install via Composer. The package enables secure QR code payment workflows between customers and merchants.

## Current State

- **Phase**: Documentation and planning - no actual package code implemented yet
- **Structure**: Contains comprehensive PRD and technical implementation guide
- **Next Step**: Initialize Laravel package structure and begin implementation

## Package Documentation

- `prd/project_requirements_document.md`: Complete project requirements and user flows
- `prd/project_implememntation_guide.md`: Detailed technical architecture and Laravel implementation specifications

## Development Tools & Principles

**Context7 MCP Integration**: This project has access to Context7 MCP for checking the latest documentation of libraries and packages. **Always use Context7 MCP when coding** to ensure you're using the most current APIs and best practices. Check documentation frequently, especially for:
- Laravel framework updates
- Payment processing libraries
- Real-time broadcasting packages
- Security and authentication packages

**Development Methodology**: 
- **Test-Driven Development (TDD)**: **Always write tests BEFORE implementing functions/features**
- **SOLID Principles**: Follow Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, and Dependency Inversion
- **Clean Code**: Maintain readable, maintainable, and well-structured code with meaningful names and small functions

## Intended Package Structure

When implemented, this Laravel package will provide:

```
src/
├── Services/              # Core payment processing services
├── Models/               # Eloquent models for payment entities
├── Controllers/          # API controllers for payment endpoints
├── Events/               # Payment workflow events
├── Jobs/                 # Background payment processing
├── Middleware/           # Security and validation middleware
└── LaravelQrPaymentServiceProvider.php

config/qr-payment.php     # Package configuration
database/migrations/      # Database schema
tests/                    # Package tests
composer.json            # Package definition
```

## Package Development Commands

Once the package structure is created:

```bash
# Package development
composer install                    # Install dependencies
composer test                      # Run package tests
composer run lint                  # Code quality checks

# Testing with Laravel testbench
vendor/bin/phpunit                 # Run PHPUnit tests
php artisan test                   # Laravel feature tests

# Package publishing (for consumers)
php artisan vendor:publish --provider="LaravelQrPaymentServiceProvider"
php artisan migrate               # Run package migrations
```

## Key Package Features (Planned)

- **QR Code Generation**: Secure, expiring QR codes for customer wallets
- **Payment Sessions**: 5-minute TTL sessions with real-time status
- **Transaction Processing**: Complete payment workflow with validation
- **Real-time Updates**: WebSocket integration via Pusher/Laravel Broadcasting
- **Security Layer**: Device binding, encryption, rate limiting
- **Multi-auth Support**: PIN, biometric, password authentication

## Package Architecture

- **Session Management**: Temporary payment sessions with automatic cleanup
- **Transaction Engine**: State machine for payment processing
- **Notification System**: Real-time push notifications and webhooks
- **Security Framework**: End-to-end encryption and fraud detection
- **Database Schema**: Comprehensive tables for payments, sessions, and ledger

## Implementation Priority (TDD Approach)

1. Create `composer.json` with proper package definition and Laravel dependencies
2. Set up testing framework with Laravel Testbench
3. **Write tests first, then implement** following TDD red-green-refactor cycle:
   - Write failing test for feature
   - Implement minimal code to pass test
   - Refactor while keeping tests green
4. Build service provider for Laravel integration and configuration
5. Implement core services following SOLID principles:
   - Single interfaces for each service responsibility
   - Dependency injection for loose coupling
   - Small, focused classes with clear responsibilities
6. Create Eloquent models with relationships and validation
7. Build API controllers following clean architecture
8. Add real-time broadcasting events and listeners
9. Implement security middleware and authentication

## Package Installation (When Complete)

Consumers will install via:
```bash
composer require xavierau/laravel-qr-payment
php artisan vendor:publish --provider="LaravelQrPaymentServiceProvider"
php artisan migrate
```

## Testing Strategy (TDD Focus)

**Test-First Development**:
- Write tests BEFORE any implementation
- Follow red-green-refactor cycle for all features
- Maintain high test coverage (>90%)

**Test Types**:
- **Unit Tests**: Test individual classes/methods in isolation with mocks
- **Feature Tests**: Test API endpoints and workflows end-to-end
- **Integration Tests**: Test service interactions and database operations
- **Contract Tests**: Test interface implementations and service boundaries

**SOLID & Clean Code in Tests**:
- Single Responsibility: One test per behavior
- DRY: Use factories and test helpers to reduce duplication
- Clear naming: Test method names should describe expected behavior
- Arrange-Act-Assert pattern for test structure