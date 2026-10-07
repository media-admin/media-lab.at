/**
 * Mengenstaffel auf der Einzelproduktseite (Cotton Classics)
 *
 * Summenzeile und Preisanzeige zur Menge im Mengenfeld kommen aus media-lab-woocommerce (wishlist.js, ab 2.13.0).
 * Dieses Modul liefert nur die Staffel:
 *   - einen Haken fuer den Stueckpreis nach Menge (window.mlwUnitPriceFilters),
 *   - die Staffel-Tabelle bei variablen Produkten (Daten aus found_variation, siehe
 *     woocommerce_available_variation-Filter in media-lab-ml-sku.php),
 *   - die Markierung der aktiven Stufe (Event mlw:price-updated).
 * Einfache Produkte: Die Staffel steht als data-ml-tiers am Container (serverseitig gerendert).
 */

/** Staffelstufe fuer eine Menge: die hoechste Stufe, deren Mindestmenge erreicht ist. */
export function pickTier(tiers, qty) {
  let best = null;
  tiers.forEach((tier, index) => {
    const min = parseInt(tier.min_quantity, 10) || 0;
    if (qty >= min && (best === null || min > best.min)) {
      best = { index, min, discount: parseFloat(tier.discount_percent) || 0 };
    }
  });
  return best;
}

export function tierPrice(base, discountPercent) {
  return Math.round(base * (1 - discountPercent / 100) * 100) / 100;
}

function hasDiscount(tiers) {
  return Array.isArray(tiers) && tiers.some((t) => parseFloat(t.discount_percent) > 0);
}

function money(value) {
  return typeof window.mlwFormatMoney === 'function'
    ? window.mlwFormatMoney(value)
    : `${value.toFixed(2).replace('.', ',')} €`;
}

export default class PriceTiers {
  constructor() {
    this.variableBox = document.querySelector('.ml-price-tiers--variable');
    this.simpleBox = document.querySelector('.ml-price-tiers[data-ml-tiers]');
    this.form = document.querySelector('.variations_form');
    this.simpleTiers = null;
    this.tbody = null;

    if (!this.variableBox && !this.simpleBox) {
      return;
    }

    if (this.simpleBox) {
      this.initSimple();
    }

    if (this.variableBox && this.form && typeof window.jQuery !== 'undefined') {
      this.tbody = this.variableBox.querySelector('tbody');
      this.initVariable();
    }

    // Haken im Starter-Kit: Stueckpreis nach Menge. Danach einmal neu rechnen lassen.
    window.mlwUnitPriceFilters = window.mlwUnitPriceFilters || [];
    window.mlwUnitPriceFilters.push((price, qty, ctx) => this.unitPrice(price, qty, ctx));
    document.addEventListener('mlw:price-updated', (event) => this.highlight(event.detail));
    document.dispatchEvent(new CustomEvent('mlw:recalculate'));
  }

  initSimple() {
    let tiers = [];
    try {
      tiers = JSON.parse(this.simpleBox.dataset.mlTiers || '[]');
    } catch (e) {
      tiers = [];
    }

    if (hasDiscount(tiers)) {
      this.simpleTiers = tiers;
    }
  }

  initVariable() {
    const $ = window.jQuery;

    $(this.form).on('found_variation', (event, variation) => {
      const tiers = variation.ml_price_tiers;
      const basePrice = parseFloat(variation.display_price);

      if (!hasDiscount(tiers) || Number.isNaN(basePrice)) {
        this.variableBox.style.display = 'none';
        return;
      }

      this.render(tiers, basePrice);
      this.variableBox.style.display = '';
      // Die Summenzeile (Starter-Kit) rechnet neu und meldet die aktive Stufe zurueck
      document.dispatchEvent(new CustomEvent('mlw:recalculate'));
    });

    $(this.form).on('reset_data', () => {
      this.variableBox.style.display = 'none';
    });
  }

  render(tiers, basePrice) {
    this.tbody.innerHTML = '';

    tiers.forEach((tier) => {
      const row = document.createElement('tr');

      const qtyCell = document.createElement('td');
      qtyCell.textContent = `ab ${tier.min_quantity}`;

      const priceCell = document.createElement('td');
      priceCell.textContent = money(tierPrice(basePrice, parseFloat(tier.discount_percent) || 0));

      row.appendChild(qtyCell);
      row.appendChild(priceCell);
      this.tbody.appendChild(row);
    });
  }

  /** Haken fuer mlwUnitPriceFilters: Stueckpreis der Staffelstufe, die zur Menge passt. */
  unitPrice(price, qty, ctx) {
    const tiers = this.simpleTiers || (ctx && ctx.variation ? ctx.variation.ml_price_tiers : null);
    if (!hasDiscount(tiers)) {
      return price;
    }
    const tier = pickTier(tiers, qty);
    return tier ? tierPrice(price, tier.discount) : price;
  }

  /** Aktive Stufe in der Tabelle markieren (Event mlw:price-updated aus dem Starter-Kit). */
  highlight(detail) {
    document.querySelectorAll('.ml-price-tiers tbody tr.is-active').forEach((row) => row.classList.remove('is-active'));

    if (!detail) {
      return;
    }

    const tiers = this.simpleTiers || (detail.variation ? detail.variation.ml_price_tiers : null);
    const tbody = this.simpleTiers ? this.simpleBox.querySelector('tbody') : this.tbody;
    if (!hasDiscount(tiers) || !tbody) {
      return;
    }

    const tier = pickTier(tiers, detail.qty);
    const row = tier ? tbody.querySelectorAll('tr')[tier.index] : null;
    if (row) {
      row.classList.add('is-active');
    }
  }
}
