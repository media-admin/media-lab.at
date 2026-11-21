<?php
namespace SupplierSync\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class ApiClient {
    private Client $client;
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null) {
        $this->client = new Client(['timeout' => 60]);
        $this->logger = $logger;
    }

    public function get(string $url, array $options = []) {
        try {
            // Token-Parameter (pszinternal) nicht im Klartext ins Log schreiben
            $this->logger?->info("API Request: " . preg_replace('/(pszinternal=)[^&]+/i', '$1***', $url));
            return $this->client->get($url, $options);
        } catch (\Exception $e) {
            $this->logger?->error("API Error: " . preg_replace('/(pszinternal=)[^&\s\'"]+/i', '$1***', $e->getMessage()));
            throw $e;
        }
    }
}
