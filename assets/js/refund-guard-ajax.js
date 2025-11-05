(function (window) {
	/**
	 * AJAX transport layer encapsulating all server interactions.
	 * Methods throw Error with i18n-friendly message on failure.
	 */
	'use strict';
	const ns = (window.IFTPRefundGuard = window.IFTPRefundGuard || {});

	class RefundAjaxTransport {
		/**
		 * Constructor expects localized config + i18n dictionary.
		 * @param {Object} cfg Localized config (expects ajax section)
		 * @param {Object} t   i18n dictionary
		 */
		constructor(cfg, t) {
			if (!cfg || !cfg.ajax || !t) {
				throw new Error(
					'RefundAjaxTransport requires ajax cfg and i18n'
				);
			}
			this.ajax = cfg.ajax;
			this.t = t;
		}

		/**
		 * Low-level POST helper; throws Error on failure.
		 * @param {string} routeKey      Key in ajax.routes
		 * @param {Object} params        POST body parameters
		 * @param {string} [fallbackKey] i18n key for generic error message
		 * @return {Promise<Object>} Parsed JSON response
		 */
		async post(routeKey, params, fallbackKey) {
			const action = this.ajax.routes?.[routeKey];
			if (!action) {
				throw new Error(this.t.error_route || 'Unknown AJAX route');
			}
			const body = new URLSearchParams({
				_ajax_nonce: this.ajax.nonce,
				action,
				...params,
			}).toString();
			let res;
			let data;
			try {
				res = await fetch(this.ajax.url, {
					method: 'POST',
					headers: {
						'Content-Type':
							'application/x-www-form-urlencoded; charset=UTF-8',
					},
					credentials: 'same-origin',
					body,
				});
				data = await res.json();
			} catch {
				throw new Error(this.t.error_network);
			}
			if (!res.ok || !data?.success) {
				const fallback =
					(fallbackKey && this.t[fallbackKey]) ||
					this.t.error_invalid;
				const message = data?.data || fallback;
				throw new Error(message);
			}
			return data;
		}

		/**
		 * Fetch remaining transactions payload (mass consent).
		 * @param {string}      transNum Transaction number
		 * @param {number|null} txnId    MemberPress transaction ID
		 * @return {Promise<Object>} Response data
		 */
		fetchRemainingTransactions(transNum, txnId) {
			return this.post(
				'txns_modal',
				{
					trans_num: transNum,
					trans_id: String(txnId),
				},
				'error_txns'
			);
		}

		/**
		 * Fetch single transaction refund cap.
		 * @param {string}      transNum Transaction number
		 * @param {number|null} txnId    MemberPress transaction ID
		 * @return {Promise<Object>} Response data
		 */
		fetchSingleRefundAmount(transNum, txnId) {
			return this.post(
				'amount',
				{
					trans_num: transNum,
					trans_id: String(txnId),
				},
				'error_amount'
			);
		}

		/**
		 * Request a verification code be sent.
		 * @param {string}      transNum Transaction number
		 * @param {number|null} transId  MemberPress transaction ID
		 * @return {Promise<Object>} Response data
		 */
		requestCode(transNum, transId) {
			return this.post(
				'send',
				{
					trans_num: transNum,
					trans_id: String(transId),
				},
				'error_send'
			);
		}

		/**
		 * Verify a previously sent 6-digit code.
		 * @param {string} transNum Transaction number
		 * @param {string} code     Six-digit verification code
		 * @return {Promise<Object>} Response data
		 */
		verifyCode(transNum, code) {
			return this.post(
				'verify',
				{
					trans_num: transNum,
					code,
				},
				'error_invalid'
			);
		}

		/**
		 * Persist chosen refund amount server-side (transient).
		 * @param {string}                        transNum Transaction number
		 * @param {number|null}                   transId  MemberPress transaction ID
		 * @param {number}                        amount   Refund amount (already validated client-side)
		 * @param {{context?:string,cap?:number}} [extra]  Optional context/cap payload
		 * @return {Promise<Object>} Response data
		 */
		setRefundAmount(transNum, transId, amount, extra) {
			// amount already validated client-side; send raw value
			const params = {
				trans_num: transNum,
				chosen_amount: String(amount),
				...(extra?.context ? { context: extra.context } : {}),
				...(extra?.cap ? { cap: String(extra.cap) } : {}),
			};
			if (extra?.context !== 'mass' && transId) {
				params.trans_id = String(transId);
			}
			return this.post('set_amount', params, 'error_amount');
		}
	}

	ns.ajax = { RefundAjaxTransport };
})(window);
