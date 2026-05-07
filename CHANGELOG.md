# Changelog

All notable changes to this project will be documented in this file.

The format loosely follows Keep a Changelog recommendations.

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
