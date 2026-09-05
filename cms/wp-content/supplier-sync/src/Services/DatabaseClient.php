<?php
/* ============================================================
 * FILE: src/Services/DatabaseClient.php
 *
 * Schlanker PDO-Wrapper für direkten DB-Zugriff, unabhängig von WP-CLI.
 * Funktioniert überall dort, wo PHP + DB-Zugangsdaten vorhanden sind -
 * insbesondere auch auf Production, wo kein WP-CLI verfügbar ist.
 * ============================================================ */

namespace SupplierSync\Services;

use PDO;
use PDOException;

class DatabaseClient {

    private PDO $pdo;
    private string $tablePrefix;

    public function __construct(
        string $host,
        int $port,
        string $dbName,
        string $user,
        string $pass,
        string $tablePrefix = 'wp_'
    ) {
        $this->tablePrefix = $tablePrefix;

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException(
                'DatabaseClient: Verbindung zur Datenbank fehlgeschlagen - ' . $e->getMessage()
            );
        }
    }

    public function table(string $baseName): string {
        return $this->tablePrefix . $baseName;
    }

    /**
     * @return array<string, string> Map von supplier_sku => aktuelle WooCommerce-SKU,
     *                                für alle Produkte eines bestimmten Lieferanten-Codes.
     */
    public function getCurrentSkusBySupplier(string $supplierCode): array {
        $postmeta = $this->table('postmeta');
        $posts    = $this->table('posts');

        $sql = "
            SELECT
                supplier_sku_meta.meta_value AS supplier_sku,
                sku_meta.meta_value AS current_sku
            FROM {$postmeta} AS supplier_code_meta
            INNER JOIN {$postmeta} AS supplier_sku_meta
                ON supplier_sku_meta.post_id = supplier_code_meta.post_id
                AND supplier_sku_meta.meta_key = '_ml_supplier_sku'
            INNER JOIN {$postmeta} AS sku_meta
                ON sku_meta.post_id = supplier_code_meta.post_id
                AND sku_meta.meta_key = '_sku'
            INNER JOIN {$posts} AS p
                ON p.ID = supplier_code_meta.post_id
                AND p.post_type = 'product'
                AND p.post_status != 'trash'
            WHERE supplier_code_meta.meta_key = '_ml_supplier_code'
              AND supplier_code_meta.meta_value = :supplier_code
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['supplier_code' => $supplierCode]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['supplier_sku']] = $row['current_sku'];
        }

        return $map;
    }
}