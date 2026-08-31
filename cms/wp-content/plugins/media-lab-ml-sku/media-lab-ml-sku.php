<?php
/**
 * Plugin Name: Media Lab – ML SKU Generator
 * Description: Generates Media Lab SKUs (ML-CODE-NNNNNN(-VVV)) for WooCommerce products/variations.
 *              Fires after WP All Import finishes writing custom fields (pmxi_saved_post),
 *              with a manual-save fallback and an atomic counter to avoid race conditions.
 * Version: 0.2.0
 */

defined('ABSPATH') || exit;

class MediaLab_ML_SKU_Generator {

	const SKU_PREFIX = 'ML';
	const PARENT_PAD = 6;
	const VAR_PAD    = 3;

	// supplier_code => token used in SKU (fallback mapping only;
	// the importer SHOULD already write the token directly into _ml_supplier_code)
	private $supplierTokens = [
		'cotton'   => 'LCC',
		'midocean' => 'DIM',
		'makito'   => 'TKM',
	];

	public function init() {
		// PRIMARY: fires after WP All Import has written all custom fields
		// (both for the imported product/parent AND for each imported variation)
		add_action('pmxi_saved_post', [$this, 'handle_import_saved_post'], 10, 1);

		// FALLBACK: manual edits/creation in wp-admin (not via importer)
		add_action('save_post_product', [$this, 'maybe_assign_skus_on_product_save'], 50, 3);
		add_action('save_post_product_variation', [$this, 'maybe_assign_sku_on_variation_save'], 50, 3);
	}

	/* ------------------------------------------------------------------ *
	 *  WP All Import hook
	 * ------------------------------------------------------------------ */

	public function handle_import_saved_post($post_id) {
		$post_type = get_post_type($post_id);

		if ($post_type === 'product') {
			$this->process_parent($post_id);
		} elseif ($post_type === 'product_variation') {
			$this->process_variation_via_import($post_id);
		}
	}

	private function process_parent($post_id) {
		$product = wc_get_product($post_id);
		if (!$product) return;

		$supplierCode = get_post_meta($post_id, '_ml_supplier_code', true);
		$supplierSku  = get_post_meta($post_id, '_ml_supplier_sku', true);

		if (!$supplierCode || !$supplierSku) return;

		$token = $this->token_for_supplier($supplierCode);
		if (!$token) {
			error_log("[ML SKU] Unknown supplier code '{$supplierCode}' on product {$post_id}");
			return;
		}

		$parentNumber = $this->ensure_parent_number($post_id, $token);

		if (!$product->get_sku()) {
			$sku = $this->format_parent_sku($token, $parentNumber);
			$product->set_sku($sku);
			$product->save();
		}

		if ($product->is_type('variable')) {
			foreach ($product->get_children() as $variation_id) {
				$this->assign_variation_sku($variation_id, $post_id, $token, $parentNumber);
			}
		}
	}

	private function process_variation_via_import($variation_id) {
		$variation = wc_get_product($variation_id);
		if (!$variation || !$variation->is_type('variation')) return;

		$parent_id = $variation->get_parent_id();
		if (!$parent_id) return;

		$supplierCode = get_post_meta($parent_id, '_ml_supplier_code', true);
		if (!$supplierCode) return;

		$token = $this->token_for_supplier($supplierCode);
		if (!$token) return;

		$parentNumber = $this->ensure_parent_number($parent_id, $token);

		$this->assign_variation_sku($variation_id, $parent_id, $token, $parentNumber);
	}

	/* ------------------------------------------------------------------ *
	 *  Manual save fallback (wp-admin, no importer involved)
	 * ------------------------------------------------------------------ */

	public function maybe_assign_skus_on_product_save($post_id, $post, $update) {
		if (wp_is_post_revision($post_id)) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (defined('PMXI_EDITION')) return;

		$this->process_parent($post_id);
	}

	public function maybe_assign_sku_on_variation_save($post_id, $post, $update) {
		if (wp_is_post_revision($post_id)) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (defined('PMXI_EDITION')) return;

		$this->process_variation_via_import($post_id);
	}

	/* ------------------------------------------------------------------ *
	 *  SKU assignment helpers
	 * ------------------------------------------------------------------ */

	private function assign_variation_sku($variation_id, $parent_id, $token, $parentNumber) {
		$variation = wc_get_product($variation_id);
		if (!$variation) return;

		if ($variation->get_sku()) return;

		$variantKey = $this->build_variant_key($variation);
		update_post_meta($variation_id, '_ml_variant_key', $variantKey);

		$varNumber = get_post_meta($variation_id, '_ml_variant_number', true);
		if (!$varNumber) {
			$varNumber = $this->atomic_increment("ml_var_counter_parent_{$parent_id}");
			update_post_meta($variation_id, '_ml_variant_number', $varNumber);
		}

		$sku = $this->format_variant_sku($token, $parentNumber, $varNumber);

		$variation->set_sku($sku);
		$variation->save();
	}

	private function ensure_parent_number($post_id, $token) {
		$parentNumber = get_post_meta($post_id, '_ml_parent_number', true);
		if (!$parentNumber) {
			$parentNumber = $this->atomic_increment("ml_counter_{$token}");
			update_post_meta($post_id, '_ml_parent_number', $parentNumber);
		}
		return (int) $parentNumber;
	}

	private function build_variant_key($variation) {
		$attrs = $variation->get_attributes();
		ksort($attrs);

		$parts = [];
		foreach ($attrs as $k => $v) {
			$parts[] = "{$k}={$v}";
		}
		return implode('|', $parts);
	}

	private function token_for_supplier($supplierCode) {
		if (in_array($supplierCode, ['DIM', 'LCC', 'TKM'], true)) return $supplierCode;
		return $this->supplierTokens[$supplierCode] ?? null;
	}

	private function format_parent_sku($token, $parentNumber) {
		$num = str_pad((string) $parentNumber, self::PARENT_PAD, '0', STR_PAD_LEFT);
		return self::SKU_PREFIX . '-' . $token . '-' . $num;
	}

	private function format_variant_sku($token, $parentNumber, $varNumber) {
		$pnum = str_pad((string) $parentNumber, self::PARENT_PAD, '0', STR_PAD_LEFT);
		$vnum = str_pad((string) $varNumber, self::VAR_PAD, '0', STR_PAD_LEFT);
		return self::SKU_PREFIX . '-' . $token . '-' . $pnum . '-' . $vnum;
	}

	/**
	 * Atomic counter increment using a single UPSERT statement,
	 * safe against concurrent requests (unlike get_option/update_option).
	 * Requires option_name to have a UNIQUE index — true for wp_options by default.
	 */
	private function atomic_increment($optionKey) {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, '1', 'no')
			 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
			$optionKey
		));

		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$optionKey
		));
	}
}

add_action('plugins_loaded', function () {
	if (!class_exists('WooCommerce')) return;
	$gen = new MediaLab_ML_SKU_Generator();
	$gen->init();
});
