(function ($) {
	/**
	 * Entry bootstrap: instantiates RefundGuard after DOM ready using localized config.
	 */
	'use strict';
	$(function () {
		const cfg = window.IFTP_REFUND_GUARD;
		if (
			cfg &&
			window.IFTPRefundGuard &&
			window.IFTPRefundGuard.RefundGuard
		) {
			new window.IFTPRefundGuard.RefundGuard(cfg).init();
		}
	});
})(jQuery);
