/**
 * Mengenstaffel auf der Einzelproduktseite (Cotton Classics)
 *
 * Variable Produkte: Kein eigener AJAX-Call. WooCommerce's Variantenformular (wc-add-to-cart-variation.js,
 * jQuery-basiert) liefert beim Seitenaufruf alle Varianten-Daten inkl. ml_price_tiers (siehe
 * woocommerce_available_variation-Filter, media-lab-ml-sku.php) und feuert bei jedem Variantenwechsel das
 * jQuery-Event 'found_variation' mit genau diesem Datenpaket im zweiten Callback-Argument.
 *
 * Einfache Produkte: Die Staffel steht als data-ml-tiers / data-ml-base am Container (serverseitig gerendert).
 *
 * Mengenfeld (.mlw-wishlist-qty__input): Der Preis oben wird auf den Preis der passenden Staffelstufe gesetzt,
 * die aktive Stufe ist in der Tabelle markiert (tr.is-active). Bei variablen Produkten ersetzt der Preis der
 * gewaehlten Variante die Preisspanne, "Auswahl zuruecksetzen" stellt sie wieder her.
 */
const QTY_SELECTOR   = '.mlw-wishlist-qty__input';
const PRICE_SELECTOR = '.product .summary > .price';

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

/** Gesamtsumme aus (gerundetem) Stueckpreis und Menge, wie in der Wunschliste (Stueckpreis mal Menge). */
export function totalPrice(unitPrice, qty) {
  return Math.round(unitPrice * qty * 100) / 100;
}

export function formatPrice(value) {
  return `${value.toLocaleString('de-AT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
}

export default class PriceTiers {
  constructor() {
    this.variableBox = document.querySelector('.ml-price-tiers--variable');
    this.simpleBox = document.querySelector('.ml-price-tiers[data-ml-tiers]');
    this.form = document.querySelector('.variations_form');
    this.qtyInput = document.querySelector(QTY_SELECTOR);
    this.priceEl = document.querySelector(PRICE_SELECTOR);
    this.priceOriginal = this.priceEl ? this.priceEl.innerHTML : null;
    this.current = null; // { base, tiers, tbody }

    // Gesamtsumme direkt unter dem Preis ("Gesamt (12 Stück): 72,48 €")
    this.totalEl = null;
    if (this.priceEl) {
      this.totalEl = document.createElement('p');
      this.totalEl.className = 'ml-price-total';
      this.totalEl.hidden = true;
      this.priceEl.insertAdjacentElement('afterend', this.totalEl);
    }

    if (this.simpleBox) {
      this.initSimple();
    }

    if (this.variableBox && this.form && typeof window.jQuery !== 'undefined') {
      this.tbody = this.variableBox.querySelector('tbody');
      this.initVariable();
    }

    if (this.qtyInput) {
      ['input', 'change'].forEach((type) => this.qtyInput.addEventListener(type, () => this.update()));
    }
  }

  initSimple() {
    let tiers = [];
    try {
      tiers = JSON.parse(this.simpleBox.dataset.mlTiers || '[]');
    } catch (e) {
      tiers = [];
    }
    const base = parseFloat(this.simpleBox.dataset.mlBase);

    if (!Array.isArray(tiers) || !tiers.length || Number.isNaN(base)) {
      return;
    }

    this.current = { base, tiers, tbody: this.simpleBox.querySelector('tbody') };
    this.update();
  }

  initVariable() {
    const $ = window.jQuery;

    $(this.form).on('found_variation', (event, variation) => {
      const tiers = variation.ml_price_tiers;
      const basePrice = parseFloat(variation.display_price);
      const hasDiscount = Array.isArray(tiers) && tiers.some((t) => parseFloat(t.discount_percent) > 0);

      if (Number.isNaN(basePrice)) {
        this.variableBox.style.display = 'none';
        this.current = null;
        this.update();
        return;
      }

      if (!hasDiscount) {
        // Keine Staffel: Tabelle bleibt weg, der Preis oben zeigt trotzdem die gewaehlte Variante
        this.variableBox.style.display = 'none';
        this.current = { base: basePrice, tiers: [], tbody: null };
        this.update();
        return;
      }

      this.render(tiers, basePrice);
      this.variableBox.style.display = '';
      this.current = { base: basePrice, tiers, tbody: this.tbody };
      this.update();
    });

    $(this.form).on('reset_data', () => {
      this.variableBox.style.display = 'none';
      this.current = null;
      this.update();
    });
  }

  render(tiers, basePrice) {
    this.tbody.innerHTML = '';

    tiers.forEach((tier) => {
      const row = document.createElement('tr');

      const qtyCell = document.createElement('td');
      qtyCell.textContent = `ab ${tier.min_quantity}`;

      const priceCell = document.createElement('td');
      priceCell.textContent = formatPrice(tierPrice(basePrice, parseFloat(tier.discount_percent) || 0));

      row.appendChild(qtyCell);
      row.appendChild(priceCell);
      this.tbody.appendChild(row);
    });
  }

  quantity() {
    const qty = this.qtyInput ? parseInt(this.qtyInput.value, 10) : 1;
    return qty > 0 ? qty : 1;
  }

  /** Aktive Stufe markieren und den Preis oben zur Menge passend setzen. */
  update() {
    document.querySelectorAll('.ml-price-tiers tbody tr.is-active').forEach((row) => row.classList.remove('is-active'));

    if (!this.priceEl) {
      return;
    }

    if (!this.current) {
      this.priceEl.innerHTML = this.priceOriginal;
      if (this.totalEl) {
        this.totalEl.hidden = true;
      }
      return;
    }

    const { base, tiers, tbody } = this.current;
    const tier = pickTier(tiers, this.quantity());

    if (tier && tbody) {
      const row = tbody.querySelectorAll('tr')[tier.index];
      if (row) {
        row.classList.add('is-active');
      }
    }

    const price = tier ? tierPrice(base, tier.discount) : base;
    const qty = this.quantity();

    if (this.totalEl) {
      this.totalEl.textContent = `Gesamt (${qty} Stück): ${formatPrice(totalPrice(price, qty))}`;
      this.totalEl.hidden = false;
    }

    if (this.simpleBox && price === base) {
      // Einfaches Produkt ohne Rabatt bei dieser Menge: Original-Markup von WooCommerce behalten
      this.priceEl.innerHTML = this.priceOriginal;
    } else {
      this.priceEl.textContent = formatPrice(price);
    }
  }
}
