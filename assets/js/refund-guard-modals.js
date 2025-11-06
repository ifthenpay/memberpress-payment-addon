(function (window) {
	/**
	 * UI modal helpers.
	 * showModal: generic confirm/cancel dialog
	 * showAmountModal: refund amount selection with inline validation
	 */
	'use strict';

	const ns = (window.IFTPRefundGuard = window.IFTPRefundGuard || {});
	const { logoHtml } = ns.utils || {};

	function showModal({
		id,
		title,
		desc,
		content,
		okText,
		cancelText,
		cancelError,
		logo,
	}) {
		return new Promise((resolve, reject) => {
			let dlg = document.getElementById(id);
			const safeLogo = logoHtml ? logoHtml(logo) : '';
			if (!dlg) {
				dlg = document.createElement('dialog');
				dlg.id = id;
				dlg.setAttribute('role', 'dialog');
				dlg.setAttribute('aria-modal', 'true');
				dlg.innerHTML = `
				<form method="dialog" class="iftp-dialog-box">
					<div class="iftp-dialog-header">${safeLogo}<h3 class="iftp-dialog-title">${title}</h3></div>
					<p class="iftp-dialog-msg">${desc}</p>
					<div class="iftp-txns-table">${content}</div>
					<menu class="iftp-dialog-actions">
						<button class="button iftp-refund-cancel">${cancelText}</button>
						<button class="button button-primary iftp-refund-ok">${okText}</button>
					</menu>
				</form>`;
				document.body.appendChild(dlg);
			} else {
				const header = dlg.querySelector('.iftp-dialog-header');
				if (header) {
					const img = header.querySelector('.iftp-dialog-logo');
					if (safeLogo) {
						if (img) {
							img.setAttribute('src', logo);
						} else {
							header.insertAdjacentHTML('afterbegin', safeLogo);
						}
					} else if (img) {
						img.remove();
					}
				}
				const titleEl = dlg.querySelector('.iftp-dialog-title');
				if (titleEl) {
					titleEl.textContent = title;
				}
				const msgEl = dlg.querySelector('.iftp-dialog-msg');
				if (msgEl) {
					msgEl.textContent = desc;
				}
				dlg.querySelector('.iftp-txns-table').innerHTML = content;
			}
			const okBtn = dlg.querySelector('.iftp-refund-ok');
			const cancelBtn = dlg.querySelector('.iftp-refund-cancel');
			okBtn.setAttribute('type', 'button');
			cancelBtn.setAttribute('type', 'button');
			const teardown = () => {
				okBtn.removeEventListener('click', onOk);
				cancelBtn.removeEventListener('click', onCancel);
			};
			const onOk = () => {
				teardown();
				dlg.close();
				resolve();
			};
			const onCancel = () => {
				teardown();
				dlg.close();
				reject(new Error(cancelError || cancelText));
			};
			okBtn.addEventListener('click', onOk);
			cancelBtn.addEventListener('click', onCancel);
			dlg.showModal();
		});
	}

	function showAmountModal({
		context,
		capAmount,
		currency = '€',
		logo,
		i18n,
	}) {
		return new Promise((resolve, reject) => {
			const id = 'iftp-refund-amount-modal';
			let dlg = document.getElementById(id);
			const safeLogo = logoHtml ? logoHtml(logo) : '';
			let desc;
			if (context === 'mass') {
				desc = i18n.amount_desc_mass;
			} else {
				desc = i18n.amount_desc_single;
			}
			if (!dlg) {
				dlg = document.createElement('dialog');
				dlg.id = id;
				dlg.setAttribute('role', 'dialog');
				dlg.setAttribute('aria-modal', 'true');
				dlg.innerHTML = `
				<form method="dialog" class="iftp-dialog-box">
					<div class="iftp-dialog-header">${safeLogo}<h3 class="iftp-dialog-title">${i18n.amount_title}</h3></div>
					<p class="iftp-dialog-msg">${desc}</p>
					<label for="iftp-refund-amount" class="screen-reader-text">${i18n.amount_input_label}</label>
					<div class="iftp-refund-amount-field">
						<span class="iftp-refund-amount-currency">${currency}</span>
						<input id="iftp-refund-amount" type="text" inputmode="decimal" autocomplete="off" />
					</div>
					<p class="iftp-dialog-error" aria-live="polite"></p>
					<menu class="iftp-dialog-actions">
						<button class="button iftp-refund-cancel" type="button">${i18n.amount_cancel}</button>
						<button class="button button-primary iftp-refund-ok" type="button">${i18n.amount_confirm}</button>
					</menu>
				</form>`;
				document.body.appendChild(dlg);
			} else {
				const titleEl = dlg.querySelector('.iftp-dialog-title');
				if (titleEl) {
					titleEl.textContent = i18n.amount_title;
				}
				const msgEl = dlg.querySelector('.iftp-dialog-msg');
				if (msgEl) {
					msgEl.textContent = desc;
				}
			}
			const input = dlg.querySelector('#iftp-refund-amount');
			if (capAmount) {
				const formattedCap = Number(capAmount).toFixed(2);
				// Always refresh placeholder to current cap value.
				input.setAttribute('placeholder', formattedCap);
				// Pre-populate with cap while retaining placeholder (placeholder for accessibility hint).
				input.value = formattedCap;
			} else {
				// Clear placeholder/value if no cap (defensive; current flows always supply cap).
				input.removeAttribute('placeholder');
				input.value = '';
			}
			const okBtn = dlg.querySelector('.iftp-refund-ok');
			const cancelBtn = dlg.querySelector('.iftp-refund-cancel');
			const errEl = dlg.querySelector('.iftp-dialog-error');
			// Value already set above based on cap presence.
			errEl.textContent = '';
			const max = Number(capAmount);
			input.addEventListener('input', () => {
				let v = input.value.replace(/,/g, '.').replace(/[^0-9.]/g, '');
				const parts = v.split('.');
				if (parts.length > 2) {
					v = parts[0] + '.' + parts.slice(1).join('');
				}
				const [i, d] = v.split('.');
				if (typeof d !== 'undefined') {
					v = i + '.' + d.slice(0, 2);
				}
				input.value = v;
			});
			const validate = () => {
				const raw = input.value.replace(/,/g, '.').trim();
				if (raw === '') {
					errEl.textContent = i18n.amount_error_empty;
					return null;
				}
				if (!/^\d+(\.\d{1,2})?$/.test(raw)) {
					errEl.textContent = i18n.amount_error_invalid;
					return null;
				}
				const val = Number(raw);
				if (!(val > 0)) {
					errEl.textContent = i18n.amount_error_invalid;
					return null;
				}
				if (val > max + 1e-9) {
					errEl.textContent = i18n.amount_error_exceeds;
					return null;
				}
				errEl.textContent = '';
				return val;
			};
			const teardown = () => {
				okBtn.removeEventListener('click', onOk);
				cancelBtn.removeEventListener('click', onCancel);
				dlg.removeEventListener('keydown', onKey);
			};
			const onOk = () => {
				const val = validate();
				if (val === null) {
					input.focus();
					return;
				}
				teardown();
				dlg.close();
				resolve({ amount: val });
			};
			const onCancel = () => {
				teardown();
				dlg.close();
				reject(new Error(i18n.error_cancelled || i18n.amount_cancel));
			};
			const onKey = (ev) => {
				if (ev.key === 'Enter') {
					ev.preventDefault();
					onOk();
				} else if (ev.key === 'Escape') {
					ev.preventDefault();
					onCancel();
				}
			};
			okBtn.addEventListener('click', onOk);
			cancelBtn.addEventListener('click', onCancel);
			dlg.addEventListener('keydown', onKey);
			dlg.showModal();
			setTimeout(() => {
				input.focus();
				// Select all so user can immediately type over the pre-filled cap
				try {
					input.select();
				} catch (_) {
					/* noop */
				}
			}, 0);
		});
	}

	ns.modals = { showModal, showAmountModal };
})(window);
