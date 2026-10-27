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

    /**
     * Marken-Bereinigung (Entscheidung Markus Tritremmel, 25.10.2026):
     *  - Der Lieferantenname selbst ist keine Marke und wird zu leer. MidOcean liefert bei rund 85 % der Produkte "midocean"
     *    (Test vom 24.10.2026: 2.350 von 2.768).
     *  - Schreibweisen werden auf die von Cotton Classics abgestimmt: "Sol's" (MidOcean) und "SOL's Collection" (Cotton) sind
     *    dieselbe Marke.
     * Neue Fälle hier eintragen. Der Vergleich ignoriert Groß- und Kleinschreibung und typografische Apostrophe.
     */
    protected const BRAND_IGNORE  = ['midocean'];
    protected const BRAND_ALIASES = [
        "sol's"        => "SOL's Collection", // MidOcean "Sol's" = Cotton "SOL's Collection"
        'antonio miro' => 'Antonio Miró',     // Makito liefert "Antonio Miro" und "ANTONIO MIRÓ" für dieselbe Marke
        'antonio miró' => 'Antonio Miró',
        'cross'        => 'Cross',            // Makito: kleingeschrieben
    ];

    protected function normalizeBrand(string $brand): string {
        $brand = trim($brand);
        if ($brand === '') {
            return '';
        }
        $key = str_replace(["\u{2019}", "\u{2018}", '`', '´'], "'", $brand);
        $key = function_exists('mb_strtolower') ? mb_strtolower($key) : strtolower($key);
        if (in_array($key, self::BRAND_IGNORE, true)) {
            return '';
        }
        return self::BRAND_ALIASES[$key] ?? $brand;
    }
}