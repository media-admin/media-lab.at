<?php
namespace SupplierSync\Services;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class ApiClient {
    private Client $client;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger) {
        $this->client = new Client(['timeout' => 60]);
        $this->logger = $logger;
    }

    public function get(string $url, array $options = []) {
        try {
            $this->logger->info("API Request: $url");
            return $this->client->get($url, $options);
        } catch (\Exception $e) {
            $this->logger->error("API Error: " . $e->getMessage());
            throw $e;
        }
    }
}
