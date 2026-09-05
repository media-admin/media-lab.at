<?php
namespace SupplierSync\Adapters;

use SupplierSync\Services\ApiClient;
use Psr\Log\LoggerInterface;

abstract class AbstractAdapter {
    protected array $config;
    protected ApiClient $apiClient;
    protected array $categoryMapping;
    protected ?LoggerInterface $logger = null;

    public function __construct(array $config, ApiClient $apiClient) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->categoryMapping = $config['category_mapping'] ?? [];
    }

    public function setLogger(LoggerInterface $logger): void {
        $this->logger = $logger;
    }

    abstract public function fetchProducts(): array;
    abstract protected function transformToProduct(array $rawData);

    protected function mapCategory(string $supplierCategory): string {
        return $this->categoryMapping[$supplierCategory] ?? $supplierCategory;
    }
}