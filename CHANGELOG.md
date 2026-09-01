# Changelog

All notable changes to this project will be documented in this file.

The format loosely follows Keep a Changelog recommendations.

## [1.1.1] - 2026-09-01

### Fixed
- Fixed callback URL query separator in `activate_callback_by_gateway_context()`: always used `&` after the base URL, producing an invalid `urlCb` (e.g. `/whk&ref=...`) that ifthenpay's callback returned as 404. Now uses `?` when the base URL has no query string yet.

### Removed
- Removed Cofidis Pay references from documentation and the allowed payment-method whitelist (method no longer offered by ifthenpay).

## [1.1.0] - 2026-05-07

### Changed
- Updated `expiryDays` handling in payload building to conditionally include `expiredate` based on null checks.
- Improved `compute_expire_ymd` logic for better expiration date calculation (0 days = tomorrow, n days = n+1 days from now).

### Added
- New installation step for payment configuration in documentation.
- Added OTP (One-Time Payment) support to payment payload.

### Updated
- Bumped "Tested up to" to WordPress 6.9 in all relevant files.

## [1.0.0] - 2025-11-06
- Initial release: Period Engine, partial refunds, multi-method support, aligned with the analytics dashboard, secure callbacks, hooks.

<!-- Future versions:
## [Unreleased]
### Added
### Changed
### Fixed
### Security
-->
