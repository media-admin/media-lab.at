<?php
if (!defined('ABSPATH')) exit;

add_action('pmxi_saved_post', function ($post_id) {

    // Preisstaffeln
    $rawTiers = get_post_meta($post_id, '_price_tiers_raw', true);
    if ($rawTiers) {
        $tiers = json_decode($rawTiers, true);
        if (is_array($tiers)) {
            update_field('field_tier_pricing', $tiers, $post_id);
        }
    }

    // Konfigurations-Schritte (fester Mengen-Step)
    $rawSteps = get_post_meta($post_id, '_config_steps_raw', true);
    if ($rawSteps) {
        $steps = json_decode($rawSteps, true);
        if (is_array($steps)) {
            update_field('field_config_steps', $steps, $post_id);
        }
    }

}, 20); // Priorität 20: nach ML-SKU-Vergabe (Priorität 10 im ML-SKU-Plugin)