<?php

namespace XavierAu\LaravelQrPayment\Services;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DateTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use XavierAu\LaravelQrPayment\Contracts\QrCodeServiceInterface;

class QrCodeService implements QrCodeServiceInterface
{
    private const CACHE_PREFIX = 'qr_payment_session:';
    
    private Writer $qrWriter;
    
    public function __construct()
    {
        $this->initializeQrWriter();
    }

    public function generatePaymentQrCode(string $sessionId, array $options = []): string
    {
        $size = $options['size'] ?? config('qr-payment.qr_code.size', 300);
        $format = $options['format'] ?? config('qr-payment.qr_code.format', 'png');
        
        // Create QR code data with session info and timestamp
        $qrData = $this->createQrCodeData($sessionId);
        
        // Generate QR code image
        $qrCodeImage = $this->qrWriter->writeString($qrData);
        
        // Store session expiry in cache
        $this->storeSessionExpiry($sessionId);
        
        // Convert to base64 data URL
        return $this->convertToDataUrl($qrCodeImage, $format);
    }

    public function isQrCodeValid(string $sessionId): bool
    {
        $expiryTime = $this->getQrCodeExpiry($sessionId);
        
        if ($expiryTime === null) {
            return false;
        }
        
        return $expiryTime > new DateTime();
    }

    public function getQrCodeExpiry(string $sessionId): ?DateTime
    {
        $expiryTimestamp = Cache::get($this->getCacheKey($sessionId));
        
        if ($expiryTimestamp === null) {
            return null;
        }
        
        return Carbon::createFromTimestamp($expiryTimestamp);
    }

    public function regenerateQrCode(string $sessionId, array $options = []): string
    {
        // Remove existing cache entry
        Cache::forget($this->getCacheKey($sessionId));
        
        // Generate new QR code
        return $this->generatePaymentQrCode($sessionId, $options);
    }

    private function initializeQrWriter(): void
    {
        $size = config('qr-payment.qr_code.size', 300);
        $errorCorrection = config('qr-payment.qr_code.error_correction', 'M');
        
        $renderer = new ImageRenderer(
            new RendererStyle($size),
            new SvgImageBackEnd()
        );
        
        $this->qrWriter = new Writer($renderer);
    }

    private function createQrCodeData(string $sessionId): string
    {
        // Create structured data for QR code
        // In real implementation, this would include more security measures
        return json_encode([
            'session_id' => $sessionId,
            'timestamp' => time(),
            'type' => 'payment_request',
            'version' => '1.0'
        ]);
    }

    private function storeSessionExpiry(string $sessionId): void
    {
        $expiryMinutes = config('qr-payment.qr_code.expiry_minutes', 5);
        $expiryTime = time() + ($expiryMinutes * 60);
        
        Cache::put(
            $this->getCacheKey($sessionId), 
            $expiryTime, 
            $expiryMinutes * 60
        );
    }

    private function getCacheKey(string $sessionId): string
    {
        return self::CACHE_PREFIX . $sessionId;
    }

    private function convertToDataUrl(string $imageData, string $format): string
    {
        // For SVG, we need to handle it differently
        if ($format === 'svg') {
            return "data:image/svg+xml;base64," . base64_encode($imageData);
        }
        
        $base64Data = base64_encode($imageData);
        return "data:image/{$format};base64,{$base64Data}";
    }
}