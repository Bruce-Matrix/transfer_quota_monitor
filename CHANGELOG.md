# Changelog

## 1.0.6 - 2025-10-01
### Fixed
- Fixed monthly reset job to use correct method `resetAllUsage()` instead of deprecated `resetAllUserTransfers()`
- Improved logging for monthly reset operations with success/failure status

## 1.0.5 - 2025-09-12
### Changed
- Reverted to RAW GitHub screenshot URLs for better display in Nextcloud app store

## 1.0.4 - 2025-09-12
### Changed
- Reverted to original GitHub screenshot URLs

## 1.0.3 - 2025-09-12
### Fixed
- Fixed screenshot URLs to display properly in Nextcloud app store

## 1.0.2 - 2025-09-05
### Updated
- Extended Nextcloud compatibility to version 33
- Resolved app store ownership lock issue

## 1.0.1 - 2025-05-28
### Fixed
- Removed deprecated ActivityDownloadTrackerJob references
- Removed unused public share tracking components in favor of guest account approach
- Fixed background job error messages in server logs

## 1.0.0 - 2025-05-24
### Added
- Initial release
- Individual transfer limits for users
- Real-time monitoring of data transfers
- Notification system with configurable thresholds (80%, 90%)
- Monthly automatic quota reset
- Admin dashboard for usage monitoring
- Support for tracking downloads by registered users and guest accounts
- Email notifications for users and administrators
