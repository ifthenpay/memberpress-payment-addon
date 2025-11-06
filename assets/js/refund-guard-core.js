(function (window, $) {
	/**
	 * RefundGuard orchestrates guarded refund flows:
	 * - Decorates refund links
	 * - Handles mass and single refund sequences
	 * - Delegates AJAX to transport
	 * - Prompts for verification code
	 */
	'use strict';
	const ns = (window.IFTPRefundGuard = window.IFTPRefundGuard || {});
	const { norm, assertConfig } = ns.utils;
	const { showModal, showAmountModal } = ns.modals;

	class RefundGuard {
		constructor(cfg) {
			assertConfig(cfg);
			this.cfg = cfg;
			this.gatewayCell = norm(cfg.gateway.cell);
			this.t = cfg.i18n;
			this.logo = (cfg.brand && cfg.brand.logo) || '';
			this.$table = $('.wp_list_mepr_transactions');
			this.$tbody = this.$table.find('tbody');
			this.dialogId = 'iftp-refund-dialog';
			this.dialogBuilt = false;
			this._observer = null;
			this._debounce = null;
			// ajax transport
			this.transport = new ns.ajax.RefundAjaxTransport(cfg, this.t);
		}

		init() {
			if (!this.$table.length) {
				return;
			}
			this.decorateRows();
			this.observeTbody();
		}

		observeTbody() {
			if (!this.$tbody.length || !('MutationObserver' in window)) {
				return;
			}
			this._observer = new MutationObserver(() => {
				clearTimeout(this._debounce);
				this._debounce = setTimeout(() => this.decorateRows(), 60);
			});
			this._observer.observe(this.$tbody.get(0), {
				childList: true,
				subtree: true,
			});
		}

		decorateRows() {
			const $rows = this.$tbody.find('tr[id^="record_"]');
			$rows.each((_, row) => {
				const $row = $(row);
				const cellText = norm(
					$row
						.find('td.col_payment_system.column-col_payment_system')
						.first()
						.text()
				);
				if (cellText === this.gatewayCell) {
					this.guardLinksInRow($row);
				}
			});
		}

		guardLinksInRow($row) {
			const $links = $row.find(
				'a.mepr-refund-txn, a.mepr-refund-txn-and-cancel-sub'
			);
			$links.each((_, link) => {
				const $link = $(link);
				if ($link.data('iftpGuarded')) {
					return;
				}
				const $proxy = $link.clone(false).addClass('iftp-guard-proxy');
				$link
					.addClass('iftp-guard-original')
					.hide()
					.data('iftpGuarded', true);
				$link.after($proxy);
				if ($link.hasClass('mepr-refund-txn-and-cancel-sub')) {
					$proxy.on('click', (e) =>
						this.handleRefundAndCancelClick(e, $row, $proxy, $link)
					);
				} else {
					$proxy.on('click', (e) =>
						this.handleGuardClick(e, $row, $proxy, $link)
					);
				}
			});
		}

		async handleRefundAndCancelClick(e, $row, $proxy, $original) {
			/**
			 * Flow: consent modal -> optional amount modal -> request code -> verify -> trigger original link.
			 * @param {Event} e Click event
			 */
			e.preventDefault();
			if ($proxy.data('busy')) {
				return;
			}
			let txnId, transNum;
			try {
				({ txnId, transNum } = this.extractTxnContext($row, $proxy));
			} catch (ctxErr) {
				alert(ctxErr.message);
				return;
			}
			$proxy.data('busy', true);
			try {
				const response =
					await this.transport.fetchRemainingTransactions(
						transNum,
						txnId
					);
				const txns = response.data;
				await showModal({
					id: 'iftp-refund-cancel-modal',
					title: this.t.consent_title,
					desc: this.t.consent_desc,
					content: this.renderTxnsTable(txns),
					logo: this.logo,
					okText: this.t.consent_ok,
					cancelText: this.t.consent_cancel,
					cancelError: this.t.error_cancelled,
				});
				const totalFormatted = (txns && txns.total_amount) || '';
				const cap = parseFloat(
					String(totalFormatted)
						.replace(/[^0-9.,]/g, '')
						.replace(/,/g, '.')
				);
				if (!isNaN(cap) && cap > 0) {
					try {
						const { amount } = await showAmountModal({
							context: 'mass',
							capAmount: cap,
							currency: '€',
							logo: this.logo,
							i18n: this.t,
						});
						await this.transport.setRefundAmount(
							transNum,
							txnId,
							amount,
							{
								context: 'mass',
								cap,
							}
						);
					} catch (amtErr) {
						throw amtErr; // cancelled or validation error aborts flow
					}
				}
				await this.transport.requestCode(transNum, txnId);
				await this.promptAndVerify(transNum, txnId);
				const href = $original.attr('href') || '';
				if ($original.length) {
					$original.get(0).click();
				} else if (href) {
					window.location.assign(href);
				}
			} catch (err) {
				alert(err.message);
			} finally {
				$proxy.data('busy', false);
			}
		}

		renderTxnsTable(payload) {
			const t = this.t;
			const list = (payload && payload.txns) || [];
			const totalAmount = (payload && payload.total_amount) || '';
			if (!list || !Array.isArray(list) || list.length === 0) {
				return `<p>${t.consent_none}</p>`;
			}
			const rows = list
				.map(
					(txn) =>
						`<tr><td>${txn.id || ''}</td><td>${txn.transaction || ''}</td><td>${txn.starts || ''}</td><td>${txn.ends || ''}</td><td>${txn.amount || ''}</td></tr>`
				)
				.join('');
			return `
				<div class="iftp-txns-table-wrapper">
					<div class="iftp-txns-table-scroll">
						<table class="iftp-txns-table-main">
							<colgroup>
								<col class="iftp-col-id">
								<col class="iftp-col-transaction">
								<col class="iftp-col-starts">
								<col class="iftp-col-ends">
								<col class="iftp-col-amount">
							</colgroup>
							<thead>
								<tr>
									<th>${t.consent_th_id}</th>
									<th>${t.consent_th_transaction}</th>
									<th>${t.consent_th_starts}</th>
									<th>${t.consent_th_ends}</th>
									<th>${t.consent_th_amount}</th>
								</tr>
							</thead>
							<tbody>${rows}</tbody>
						</table>
					</div>
					<div class="iftp-txns-total-row">
						<span class="iftp-txns-total-label">${t.consent_total_label}</span>
						<span class="iftp-txns-total-amount">${totalAmount}</span>
					</div>
				</div>
			`;
		}

		async handleGuardClick(e, $row, $proxy, $original) {
			/**
			 * Single refund flow: fetch cap -> amount modal -> request code -> verify -> trigger original link.
			 * @param {Event} e Click event
			 */
			e.preventDefault();
			if ($proxy.data('busy')) {
				return;
			}
			let txnId, transNum;
			try {
				({ txnId, transNum } = this.extractTxnContext($row, $proxy));
			} catch (ctxErr) {
				alert(ctxErr.message);
				return;
			}
			$proxy.data('busy', true);
			try {
				try {
					const amtRes = await this.transport.fetchSingleRefundAmount(
						transNum,
						txnId
					);
					const capAmount = amtRes?.data?.amount;
					if (typeof capAmount === 'number' && capAmount > 0) {
						try {
							const { amount } = await showAmountModal({
								context: 'single',
								capAmount,
								currency: '€',
								logo: this.logo,
								i18n: this.t,
							});
							await this.transport.setRefundAmount(
								transNum,
								txnId,
								amount,
								{ context: 'single' }
							);
						} catch (amtErr) {
							throw amtErr; // abort on cancel/error
						}
					}
				} catch (fetchErr) {
					alert(fetchErr.message || this.t.error_amount || 'Error');
					throw fetchErr; // abort flow
				}
				await this.transport.requestCode(transNum, txnId);
				await this.promptAndVerify(transNum, txnId);
				const href = $original.attr('href') || '';
				if ($original.length) {
					$original.get(0).click();
				} else if (href) {
					window.location.assign(href);
				}
			} catch (err) {
				alert(err.message);
			} finally {
				$proxy.data('busy', false);
			}
		}

		extractTxnContext($row, $link) {
			// Both transaction ID and transaction number are required for all flows.
			const rawId =
				$link.data('value') ||
				String($link.attr('id') || '').match(/(\d+)$/)?.[1] ||
				null;
			const transNum = (
				$row.find('.col_trans_num b').first().text() || ''
			).trim();
			if (!rawId || !transNum) {
				throw new Error('RefundGuard: missing transaction context');
			}
			return { txnId: Number(rawId), transNum };
		}

		promptAndVerify(transNum) {
			this.ensureDialog();
			const dlg = document.getElementById(this.dialogId);
			const input = dlg.querySelector('#iftp-refund-code');
			const okBtn = dlg.querySelector('.iftp-refund-ok');
			const noBtn = dlg.querySelector('.iftp-refund-cancel');
			const title = dlg.querySelector('.iftp-dialog-title');
			const msgEl = dlg.querySelector('.iftp-dialog-msg-text');
			const infoBtn = dlg.querySelector('.iftp-info-icon');
			let errEl = dlg.querySelector('.iftp-dialog-error');
			if (!errEl) {
				errEl = document.createElement('p');
				errEl.className = 'iftp-dialog-error';
				errEl.setAttribute('aria-live', 'polite');
				const inputField = dlg.querySelector('#iftp-refund-code');
				inputField.insertAdjacentElement('afterend', errEl);
			}
			title.textContent = this.t.title;
			msgEl.textContent = this.t.prompt;
			if (infoBtn && this.t.after_submit_notice) {
				infoBtn.setAttribute('title', this.t.after_submit_notice);
				infoBtn.setAttribute('aria-label', this.t.after_submit_notice);
			}
			okBtn.textContent = this.t.confirm;
			noBtn.textContent = this.t.cancel;
			okBtn.setAttribute('type', 'button');
			noBtn.setAttribute('type', 'button');
			input.value = '';
			errEl.textContent = '';
			const setBusy = (on) => {
				okBtn.disabled = on;
				noBtn.disabled = on;
				input.disabled = on;
			};
			return new Promise((resolve, reject) => {
				const onCancel = () => {
					teardown();
					dlg.close();
					reject(new Error(this.t.error_no_code));
				};
				const digitFilter = () => {
					let v = input.value.replace(/\D+/g, '');
					if (v.length > 6) {
						v = v.slice(0, 6);
					}
					if (input.value !== v) {
						input.value = v;
					}
					if (v.length === 6) {
						onOk();
					}
				};
				input.addEventListener('input', digitFilter);
				const onOk = async () => {
					const code = input.value.trim();
					if (!code) {
						errEl.textContent = this.t.error_no_code;
						input.focus();
						return;
					}
					setBusy(true);
					try {
						await this.transport.verifyCode(transNum, code);
						teardown();
						dlg.close();
						resolve();
					} catch (e) {
						errEl.textContent = e?.message || this.t.error_invalid;
						setBusy(false);
						input.focus();
						input.select();
					}
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
				const teardown = () => {
					okBtn.removeEventListener('click', onOk);
					noBtn.removeEventListener('click', onCancel);
					dlg.removeEventListener('keydown', onKey);
					input.removeEventListener('input', digitFilter);
				};
				okBtn.addEventListener('click', onOk);
				noBtn.addEventListener('click', onCancel);
				dlg.addEventListener('keydown', onKey);
				dlg.showModal();
				setTimeout(() => input.focus(), 0);
			});
		}

		ensureDialog() {
			if (this.dialogBuilt) {
				return;
			}
			const safeLogo = ns.utils?.logoHtml
				? ns.utils.logoHtml(this.logo)
				: '';
			const dlg = document.createElement('dialog');
			dlg.id = this.dialogId;
			dlg.setAttribute('role', 'dialog');
			dlg.setAttribute('aria-modal', 'true');
			dlg.setAttribute('aria-labelledby', 'iftp-refund-title');
			dlg.innerHTML = `
			<form method="dialog" class="iftp-dialog-box">
				<div class="iftp-dialog-header">${safeLogo}<h3 id="iftp-refund-title" class="iftp-dialog-title"></h3></div>
				<p class="iftp-dialog-msg"><span class="iftp-dialog-msg-text"></span><button type="button" class="iftp-info-icon" title="" aria-label="" tabindex="0">?</button></p>
				<label class="screen-reader-text" for="iftp-refund-code">Verification code</label>
				<input id="iftp-refund-code" type="text" inputmode="numeric" pattern="\\d{6}" maxlength="6" autocomplete="one-time-code" />
				<p class="iftp-dialog-error" aria-live="polite"></p>
				<menu class="iftp-dialog-actions">
					<button class="button iftp-refund-cancel"></button>
					<button class="button button-primary iftp-refund-ok"></button>
				</menu>
			</form>`;
			document.body.appendChild(dlg);
			this.dialogBuilt = true;
		}
	}

	ns.RefundGuard = RefundGuard;
})(window, jQuery);
