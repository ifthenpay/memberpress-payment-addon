=== ifthenpay | Payments for MemberPress ===
Contributors: ifthenpay
Tags: ifthenpay, memberpress, subscriptions, recurring, refunds
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds ifthenpay payment methods to MemberPress: cards, wallets, local bank transfers; supports one-time and period-based recurring memberships.

Includes partial refunds, a merchant backoffice (basic sales & refunds), and secure signed callbacks for automatic payment confirmation.

== Description ==

ifthenpay does not offer built-in recurring billing. This plugin makes recurring memberships work by creating simple, easy-to-understand payment periods. When a period is paid, the membership continues; if not paid, MemberPress marks that period as "Lapsed" and the system waits for the end-customer to "Update" their subscription by making a manual payment for the next computed period. Periods are computed sequentially so customers never lose paid time — the next period is only created after the previous one is completed. An admin may also change the subscription status if needed.

In plain terms you get:
* One-time payments and recurring memberships (period engine)
* Partial refunds you control
* Merchant backoffice (basic sales & refunds) on web + mobile
* Secure automatic payment confirmations (no card numbers stored)

All settings are made in MemberPress and in your ifthenpay Backoffice. The plugin is built so store owners can manage payments without needing deep technical knowledge.

== Key Features ==

1. Period-based recurring (sequential, preserves paid time)
2. One-time payments (standard purchase flow)
3. Automatic payment confirmation (fast access)
4. Lapsed period handling with manual Update action
5. Admin partial refunds (future/unconsumed periods)
6. Multiple local payment types (cards, wallets, transfers)
7. Merchant backoffice (basic sales & refund reports)
8. Security first (signed callbacks, no card data stored)

== How Period-Based Recurrence Works ==
1. User purchases a membership with a cycle (e.g. monthly).
2. The plugin creates the first payment period and a payment reference for that period.
3. When payment is received, the period is marked paid and the next period is prepared. Periods are computed sequentially to avoid overlap and preserve any paid time for the customer.
4. Admins can generate or edit periods from the transactions screen.

== Requirements ==
* An active ifthenpay merchant account.
* A Gateway Key for MemberPress (request this from ifthenpay support/helpdesk).
* The payment methods you want enabled on that Gateway Key (your ifthenpay backoffice lets you choose).
* WordPress 5.0+, PHP 7.4+, and MemberPress installed.
* HTTPS (SSL) enabled on your site.

== Installation ==
1. Install: Upload the plugin zip via Plugins → Add New → Upload, or install from WordPress.org and Activate.
2. Credentials: Request an ifthenpay Gateway Key for MemberPress and ensure desired payment methods are enabled.
3. API Token: In Backoffice → Administration → Integrations create a MemberPress integration; copy API Token & Backoffice Key.
4. Gateway setup: MemberPress → Settings → Payments → Add Gateway → choose "ifthenpay | Payment Gateway"; enter API Token & Backoffice Key; save.
5. Test: Make a low-value test payment and confirm callback marks the period Paid.

Troubleshooting:
* Callback not firing / period not advancing: confirm HTTPS reachability + correct registered callback URL.
* Method missing at checkout: ensure enabled on Gateway Key AND mapped in Backoffice Integrations.

== Frequently Asked Questions ==

= Does ifthenpay now support real recurring billing? =
No. ifthenpay does not provide native recurring billing. This plugin makes recurring memberships work using payment periods as described above.

= Are payment details stored? =
No. The plugin does not store card numbers or full bank details. Only small references needed for matching payments are kept.

= How are partial refunds calculated? =
Admins enter the desired refund amount per transaction or period when issuing a refund. The plugin can suggest values but the admin finalizes and approves the refund.

= What happens if a user misses a period payment? =
The subscription period is marked Lapsed in MemberPress. The customer must Update their subscription (manual payment for the next period) to restore active access; Admins can also reinstate by manually changing the status.

= Can I customize period lengths? =
The integration reads the membership’s subscription schema (period type and amount) and automatically computes the next periods and transactions.

= Do upgrades/downgrades recalculate periods? =
Yes. Future periods are recalculated; current period may optionally prorate (configurable).

= Is there a sandbox? =
ifthenpay may provide test entities; if unavailable, use a low-value live test. Future roadmap includes an internal simulation mode.

= Which payment methods are supported? =
Any ifthenpay method attached to the Gateway Key (e.g. Multibanco, MB WAY, Payshop, Cofidis Pay, Pix, Credit Card if provisioned).

= How secure is the integration? =
Callbacks are signed; requests are encrypted over HTTPS; data minimized; nonces protect admin forms.

= Does this replace MemberPress trials? =
Trials still work; the first period can be zero-amount and becomes payable only when the trial ends.

== Refund Policy Management ==
Scope: refunds apply only to future or unconsumed paid periods (no retroactive time reimbursement).
Process:
* Admin inputs desired refund amount per transaction/period.
* Optional min/max limits can guide consistency.
* Approval required before issuing ifthenpay refund or internal credit note.

== External Services & Dependencies ==
This plugin integrates only with the ifthenpay platform and with WordPress/MemberPress.

- ifthenpay Backoffice & Integrations
	- What we send: the plugin sends your Backoffice Key and API Token (kept only in your site settings), minimal order/subscription details (transaction or subscription ID, user identifier, amount) used only to match payments in ifthenpay.
	- What we receive: ifthenpay returns payment references, payment status updates and timestamps. These are used to mark periods as paid, update MemberPress subscriptions and populate the merchant backoffice.
	- Why credentials are needed: the Backoffice Key identifies your merchant account and the API Token scopes which payment methods are available for MemberPress. Request a Gateway Key for MemberPress via ifthenpay support/helpdesk and create an Integration in the Backoffice to generate an API Token.

- Callbacks / Webhooks
	- The plugin registers a callback URL with ifthenpay (you can confirm the URL in the Backoffice). When ifthenpay notifies us a payment has completed, we verify the signed payload and mark the matching period paid in MemberPress.
	- Only minimal, non-sensitive data is sent in callbacks (reference IDs, status, amount, payment methods). The plugin does not transmit or store raw card PANs or full bank account numbers.

- MemberPress
	- The plugin reads MemberPress subscription schemas to compute periods and updates subscriptions based on ifthenpay confirmations. No extra third-party PHP libraries are required beyond WordPress and MemberPress.

All network requests are performed server-side over HTTPS. Sensitive credentials are stored in site options and are not publicly exposed.

== Screenshots ==
1. Gateway settings screen
2. Checkout with method selection
3. Final Customer subscription Update view

== Changelog ==

= 1.0.0 =
* Initial release: Period Engine, partial refunds, multi-method support, aligned with the analytics dashboard, secure callbacks, hooks.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Review refund defaults and gateway settings before going live.

== License ==
This plugin is licensed under the GPLv3.

== Support ==
For assistance use the WordPress.org support forum:
https://wordpress.org/support/plugin/ifthenpay-payments-for-memberpress/

Please include:
* Backoffice account
* Site URL + plugin version
* Exact error message + relevant log excerpts/screenshots

Pre-checks before posting:
* Callback URL reachable over HTTPS & matches settings
* Payment method enabled on Gateway Key AND mapped to Integration
* Running current recommended versions of WordPress, PHP & MemberPress

Commercial helpdesk available (no direct email required): https://helpdesk.ifthenpay.com/
