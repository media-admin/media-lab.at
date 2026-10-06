/**
 * Mengenstaffel-Anzeige fuer variable Produkte (Cotton Classics)
 *
 * Kein eigener AJAX-Call: WooCommerce's eigenes Variantenformular
 * (wc-add-to-cart-variation.js, jQuery-basiert) laedt beim Seitenaufruf
 * bereits alle Varianten-Daten inkl. unserer ml_price_tiers (siehe
 * woocommerce_available_variation-Filter, media-lab-ml-sku.php) und
 * feuert bei jedem Variantenwechsel das jQuery-Event 'found_variation'
 * mit genau diesem Datenpaket im zweiten Callback-Argument.
 */
export default class PriceTiers {
  constructor() {
    this.container = document.querySelector('.ml-price-tiers--variable');
    this.form = document.querySelector('.variations_form');

    if (!this.container || !this.form || typeof window.jQuery === 'undefined') {
      return;
    }

    this.tbody = this.container.querySelector('tbody');
    this.init();
  }

  init() {
    const $ = window.jQuery;

    $(this.form).on('found_variation', (event, variation) => {
      const tiers = variation.ml_price_tiers;
      const basePrice = parseFloat(variation.display_price);

      const hasDiscount = Array.isArray(tiers) && tiers.some((t) => parseFloat(t.discount_percent) > 0);

      if (!hasDiscount || Number.isNaN(basePrice)) {
        this.container.style.display = 'none';
        return;
      }

      this.render(tiers, basePrice);
      this.container.style.display = '';
    });

    $(this.form).on('reset_data', () => {
      this.container.style.display = 'none';
    });
  }

  render(tiers, basePrice) {
    this.tbody.innerHTML = '';

    tiers.forEach((tier) => {
      const tierPrice = (basePrice * (1 - (tier.discount_percent || 0) / 100));
      const row = document.createElement('tr');

      const qtyCell = document.createElement('td');
      qtyCell.textContent = `ab ${tier.min_quantity}`;

      const priceCell = document.createElement('td');
      priceCell.textContent = `${tierPrice.toFixed(2).replace('.', ',')} €`;

      row.appendChild(qtyCell);
      row.appendChild(priceCell);
      this.tbody.appendChild(row);
    });
  }
}
