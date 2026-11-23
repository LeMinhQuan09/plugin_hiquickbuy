/**
 * HI Quick-Buy Frontend Controller (Pure Vanilla JS - Zero jQuery Dependency)
 *
 * @package HIQuickBuy
 */

(function () {
	'use strict';

	if (typeof window.hiqbConfig === 'undefined') {
		return;
	}

	const config = window.hiqbConfig;
	const i18n = config.i18n || {};

	/** @type {HTMLElement|null} */
	let modalEl = null;
	/** @type {HTMLElement|null} */
	let modalBodyEl = null;
	/** @type {HTMLElement|null} */
	let lastFocusedEl = null;
	/** @type {number} */
	let activeProductId = 0;
	/** @type {boolean} */
	let isSyncingVariations = false;
	/** @type {AbortController|null} */
	let shippingAbortController = null;

	/**
	 * Format currency according to WooCommerce settings.
	 *
	 * @param {number} amount
	 * @returns {string}
	 */
	function formatPrice(amount) {
		const c = config.currency || {};
		const decimals = Number.isInteger(c.decimals) ? c.decimals : 0;
		const decSep = typeof c.decimalSeparator === 'string' ? c.decimalSeparator : ',';
		const thouSep = typeof c.thousandSeparator === 'string' ? c.thousandSeparator : '.';
		const symbol = typeof c.symbol === 'string' ? c.symbol : '₫';
		const pos = c.position || 'right';

		const num = Number.isFinite(amount) ? amount : 0;
		const fixed = num.toFixed(decimals);
		const parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
		const formatted = decimals > 0 ? parts.join(decSep) : parts[0];

		switch (pos) {
			case 'left':
				return symbol + formatted;
			case 'left_space':
				return symbol + ' ' + formatted;
			case 'right_space':
				return formatted + ' ' + symbol;
			case 'right':
			default:
				return formatted + symbol;
		}
	}

	/**
	 * Send a POST request via Fetch API to WordPress admin-ajax.php with nonce.
	 *
	 * @param {string} action
	 * @param {Record<string, string|number>|FormData} payload
	 * @param {AbortSignal} [signal]
	 * @returns {Promise<any>}
	 */
	async function postAjax(action, payload, signal) {
		let body;
		if (payload instanceof FormData) {
			body = payload;
		} else {
			body = new FormData();
			Object.keys(payload || {}).forEach((key) => {
				body.append(key, String(payload[key]));
			});
		}

		body.set('action', action);
		body.set('nonce', config.nonce || '');

		const response = await fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
			signal,
		});

		const json = await response.json();
		if (!response.ok || !json || !json.success) {
			const msg =
				(json && json.data && json.data.message) ||
				i18n.genericError ||
				'Request failed.';
			throw new Error(msg);
		}

		return json.data;
	}

	/**
	 * Locate the Single Product WooCommerce form outside the popup if matching productId.
	 *
	 * @param {number} productId
	 * @returns {HTMLFormElement|null}
	 */
	function getOuterProductForm(productId) {
		const forms = document.querySelectorAll('form.variations_form, form.cart');
		for (let i = 0; i < forms.length; i += 1) {
			const form = forms[i];
			const formProdId =
				Number(form.getAttribute('data-product_id')) ||
				Number((form.querySelector('input[name="product_id"]') || {}).value) ||
				Number((form.querySelector('button[name="add-to-cart"]') || {}).value) ||
				0;

			if (formProdId === productId) {
				return form;
			}
		}
		return null;
	}

	/**
	 * Read preselected attributes, variation_id, and quantity from outer Single Product form.
	 *
	 * @param {number} productId
	 * @returns {{ variationId: number, quantity: number, attributes: Record<string, string> }}
	 */
	function readOuterSelection(productId) {
		const result = {
			variationId: 0,
			quantity: 1,
			attributes: {},
		};

		const outerForm = getOuterProductForm(productId);
		if (!outerForm) {
			return result;
		}

		const varInput = outerForm.querySelector('input[name="variation_id"]');
		if (varInput && varInput.value) {
			result.variationId = parseInt(varInput.value, 10) || 0;
		}

		const qtyInput = outerForm.querySelector('input[name="quantity"]');
		if (qtyInput && qtyInput.value) {
			result.quantity = Math.max(1, parseInt(qtyInput.value, 10) || 1);
		}

		const attrSelects = outerForm.querySelectorAll('select[name^="attribute_"]');
		attrSelects.forEach((select) => {
			if (select.value) {
				result.attributes[select.name] = select.value;
			}
		});

		return result;
	}

	/**
	 * Open the modal dialog and lock body scroll.
	 */
	function openModal() {
		if (!modalEl) {
			return;
		}
		lastFocusedEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		modalEl.hidden = false;
		document.body.classList.add('hiqb-modal-open');

		const closeBtn = modalEl.querySelector('.hiqb-modal__close');
		if (closeBtn instanceof HTMLElement) {
			closeBtn.focus();
		}
	}

	/**
	 * Close the modal dialog and restore focus.
	 */
	function closeModal() {
		if (!modalEl || modalEl.hidden) {
			return;
		}
		modalEl.hidden = true;
		document.body.classList.remove('hiqb-modal-open');
		activeProductId = 0;

		if (shippingAbortController) {
			shippingAbortController.abort();
			shippingAbortController = null;
		}

		if (lastFocusedEl) {
			lastFocusedEl.focus();
			lastFocusedEl = null;
		}
	}

	/**
	 * Show loading state inside modal body.
	 */
	function renderLoading() {
		if (!modalBodyEl) {
			return;
		}
		modalBodyEl.innerHTML =
			'<div class="hiqb-loading">' +
			'<span class="hiqb-spinner" aria-hidden="true"></span>' +
			'<p>' +
			escapeHtml(i18n.loading || 'Loading...') +
			'</p>' +
			'</div>';
	}

	/**
	 * Normalize Vietnamese text for accent-insensitive search.
	 *
	 * @param {string} text
	 * @returns {string}
	 */
	function normalizeSearchText(text) {
		return String(text || '')
			.normalize('NFD')
			.replace(/[\u0300-\u036f]/g, '')
			.replace(/đ/g, 'd')
			.replace(/Đ/g, 'd')
			.toLowerCase()
			.trim();
	}

	/**
	 * Enhance a native <select> with a lightweight, zero-dependency searchable combobox UI.
	 *
	 * @param {HTMLSelectElement} selectEl
	 * @returns {{ sync: () => void, close: () => void }}
	 */
	function enhanceSearchableSelect(selectEl) {
		const container = document.createElement('div');
		container.className = 'hiqb-combobox';

		const trigger = document.createElement('button');
		trigger.type = 'button';
		trigger.className = 'hiqb-combobox__trigger';
		trigger.setAttribute('aria-haspopup', 'listbox');
		trigger.setAttribute('aria-expanded', 'false');

		const labelSpan = document.createElement('span');
		labelSpan.className = 'hiqb-combobox__value';

		const arrowSpan = document.createElement('span');
		arrowSpan.className = 'hiqb-combobox__arrow';
		arrowSpan.setAttribute('aria-hidden', 'true');

		trigger.appendChild(labelSpan);
		trigger.appendChild(arrowSpan);

		const dropdown = document.createElement('div');
		dropdown.className = 'hiqb-combobox__dropdown';
		dropdown.hidden = true;

		const searchWrap = document.createElement('div');
		searchWrap.className = 'hiqb-combobox__search-wrap';

		const searchInput = document.createElement('input');
		searchInput.type = 'search';
		searchInput.className = 'hiqb-combobox__search';
		searchInput.placeholder =
			selectEl.getAttribute('data-search-placeholder') || 'Tìm kiếm...';
		searchInput.setAttribute('autocomplete', 'off');
		searchWrap.appendChild(searchInput);

		const listEl = document.createElement('ul');
		listEl.className = 'hiqb-combobox__list';
		listEl.setAttribute('role', 'listbox');

		dropdown.appendChild(searchWrap);
		dropdown.appendChild(listEl);

		selectEl.classList.add('hiqb-sr-select');
		selectEl.parentNode.insertBefore(container, selectEl);
		container.appendChild(selectEl);
		container.appendChild(trigger);
		container.appendChild(dropdown);

		let activeIndex = -1;

		function getOptions() {
			return Array.from(selectEl.options).filter((opt) => opt.value !== '');
		}

		function updateTriggerLabel() {
			const selectedOpt = selectEl.options[selectEl.selectedIndex];
			const hasValue = Boolean(selectEl.value);
			labelSpan.textContent = selectedOpt ? selectedOpt.textContent : '';
			trigger.classList.toggle('hiqb-combobox__trigger--placeholder', !hasValue);
			trigger.disabled = selectEl.disabled;
			container.classList.toggle('hiqb-combobox--disabled', selectEl.disabled);
			trigger.classList.toggle(
				'hiqb-input--invalid',
				selectEl.classList.contains('hiqb-input--invalid')
			);
		}

		function renderList(query) {
			const needle = normalizeSearchText(query);
			const options = getOptions();
			const filtered = needle
				? options.filter((opt) => normalizeSearchText(opt.textContent).includes(needle))
				: options;

			listEl.innerHTML = '';
			activeIndex = -1;

			if (!filtered.length) {
				const emptyLi = document.createElement('li');
				emptyLi.className = 'hiqb-combobox__empty';
				emptyLi.textContent = 'Không tìm thấy kết quả';
				listEl.appendChild(emptyLi);
				return;
			}

			filtered.forEach((opt) => {
				const li = document.createElement('li');
				li.className = 'hiqb-combobox__option';
				li.setAttribute('role', 'option');
				li.setAttribute('data-value', opt.value);
				li.textContent = opt.textContent;
				if (opt.value === selectEl.value) {
					li.classList.add('hiqb-combobox__option--selected');
					li.setAttribute('aria-selected', 'true');
				}
				li.addEventListener('mousedown', (e) => {
					e.preventDefault();
					selectValue(opt.value);
				});
				listEl.appendChild(li);
			});
		}

		function selectValue(val) {
			if (selectEl.value !== val) {
				selectEl.value = val;
				selectEl.dispatchEvent(new Event('change', { bubbles: true }));
			}
			updateTriggerLabel();
			closeDropdown();
			trigger.focus();
		}

		function openDropdown() {
			if (selectEl.disabled) {
				return;
			}
			// Close any other open combobox inside modal.
			document.querySelectorAll('.hiqb-combobox--open').forEach((other) => {
				if (other !== container && typeof other._hiqbClose === 'function') {
					other._hiqbClose();
				}
			});
			dropdown.hidden = false;
			container.classList.add('hiqb-combobox--open');
			trigger.setAttribute('aria-expanded', 'true');
			searchInput.value = '';
			renderList('');
			window.requestAnimationFrame(() => searchInput.focus());
		}

		function closeDropdown() {
			if (dropdown.hidden) {
				return;
			}
			dropdown.hidden = true;
			container.classList.remove('hiqb-combobox--open');
			trigger.setAttribute('aria-expanded', 'false');
		}

		container._hiqbClose = closeDropdown;
		selectEl._hiqbSync = updateTriggerLabel;

		trigger.addEventListener('click', () => {
			if (dropdown.hidden) {
				openDropdown();
			} else {
				closeDropdown();
			}
		});

		searchInput.addEventListener('input', () => {
			renderList(searchInput.value);
		});

		searchInput.addEventListener('keydown', (e) => {
			const items = Array.from(listEl.querySelectorAll('.hiqb-combobox__option'));
			if (e.key === 'Escape') {
				e.preventDefault();
				e.stopPropagation();
				closeDropdown();
				trigger.focus();
				return;
			}
			if (!items.length) {
				return;
			}
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				activeIndex = (activeIndex + 1) % items.length;
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				activeIndex = (activeIndex - 1 + items.length) % items.length;
			} else if (e.key === 'Enter') {
				e.preventDefault();
				const targetItem = items[activeIndex >= 0 ? activeIndex : 0];
				if (targetItem) {
					selectValue(targetItem.getAttribute('data-value') || '');
				}
				return;
			} else {
				return;
			}

			items.forEach((el, idx) => {
				el.classList.toggle('hiqb-combobox__option--active', idx === activeIndex);
			});
			if (items[activeIndex]) {
				items[activeIndex].scrollIntoView({ block: 'nearest' });
			}
		});

		document.addEventListener('click', (e) => {
			if (!container.contains(e.target)) {
				closeDropdown();
			}
		});

		updateTriggerLabel();

		return {
			sync: updateTriggerLabel,
			close: closeDropdown,
		};
	}

	/**
	 * Escape text safely for HTML insertion.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = String(str || '');
		return div.innerHTML;
	}

	/**
	 * Open popup and lazy-load product form via Fetch API.
	 *
	 * @param {number} productId
	 */
	async function handleTriggerClick(productId) {
		if (!productId || !modalEl || !modalBodyEl) {
			return;
		}

		activeProductId = productId;
		openModal();
		renderLoading();

		const outer = readOuterSelection(productId);

		try {
			const data = await postAjax('hiqb_load_form', {
				product_id: productId,
				variation_id: outer.variationId,
				quantity: outer.quantity,
				attributes: JSON.stringify(outer.attributes),
			});

			modalBodyEl.innerHTML = data.html || '';
			initPopupForm();
		} catch (err) {
			modalBodyEl.innerHTML =
				'<div class="hiqb-error-box">' +
				'<p>' +
				escapeHtml(err.message || i18n.loadError || 'Error loading form.') +
				'</p>' +
				'</div>';
		}
	}

	/**
	 * Initialize interactive controls inside the freshly loaded popup form.
	 */
	function initPopupForm() {
		const wrapper = modalBodyEl ? modalBodyEl.querySelector('.hiqb-form-wrapper') : null;
		const form = modalBodyEl ? modalBodyEl.querySelector('#hiqb-order-form') : null;
		if (!wrapper || !form) {
			return;
		}

		let variations = [];
		try {
			variations = JSON.parse(wrapper.getAttribute('data-variations') || '[]');
		} catch (e) {
			variations = [];
		}

		const isVariable = wrapper.getAttribute('data-is-variable') === '1';
		const defaultImage = wrapper.getAttribute('data-default-image') || '';
		const qtyInput = form.querySelector('input[name="quantity"]');
		const variationIdInput = form.querySelector('input[name="variation_id"]');
		const variationSelects = Array.from(form.querySelectorAll('.hiqb-variation-select'));
		const provinceSelect = form.querySelector('#hiqb-province');
		const wardSelect = form.querySelector('#hiqb-ward');

		const provinceCombo =
			provinceSelect && provinceSelect.hasAttribute('data-hiqb-searchable')
				? enhanceSearchableSelect(provinceSelect)
				: null;
		const wardCombo =
			wardSelect && wardSelect.hasAttribute('data-hiqb-searchable')
				? enhanceSearchableSelect(wardSelect)
				: null;

		/**
		 * Recalculate subtotal + shipping = grand total in real time.
		 */
		function updateTotals() {
			const hasPrice = wrapper.getAttribute('data-has-price') !== '0';
			const noPriceLabel = i18n.noPriceLabel || 'Liên hệ';
			const subtotalEl = form.querySelector('[data-hiqb-subtotal]');
			const shipCostEl = form.querySelector('[data-hiqb-shipping-cost]');
			const totalEl = form.querySelector('[data-hiqb-grand-total]');
			const qtyControl = form.querySelector('[data-hiqb-qty-control]');
			const shipSection = form.querySelector('[data-hiqb-shipping-section]');
			const shipRow = form.querySelector('[data-hiqb-shipping-row]');
			const paymentSection = form.querySelector('[data-hiqb-payment-section]');
			const summarySection = form.querySelector('[data-hiqb-summary-section]');

			if (!hasPrice) {
				if (qtyControl) {
					qtyControl.hidden = true;
				}
				if (shipSection) {
					shipSection.hidden = true;
				}
				if (shipRow) {
					shipRow.hidden = true;
				}
				if (paymentSection) {
					paymentSection.hidden = true;
				}
				if (summarySection) {
					summarySection.hidden = true;
				}
				if (subtotalEl) {
					subtotalEl.textContent = noPriceLabel;
				}
				if (shipCostEl) {
					shipCostEl.textContent = noPriceLabel;
				}
				if (totalEl) {
					totalEl.textContent = noPriceLabel;
				}
				return;
			}

			if (qtyControl) {
				qtyControl.hidden = false;
			}
			if (shipSection) {
				shipSection.hidden = false;
			}
			if (shipRow) {
				shipRow.hidden = false;
			}
			if (paymentSection) {
				paymentSection.hidden = false;
			}
			if (summarySection) {
				summarySection.hidden = false;
			}

			const unitPrice = parseFloat(wrapper.getAttribute('data-unit-price') || '0') || 0;
			const qty = Math.max(1, parseInt(qtyInput ? qtyInput.value : '1', 10) || 1);
			const subtotal = unitPrice * qty;

			let shippingCost = 0;
			const selectedShip = form.querySelector('input[name="shipping_method"]:checked');
			if (selectedShip) {
				shippingCost = parseFloat(selectedShip.getAttribute('data-cost') || '0') || 0;
			}

			const grandTotal = subtotal + shippingCost;

			if (subtotalEl) {
				subtotalEl.textContent = formatPrice(subtotal);
			}
			if (shipCostEl) {
				shipCostEl.textContent =
					shippingCost > 0 ? formatPrice(shippingCost) : i18n.freeShipping || 'Miễn phí';
			}
			if (totalEl) {
				totalEl.textContent = formatPrice(grandTotal);
			}
		}

		/**
		 * Match selected variation attributes against WooCommerce variation list.
		 *
		 * @param {boolean} syncToOuter
		 */
		function matchVariation(syncToOuter) {
			if (!isVariable) {
				return;
			}

			const chosen = {};
			let allChosen = true;

			variationSelects.forEach((sel) => {
				const key = sel.getAttribute('data-attribute-name') || sel.name;
				chosen[key] = sel.value;
				if (!sel.value) {
					allChosen = false;
				}
			});

			// Sync popup selection back to Single Product form outside the popup.
			if (syncToOuter && !isSyncingVariations) {
				const outerForm = getOuterProductForm(activeProductId);
				if (outerForm) {
					isSyncingVariations = true;
					variationSelects.forEach((sel) => {
						const outerSel = outerForm.querySelector(
							'select[name="' + CSS.escape(sel.name) + '"]'
						);
						if (outerSel && outerSel.value !== sel.value) {
							outerSel.value = sel.value;
							outerSel.dispatchEvent(new Event('change', { bubbles: true }));
						}
					});
					isSyncingVariations = false;
				}
			}

			const statusEl = form.querySelector('[data-hiqb-variation-status]');
			const priceEl = form.querySelector('[data-hiqb-unit-price]');
			const imgEl = form.querySelector('.hiqb-product-image');
			const submitBtn = form.querySelector('[data-hiqb-submit]');

			if (!allChosen) {
				if (variationIdInput) {
					variationIdInput.value = '0';
				}
				if (statusEl) {
					statusEl.hidden = true;
				}
				if (submitBtn) {
					submitBtn.disabled = false;
				}
				updateTotals();
				return;
			}

			const matched = variations.find((v) => {
				const attrs = v.attributes || {};
				return Object.keys(attrs).every((attrKey) => {
					const expected = attrs[attrKey];
					// Empty string in WC variation means "Any <attribute>".
					return expected === '' || expected === chosen[attrKey];
				});
			});

			if (!matched) {
				if (variationIdInput) {
					variationIdInput.value = '0';
				}
				if (statusEl) {
					statusEl.textContent = i18n.selectVariation || 'Biến thể không khả dụng.';
					statusEl.hidden = false;
				}
				if (submitBtn) {
					submitBtn.disabled = true;
				}
				return;
			}

			if (variationIdInput) {
				variationIdInput.value = String(matched.variation_id);
			}

			const varHasPrice = matched.has_price !== false;
			wrapper.setAttribute('data-has-price', varHasPrice ? '1' : '0');
			wrapper.setAttribute('data-unit-price', String(matched.price || 0));
			if (priceEl) {
				priceEl.textContent =
					matched.price_html ||
					(varHasPrice ? formatPrice(matched.price || 0) : i18n.noPriceLabel || 'Liên hệ');
			}

			if (imgEl) {
				imgEl.src = matched.image_src || defaultImage;
			}

			if (!matched.is_in_stock) {
				if (statusEl) {
					statusEl.textContent = i18n.outOfStock || 'Hết hàng.';
					statusEl.hidden = false;
				}
				if (submitBtn) {
					submitBtn.disabled = true;
				}
			} else {
				if (statusEl) {
					statusEl.hidden = true;
				}
				if (submitBtn) {
					submitBtn.disabled = false;
				}
				if (qtyInput && matched.max_qty > 0) {
					qtyInput.max = String(matched.max_qty);
				}
			}

			updateTotals();
			recalculateShipping();
		}

		/**
		 * Fetch live shipping rates via independent backend calculator.
		 */
		async function recalculateShipping() {
			const hasPrice = wrapper.getAttribute('data-has-price') !== '0';
			const shippingList = form.querySelector('[data-hiqb-shipping-list]');
			if (!hasPrice || !shippingList) {
				updateTotals();
				return;
			}

			if (shippingAbortController) {
				shippingAbortController.abort();
			}
			shippingAbortController = new AbortController();

			const currentChoice =
				(form.querySelector('input[name="shipping_method"]:checked') || {}).value || '';

			shippingList.innerHTML =
				'<div class="hiqb-shipping-loading">' +
				escapeHtml(i18n.calculatingShip || 'Đang tính phí vận chuyển...') +
				'</div>';

			try {
				const data = await postAjax(
					'hiqb_calculate_shipping',
					{
						product_id: activeProductId,
						variation_id: variationIdInput ? variationIdInput.value : 0,
						quantity: qtyInput ? qtyInput.value : 1,
						province: provinceSelect ? provinceSelect.value : '',
						ward: wardSelect ? wardSelect.value : '',
					},
					shippingAbortController.signal
				);

				const rates = Array.isArray(data.rates) ? data.rates : [];
				if (!rates.length) {
					shippingList.innerHTML = '';
					updateTotals();
					return;
				}

				const html = rates
					.map((rate, idx) => {
						const checked =
							(currentChoice && rate.id === currentChoice) ||
							(!currentChoice && idx === 0);
						return (
							'<label class="hiqb-option-card">' +
							'<input type="radio" name="shipping_method" value="' +
							escapeHtml(rate.id) +
							'" data-cost="' +
							escapeHtml(String(rate.cost)) +
							'"' +
							(checked ? ' checked' : '') +
							'>' +
							'<span class="hiqb-option-card__label">' +
							escapeHtml(rate.label) +
							'</span>' +
							'<span class="hiqb-option-card__price">' +
							escapeHtml(rate.cost_formatted) +
							'</span>' +
							'</label>'
						);
					})
					.join('');

				shippingList.innerHTML = html;

				// Ensure at least one radio is checked.
				if (!shippingList.querySelector('input[name="shipping_method"]:checked')) {
					const firstRadio = shippingList.querySelector('input[name="shipping_method"]');
					if (firstRadio) {
						firstRadio.checked = true;
					}
				}

				updateTotals();
			} catch (err) {
				if (err.name === 'AbortError') {
					return;
				}
				updateTotals();
			}
		}

		// Quantity +/- buttons and input change.
		form.querySelectorAll('[data-hiqb-qty]').forEach((btn) => {
			btn.addEventListener('click', () => {
				if (!qtyInput) {
					return;
				}
				const delta = parseInt(btn.getAttribute('data-hiqb-qty') || '0', 10);
				const next = Math.max(1, (parseInt(qtyInput.value, 10) || 1) + delta);
				qtyInput.value = String(next);
				updateTotals();
				recalculateShipping();
			});
		});

		if (qtyInput) {
			qtyInput.addEventListener('change', () => {
				const next = Math.max(1, parseInt(qtyInput.value, 10) || 1);
				qtyInput.value = String(next);
				updateTotals();
				recalculateShipping();
			});
		}

		// Variation selects change inside popup.
		variationSelects.forEach((sel) => {
			sel.addEventListener('change', () => matchVariation(true));
		});

		// Shipping radio change.
		form.addEventListener('change', (evt) => {
			if (evt.target && evt.target.name === 'shipping_method') {
				updateTotals();
			}
		});

		// 2-level Vietnam cascading selects: Province (34) -> Ward/Commune/Special Zone (3,321).
		if (provinceSelect && wardSelect) {
			provinceSelect.addEventListener('change', async () => {
				const province = provinceSelect.value;
				wardSelect.innerHTML =
					'<option value="">' + escapeHtml(i18n.selectWard || 'Chọn Phường / Xã') + '</option>';
				wardSelect.disabled = true;
				if (wardCombo) {
					wardCombo.sync();
				}

				clearFieldError(form, 'province');
				clearFieldError(form, 'ward');

				if (!province) {
					recalculateShipping();
					return;
				}

				try {
					const data = await postAjax('hiqb_get_wards', { province });
					const items = Array.isArray(data.items) ? data.items : [];
					items.forEach((item) => {
						const opt = document.createElement('option');
						opt.value = item.code;
						opt.textContent = item.name;
						wardSelect.appendChild(opt);
					});
					wardSelect.disabled = items.length === 0;
				} catch (e) {
					wardSelect.disabled = true;
				}

				if (wardCombo) {
					wardCombo.sync();
				}

				recalculateShipping();
			});

			wardSelect.addEventListener('change', () => {
				clearFieldError(form, 'ward');
				if (wardCombo) {
					wardCombo.sync();
				}
				recalculateShipping();
			});
		}

		// Form submission with Vanilla JS validation.
		form.addEventListener('submit', async (evt) => {
			evt.preventDefault();
			clearAllErrors(form);

			if (!validateForm(form, isVariable)) {
				return;
			}

			const submitBtn = form.querySelector('[data-hiqb-submit]');
			const alertBox = form.querySelector('[data-hiqb-form-alert]');
			const originalLabel = submitBtn ? submitBtn.textContent : '';

			if (submitBtn) {
				submitBtn.disabled = true;
				submitBtn.textContent = i18n.submitting || 'Đang xử lý...';
			}
			if (alertBox) {
				alertBox.hidden = true;
			}

			try {
				const formData = new FormData(form);
				const result = await postAjax('hiqb_create_order', formData);

				if (result.redirect && result.thankyou_link) {
					window.location.href = result.thankyou_link;
					return;
				}

				renderSuccessState(result);
			} catch (err) {
				if (alertBox) {
					alertBox.textContent = err.message || i18n.genericError || 'Có lỗi xảy ra.';
					alertBox.hidden = false;
				}
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.textContent = originalLabel;
				}
			}
		});

		// Run initial variation match if preselected from Single Product page.
		if (isVariable) {
			matchVariation(false);
		} else {
			updateTotals();
		}
	}

	/**
	 * Validate form fields using HTML5 + Vietnamese phone rules.
	 *
	 * @param {HTMLFormElement} form
	 * @param {boolean} isVariable
	 * @returns {boolean}
	 */
	function validateForm(form, isVariable) {
		let valid = true;
		let firstInvalid = null;

		const markInvalid = (fieldName, message, el) => {
			valid = false;
			setFieldError(form, fieldName, message);
			if (!firstInvalid && el instanceof HTMLElement) {
				firstInvalid = el;
			}
		};

		if (isVariable) {
			const varIdInput = form.querySelector('input[name="variation_id"]');
			const varId = varIdInput ? parseInt(varIdInput.value, 10) : 0;
			if (!varId) {
				const statusEl = form.querySelector('[data-hiqb-variation-status]');
				if (statusEl) {
					statusEl.textContent = i18n.selectVariation || 'Vui lòng chọn biến thể.';
					statusEl.hidden = false;
				}
				const firstSel = form.querySelector('.hiqb-variation-select');
				valid = false;
				if (!firstInvalid && firstSel) {
					firstInvalid = firstSel;
				}
			}
		}

		const fullNameEl = form.querySelector('#hiqb-full-name');
		if (fullNameEl && !fullNameEl.value.trim()) {
			markInvalid('full_name', i18n.invalidName || 'Vui lòng nhập họ và tên.', fullNameEl);
		}

		const phoneEl = form.querySelector('#hiqb-phone');
		if (phoneEl) {
			let normalizedPhone = phoneEl.value.trim().replace(/[\s.\-()]+/g, '');
			if (normalizedPhone.startsWith('+84')) {
				normalizedPhone = '0' + normalizedPhone.slice(3);
			} else if (normalizedPhone.startsWith('84') && normalizedPhone.length >= 11) {
				normalizedPhone = '0' + normalizedPhone.slice(2);
			}

			if (!/^(0\d{9}|02\d{9})$/.test(normalizedPhone)) {
				markInvalid(
					'phone',
					i18n.invalidPhone || 'Số điện thoại Việt Nam không hợp lệ (ví dụ: 0912345678 hoặc +84 912345678).',
					phoneEl
				);
			}
		}

		const emailEl = form.querySelector('#hiqb-email');
		if (emailEl) {
			const emailVal = emailEl.value.trim();
			if (emailEl.required && !emailVal) {
				markInvalid('email', i18n.invalidEmail || 'Email không hợp lệ.', emailEl);
			} else if (emailVal && !emailEl.checkValidity()) {
				markInvalid('email', i18n.invalidEmail || 'Email không hợp lệ.', emailEl);
			}
		}

		const provinceEl = form.querySelector('#hiqb-province');
		if (provinceEl && provinceEl.required && !provinceEl.value) {
			markInvalid('province', i18n.requiredProvince || 'Vui lòng chọn Tỉnh / Thành phố.', provinceEl);
		}

		const wardEl = form.querySelector('#hiqb-ward');
		if (wardEl && wardEl.required && !wardEl.value) {
			markInvalid('ward', i18n.requiredWard || 'Vui lòng chọn Phường / Xã / Đặc khu.', wardEl);
		}

		const addressEl = form.querySelector('#hiqb-address');
		if (addressEl && addressEl.required && !addressEl.value.trim()) {
			markInvalid('address', i18n.requiredAddress || 'Vui lòng nhập địa chỉ.', addressEl);
		}

		if (firstInvalid) {
			if (firstInvalid.classList.contains('hiqb-sr-select') && firstInvalid.parentElement) {
				const comboTrigger = firstInvalid.parentElement.querySelector('.hiqb-combobox__trigger');
				if (comboTrigger instanceof HTMLElement) {
					comboTrigger.focus();
				}
			} else {
				firstInvalid.focus();
			}
		}

		return valid;
	}

	/**
	 * Display inline error message for a field.
	 *
	 * @param {HTMLFormElement} form
	 * @param {string} fieldName
	 * @param {string} message
	 */
	function setFieldError(form, fieldName, message) {
		const errEl = form.querySelector('[data-error-for="' + CSS.escape(fieldName) + '"]');
		if (errEl) {
			errEl.textContent = message;
		}
		const input = form.querySelector('[name="' + CSS.escape(fieldName) + '"]');
		if (input) {
			input.classList.add('hiqb-input--invalid');
			if (typeof input._hiqbSync === 'function') {
				input._hiqbSync();
			}
		}
	}

	/**
	 * Clear inline error for a specific field.
	 *
	 * @param {HTMLFormElement} form
	 * @param {string} fieldName
	 */
	function clearFieldError(form, fieldName) {
		const errEl = form.querySelector('[data-error-for="' + CSS.escape(fieldName) + '"]');
		if (errEl) {
			errEl.textContent = '';
		}
		const input = form.querySelector('[name="' + CSS.escape(fieldName) + '"]');
		if (input) {
			input.classList.remove('hiqb-input--invalid');
			if (typeof input._hiqbSync === 'function') {
				input._hiqbSync();
			}
		}
	}

	/**
	 * Clear all inline validation errors.
	 *
	 * @param {HTMLFormElement} form
	 */
	function clearAllErrors(form) {
		form.querySelectorAll('.hiqb-field-error').forEach((el) => {
			el.textContent = '';
		});
		form.querySelectorAll('.hiqb-input--invalid').forEach((el) => {
			el.classList.remove('hiqb-input--invalid');
			if (typeof el._hiqbSync === 'function') {
				el._hiqbSync();
			}
		});
	}

	/**
	 * Render celebratory order confirmation inside the popup.
	 *
	 * @param {Record<string, any>} data
	 */
	function renderSuccessState(data) {
		if (!modalBodyEl) {
			return;
		}

		const orderNumber = escapeHtml(data.order_number || data.order_id || '');
		const thankyouLink = data.thankyou_link ? String(data.thankyou_link) : '';
		const totalFormatted = escapeHtml(data.total_formatted || '');

		let bacsHtml = '';
		if (
			data.bacs_info &&
			(data.bacs_info.qr_url ||
				(Array.isArray(data.bacs_info.accounts) && data.bacs_info.accounts.length > 0))
		) {
			const qrUrl = data.bacs_info.qr_url ? String(data.bacs_info.qr_url) : '';
			const transferRef = data.bacs_info.transfer_ref ? String(data.bacs_info.transfer_ref) : '';
			const accounts = Array.isArray(data.bacs_info.accounts) ? data.bacs_info.accounts : [];

			const qrBlock = qrUrl
				? '<div class="hiqb-bacs-qr">' +
				  '<img src="' +
				  escapeHtml(qrUrl) +
				  '" alt="Mã QR chuyển khoản" loading="lazy">' +
				  '<span>Quét mã QR để chuyển khoản</span>' +
				  '</div>'
				: '';

			const accountRows = accounts
				.map((acc) => {
					return (
						(acc.bank_name
							? '<div class="hiqb-bacs-row"><span>Ngân hàng:</span><strong>' +
							  escapeHtml(acc.bank_name) +
							  '</strong></div>'
							: '') +
						(acc.account_name
							? '<div class="hiqb-bacs-row"><span>Chủ tài khoản:</span><strong>' +
							  escapeHtml(acc.account_name) +
							  '</strong></div>'
							: '') +
						(acc.account_number
							? '<div class="hiqb-bacs-row"><span>Số tài khoản:</span><span class="hiqb-bacs-copy-val"><strong>' +
							  escapeHtml(acc.account_number) +
							  '</strong><button type="button" class="hiqb-copy-btn" data-hiqb-copy="' +
							  escapeHtml(acc.account_number) +
							  '">Sao chép</button></span></div>'
							: '')
					);
				})
				.join('');

			const summaryRows =
				(totalFormatted
					? '<div class="hiqb-bacs-row"><span>Số tiền:</span><strong class="hiqb-bacs-amount">' +
					  totalFormatted +
					  '</strong></div>'
					: '') +
				(transferRef
					? '<div class="hiqb-bacs-row"><span>Nội dung CK:</span><span class="hiqb-bacs-copy-val"><strong>' +
					  escapeHtml(transferRef) +
					  '</strong><button type="button" class="hiqb-copy-btn" data-hiqb-copy="' +
					  escapeHtml(transferRef) +
					  '">Sao chép</button></span></div>'
					: '');

			bacsHtml =
				'<div class="hiqb-bacs-box">' +
				'<div class="hiqb-bacs-box__title">Thông tin chuyển khoản ngân hàng</div>' +
				(data.bacs_info.instructions
					? '<p class="hiqb-bacs-box__desc">' + escapeHtml(data.bacs_info.instructions) + '</p>'
					: '') +
				'<div class="hiqb-bacs-account">' +
				qrBlock +
				'<div class="hiqb-bacs-details">' +
				accountRows +
				summaryRows +
				'</div>' +
				'</div>' +
				'</div>';
		}

		modalBodyEl.innerHTML =
			'<div class="hiqb-success">' +
			'<div class="hiqb-success__icon" aria-hidden="true">&#10003;</div>' +
			'<h2 class="hiqb-success__title">' +
			escapeHtml(i18n.orderSuccessTitle || 'Đặt hàng thành công!') +
			'</h2>' +
			'<div class="hiqb-success__badge">' +
			escapeHtml(i18n.orderNumberLabel || 'Mã đơn hàng:') +
			' <strong>#' +
			orderNumber +
			'</strong></div>' +
			'<div class="hiqb-success__message">' +
			(data.message || '') +
			'</div>' +
			bacsHtml +
			'<div class="hiqb-success__actions">' +
			(thankyouLink
				? '<a class="hiqb-btn hiqb-btn--primary" href="' +
				  escapeHtml(thankyouLink) +
				  '">' +
				  escapeHtml(i18n.viewOrderDetail || 'Xem chi tiết đơn hàng') +
				  '</a>'
				: '') +
			'<button type="button" class="hiqb-btn hiqb-btn--secondary" data-hiqb-close>' +
			escapeHtml(i18n.continueShopping || 'Tiếp tục mua sắm') +
			'</button>' +
			'</div>' +
			'</div>';

		modalBodyEl.querySelectorAll('[data-hiqb-copy]').forEach((btn) => {
			btn.addEventListener('click', async () => {
				const text = btn.getAttribute('data-hiqb-copy') || '';
				if (!text) {
					return;
				}
				try {
					await navigator.clipboard.writeText(text);
					const orig = btn.textContent;
					btn.textContent = 'Đã chép';
					btn.classList.add('hiqb-copy-btn--copied');
					window.setTimeout(() => {
						btn.textContent = orig;
						btn.classList.remove('hiqb-copy-btn--copied');
					}, 1600);
				} catch (e) {
					// Clipboard API unavailable in insecure context.
				}
			});
		});
	}

	/**
	 * Two-way sync: listen to variation changes on Single Product page outside popup.
	 */
	function bindOuterVariationSync() {
		document.addEventListener('change', (evt) => {
			if (isSyncingVariations || !modalEl || modalEl.hidden || !activeProductId) {
				return;
			}
			const target = evt.target;
			if (!(target instanceof HTMLSelectElement)) {
				return;
			}
			if (!target.closest('form.variations_form') || !target.name.startsWith('attribute_')) {
				return;
			}

			const popupSel = modalEl.querySelector(
				'.hiqb-variation-select[name="' + CSS.escape(target.name) + '"]'
			);
			if (popupSel instanceof HTMLSelectElement && popupSel.value !== target.value) {
				isSyncingVariations = true;
				popupSel.value = target.value;
				popupSel.dispatchEvent(new Event('change', { bubbles: true }));
				isSyncingVariations = false;
			}
		});
	}

	/**
	 * Bootstrap event listeners once DOM is ready.
	 */
	function boot() {
		modalEl = document.getElementById('hiqb-modal');
		modalBodyEl = document.getElementById('hiqb-modal-body');

		// Delegate click on all Quick Buy buttons (Single, Loop, Shortcode, dynamic AJAX loops).
		document.addEventListener('click', (evt) => {
			const target = evt.target;
			if (!(target instanceof Element)) {
				return;
			}

			const closeTrigger = target.closest('[data-hiqb-close]');
			if (closeTrigger && modalEl && !modalEl.hidden) {
				evt.preventDefault();
				closeModal();
				return;
			}

			const triggerBtn = target.closest('.hiqb-trigger-btn');
			if (triggerBtn) {
				evt.preventDefault();
				const productId = parseInt(triggerBtn.getAttribute('data-product-id') || '0', 10);
				handleTriggerClick(productId);
			}
		});

		// Close modal on Escape key.
		document.addEventListener('keydown', (evt) => {
			if (evt.key === 'Escape' && modalEl && !modalEl.hidden) {
				closeModal();
			}
		});

		bindOuterVariationSync();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();

