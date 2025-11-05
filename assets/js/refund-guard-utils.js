(function (window) {
	// Utility helpers exposed as window.IFTPRefundGuard.utils
	'use strict';
	window.IFTPRefundGuard = window.IFTPRefundGuard || {};

	/**
	 * Normalize a string by collapsing whitespace.
	 * @param {string} s
	 * @return {string} Normalized string
	 */
	const norm = (s) => String(s).replace(/\s+/g, ' ').trim();

	/**
	 * Build logo <img> HTML if a sane absolute/relative URL-like path string.
	 * @param {string} logo
	 * @return {string} HTML or empty string
	 */
	function logoHtml(logo) {
		return logo && typeof logo === 'string' && /^(https?:)?\//.test(logo)
			? `<img class="iftp-dialog-logo" src="${logo}" alt="">`
			: '';
	}

	/**
	 * Basic runtime configuration validation; throws on missing required sections.
	 * @param {Object} cfg Localized config object
	 */
	function assertConfig(cfg) {
		if (!cfg || !cfg.ajax || !cfg.gateway || !cfg.i18n) {
			throw new Error('IFTP_REFUND_GUARD not configured');
		}
		const { ajax, gateway } = cfg;
		if (
			!ajax.url ||
			!ajax.nonce ||
			!ajax.routes?.send ||
			!ajax.routes?.verify ||
			!ajax.routes?.txns_modal
		) {
			throw new Error('IFTP_REFUND_GUARD.ajax is incomplete');
		}
		if (!gateway.id || !gateway.cell) {
			throw new Error('IFTP_REFUND_GUARD.gateway is incomplete');
		}
	}
	window.IFTPRefundGuard.utils = { norm, assertConfig, logoHtml };
})(window);
