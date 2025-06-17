<?php

namespace XavierAu\LaravelQrPayment\Tests\Unit\Services;

use XavierAu\LaravelQrPayment\Services\QrCodeService;
use XavierAu\LaravelQrPayment\Tests\TestCase;
use DateTime;

class QrCodeServiceTest extends TestCase
{
    private QrCodeService $qrCodeService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->qrCodeService = new QrCodeService();
    }

    /** @test */
    public function it_can_generate_payment_qr_code(): void
    {
        // Arrange
        $sessionId = 'test-session-123';
        
        // Act
        $qrCodeData = $this->qrCodeService->generatePaymentQrCode($sessionId);
        
        // Assert
        $this->assertIsString($qrCodeData);
        $this->assertNotEmpty($qrCodeData);
        $this->assertStringStartsWith('data:image/', $qrCodeData);
    }

    /** @test */
    public function it_generates_qr_code_within_time_limit(): void
    {
        // Arrange
        $sessionId = 'test-session-456';
        $startTime = microtime(true);
        
        // Act
        $qrCodeData = $this->qrCodeService->generatePaymentQrCode($sessionId);
        $endTime = microtime(true);
        
        // Assert
        $generationTime = $endTime - $startTime;
        $this->assertLessThan(2.0, $generationTime, 'QR code generation should complete within 2 seconds');
        $this->assertNotEmpty($qrCodeData);
    }

    /** @test */
    public function it_validates_qr_code_as_valid_when_not_expired(): void
    {
        // Arrange
        $sessionId = 'test-session-789';
        $this->qrCodeService->generatePaymentQrCode($sessionId);
        
        // Act
        $isValid = $this->qrCodeService->isQrCodeValid($sessionId);
        
        // Assert
        $this->assertTrue($isValid);
    }

    /** @test */
    public function it_validates_qr_code_as_invalid_when_expired(): void
    {
        // Arrange
        $sessionId = 'test-session-expired';
        
        // Act & Assert
        $this->assertFalse($this->qrCodeService->isQrCodeValid($sessionId));
    }

    /** @test */
    public function it_returns_qr_code_expiry_time(): void
    {
        // Arrange
        $sessionId = 'test-session-expiry';
        $this->qrCodeService->generatePaymentQrCode($sessionId);
        
        // Act
        $expiryTime = $this->qrCodeService->getQrCodeExpiry($sessionId);
        
        // Assert
        $this->assertInstanceOf(DateTime::class, $expiryTime);
        $this->assertGreaterThan(new DateTime(), $expiryTime);
    }

    /** @test */
    public function it_returns_null_expiry_for_non_existent_session(): void
    {
        // Arrange
        $sessionId = 'non-existent-session';
        
        // Act
        $expiryTime = $this->qrCodeService->getQrCodeExpiry($sessionId);
        
        // Assert
        $this->assertNull($expiryTime);
    }

    /** @test */
    public function it_can_regenerate_qr_code(): void
    {
        // Arrange
        $sessionId = 'test-session-regen';
        $originalQrCode = $this->qrCodeService->generatePaymentQrCode($sessionId);
        
        // Act
        $regeneratedQrCode = $this->qrCodeService->regenerateQrCode($sessionId);
        
        // Assert
        $this->assertIsString($regeneratedQrCode);
        $this->assertNotEmpty($regeneratedQrCode);
        $this->assertStringStartsWith('data:image/png;base64,', $regeneratedQrCode);
        // QR codes might be different due to timestamp changes
    }

    /** @test */
    public function it_generates_qr_code_with_custom_options(): void
    {
        // Arrange
        $sessionId = 'test-session-options';
        $options = [
            'size' => 400,
            'format' => 'svg'
        ];
        
        // Act
        $qrCodeData = $this->qrCodeService->generatePaymentQrCode($sessionId, $options);
        
        // Assert
        $this->assertIsString($qrCodeData);
        $this->assertNotEmpty($qrCodeData);
        $this->assertStringStartsWith('data:image/', $qrCodeData);
    }
}