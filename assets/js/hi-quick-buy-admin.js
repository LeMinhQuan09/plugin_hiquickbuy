/**
 * HI Quick-Buy Admin Media Picker (Vanilla JS + wp.media)
 *
 * @package HIQuickBuy
 */

(function () {
	'use strict';

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
						title: 'Chọn ảnh mã QR chuyển khoản',
						button: { text: 'Sử dụng ảnh này' },
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
							img.alt = attachment.alt || 'Mã QR chuyển khoản';
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
	});
})();

