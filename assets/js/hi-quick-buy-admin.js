/**
 * HI Quick-Buy Admin Media Picker (Vanilla JS + wp.media)
 *
 * @package HIQuickBuy
 */

(function () {
	'use strict';

	const adminI18n = (window.hiqbAdminConfig && window.hiqbAdminConfig.i18n) || {};

	document.addEventListener('DOMContentLoaded', () => {
		document.querySelectorAll('[data-hiqb-media-field]').forEach((field) => {
			const input = field.querySelector('[data-hiqb-media-input]');
			const preview = field.querySelector('[data-hiqb-media-preview]');
			const selectBtn = field.querySelector('[data-hiqb-media-select]');
			const removeBtn = field.querySelector('[data-hiqb-media-remove]');

			if (!input || !preview || !selectBtn || !removeBtn) {
				return;
			}

			let frame = null;

			selectBtn.addEventListener('click', (evt) => {
				evt.preventDefault();

				if (typeof window.wp === 'undefined' || !window.wp.media) {
					return;
				}

				if (!frame) {
					frame = window.wp.media({
						title: adminI18n.mediaTitle || 'Chọn ảnh mã QR chuyển khoản',
						button: { text: adminI18n.mediaButton || 'Sử dụng ảnh này' },
						library: { type: 'image' },
						multiple: false,
					});

					frame.on('select', () => {
						const attachment = frame.state().get('selection').first().toJSON();
						const url =
							(attachment.sizes &&
								attachment.sizes.medium &&
								attachment.sizes.medium.url) ||
							attachment.url ||
							'';

						input.value = String(attachment.id || 0);
						preview.innerHTML = '';
						if (url) {
							const img = document.createElement('img');
							img.src = url;
							img.alt = attachment.alt || adminI18n.qrAlt || 'Mã QR chuyển khoản';
							preview.appendChild(img);
							preview.hidden = false;
							removeBtn.hidden = false;
						}
					});
				}

				frame.open();
			});

			removeBtn.addEventListener('click', (evt) => {
				evt.preventDefault();
				input.value = '0';
				preview.innerHTML = '';
				preview.hidden = true;
				removeBtn.hidden = true;
			});
		});

		// Sync master "enabled" toggle with Single Product & Shop Loop placement toggles.
		const enabledMaster = document.querySelector('input[name="hiqb_settings[enabled]"]');
		const singleToggle = document.querySelector(
			'input[name="hiqb_settings[show_single_product]"]'
		);
		const loopToggle = document.querySelector('input[name="hiqb_settings[show_shop_loop]"]');

		if (enabledMaster && (singleToggle || loopToggle)) {
			const placementToggles = [singleToggle, loopToggle].filter(Boolean);

			const syncPlacementRows = () => {
				const isOn = enabledMaster.checked;
				placementToggles.forEach((el) => {
					const row = el.closest('tr');
					if (row) {
						row.classList.toggle('hiqb-row--muted', !isOn);
					}
				});
			};

			enabledMaster.addEventListener('change', () => {
				if (!enabledMaster.checked) {
					placementToggles.forEach((el) => {
						el.checked = false;
					});
				} else if (placementToggles.every((el) => !el.checked) && singleToggle) {
					singleToggle.checked = true;
				}
				syncPlacementRows();
			});

			placementToggles.forEach((subToggle) => {
				subToggle.addEventListener('change', () => {
					if (subToggle.checked && !enabledMaster.checked) {
						enabledMaster.checked = true;
						syncPlacementRows();
					}
				});
			});

			syncPlacementRows();
		}

		// Sync master "show_payment_methods" toggle with COD & BACS sub-toggles.
		const paymentMaster = document.querySelector(
			'input[name="hiqb_settings[show_payment_methods]"]'
		);
		const codToggle = document.querySelector('input[name="hiqb_settings[enable_cod]"]');
		const bacsToggle = document.querySelector('input[name="hiqb_settings[enable_bacs]"]');

		if (paymentMaster && (codToggle || bacsToggle)) {
			paymentMaster.addEventListener('change', () => {
				if (!paymentMaster.checked) {
					if (codToggle) {
						codToggle.checked = false;
					}
					if (bacsToggle) {
						bacsToggle.checked = false;
					}
				} else if (
					(!codToggle || !codToggle.checked) &&
					(!bacsToggle || !bacsToggle.checked)
				) {
					// Re-enable COD by default when turning payment selection back on.
					if (codToggle) {
						codToggle.checked = true;
					}
				}
			});

			[codToggle, bacsToggle].forEach((subToggle) => {
				if (!subToggle) {
					return;
				}
				subToggle.addEventListener('change', () => {
					if (subToggle.checked && !paymentMaster.checked) {
						paymentMaster.checked = true;
					}
				});
			});
		}
	});
})();

