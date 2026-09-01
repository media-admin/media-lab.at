<?php
namespace SupplierSync\Adapters;

use SupplierSync\Services\ApiClient;

abstract class AbstractAdapter {
    protected array $config;
    protected ApiClient $apiClient;
    protected array $categoryMapping;

    public function __construct(array $config, ApiClient $apiClient) {
        $this->config = $config;
        $this->apiClient = $apiClient;
        $this->categoryMapping = $config['category_mapping'] ?? [];
    }

    abstract public function fetchProducts(): array;
    abstract protected function transformToProduct(array $rawData);

    protected function mapCategory(string $supplierCategory): string {
        return $this->categoryMapping[$supplierCategory] ?? $supplierCategory;
    }
}
