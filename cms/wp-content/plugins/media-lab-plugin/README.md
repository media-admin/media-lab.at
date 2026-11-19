# Media Lab Project Starter

Project-specific CPTs, taxonomies, and ACF fields for client websites.

## What's Included

- **Custom Post Types**: Team, Project, Job, Service, Testimonial, FAQ, Hero Slides, Carousel, Maps
- **Taxonomies**: Project Categories, Service Categories, Job Categories, Job Types, Job Locations, etc.
- **ACF Field Groups**: 11 field groups (65 fields total) stored as JSON

## Usage

### For Each New Client Project:

1. Duplicate this plugin folder
2. Rename to `client-name-project` (e.g., `acme-corp-project`)
3. Update plugin header in main PHP file
4. Activate for client site
5. Customize CPTs/ACF as needed

## Customization

Add/remove CPTs in `inc/custom-post-types.php`
Add/remove taxonomies in `inc/taxonomies.php`
ACF fields auto-load from `acf-json/`
Currency display (`inc/currency.php`): EUR with a space before the amount ("EUR 100.000,00"), set via the WooCommerce filters for symbol, price format and separators (project decision at.media-lab, 19.11.2026)
Variation thumbnails in the product gallery (`inc/variation-thumbs.php`): switches on the opt-in filter `mlw_variation_color_thumbs` of media-lab-woocommerce 2.36.0 (project decision at.media-lab, 19.11.2026)

## Dependencies

Requires: Media Lab Agency Core plugin

Keine Composer-Abhängigkeiten — kein `composer.json`/`composer.lock`
vorhanden. Scaffold-Plugin, keine eigene Logik über CPT-/Taxonomie-/
ACF-Registrierung hinaus. (Ausnahme im Starter Kit: `media-lab-backup`,
das phpseclib3 für SSH-Key-Auth benötigt.)

## Version

1.2.0

