<?php
namespace SupplierSync\Adapters;

use SupplierSync\Models\Product;
use SupplierSync\Services\ApiClient;

abstract class AbstractAdapter {
    protected array $config;
    protected ApiClient $apiClient;

    public function __construct(array $config, ApiClient $apiClient) {
        $this->config = $config;
        $this->apiClient = $apiClient;
    }

    /** @return Product[] */
    abstract public function fetchProducts(): array;

    /** Lieferanten-Kategorie -> Shop-Kategorie, per config['category_mapping'] */
    protected function mapCategory(string $supplierCategory): string {
        return $this->config['category_mapping'][$supplierCategory] ?? $supplierCategory;
    }
}
