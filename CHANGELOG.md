# Changelog

All notable changes to this project will be documented in this file.

## 1.0.0-b1 - 2026-06-09

### Added

- Added Google Consent Mode support so Google tags can receive analytics and marketing consent state automatically.
- Added Privacy Policy links to the consent banner and settings modal.

### Changed

- Updated consent and developer documentation for Google Consent Mode, iframe patterns, and integration examples.
- Excluded developer documentation from release packages.

### Fixed

- Restored consent logging for anonymous users with anonymized session-based identifiers.
- Fixed Consent Manager not loading on the ACP login page.

## 1.0.0-a2 - 2026-06-01

### Added

- Consent banner text can now be edited and/or translated from the ACP under the new "Consent Text" section.

## 1.0.0-a1 - 2026-05-27

### Changed

- Blocked iframes now show the original URL, so users can easily identify the source of the blocked content and visit it directly without needing to enable consent.
- Raised the minimum phpBB requirement to 3.3.5 to avoid installing errors on PHP 8.1+.

### Fixed

- Fixed a bug where the registry of active services would not show Consent Manager's iframe service when no other services are registered.
- Fixed a minor issue with deferred iframes being evaluated twice.
- Bumped minimum phpBB board requirements to 3.3.5 or newer.

## 0.4.0-dev - 2026-05-16

### Added

- Added a registry of active services registered with Consent Manager to the ACP.
- Added live JSON validation to the manual (ACP-managed) integrations field.

### Changed 

- Improved the ACP description of the manual (ACP-managed) integrations.
- Improved formatting of buttons in the Consent Manager modal on mobile devices.
- Improved CSRF protections when deleting consent logs.
- Switched the Privacy settings link to a new lock icon.

## 0.3.0-dev - 2026-05-12

### Added

- Added support for permanently deleting consent log records with the same optional filters used for CSV exports.
- Added timestamps to exported consent log CSV file names so repeated exports are easier to distinguish.

### Changed

- Exporting or deleting consent logs by user now lets you pick that user by username instead of user ID.
- Updated the language explaining the *Date from* and *Date to* fields in the ACP Consent Logs page.
- Updated the developer documentation with clearer consent integration guidance, including iframe-specific patterns.
- Allowed the extension to install on phpBB 4 while retaining support for phpBB 3.3 and later.

### Fixed

- Fixed consent decisions that require a page reload so they are still logged before the browser refreshes.
- Fixed consent log CSV downloads to use binary-safe output handling for better compatibility with spreadsheet applications.

## 0.2.0-dev - 2026-05-09

### Added

- Added an **Embedded media** consent category for third-party media such as iframes, including ACP support, migration handling, and dedicated template flags.
- Added embedded media placeholder handling so blocked media can be presented cleanly until consent is granted.
- Added support for preserving explicit script IDs in registered script definitions.
- Added caching and memoization for locally resolved assets to reduce repeated consent script resolution work.
- Added clearer installation checks and messaging for unsupported phpBB or PHP versions.

### Changed

- Improved deferred script loading performance by trimming payload data, reducing repeated language loading, and refining inline JavaScript handling.
- Refined embedded media markup and styling for better compatibility across phpBB styles and cleaner placeholder output.
- Updated the developer documentation to cover the media category, script-loading behavior, and current integration patterns.
- Raised the minimum PHP requirement to **PHP 7.2**.

### Fixed

- Fixed consent revocation behavior for embedded media so revoked iframes are re-blocked and reloaded immediately.
- Fixed several test regressions and expanded automated coverage for media handling, caching, install checks, and service behavior.

## 0.1.0-dev - 2026-05-03

### Added

- Initial release of Consent Manager for phpBB with a consent banner, settings modal, persistent preferences link, and category-based consent controls.
- Added support for **Necessary**, **Analytics**, and **Marketing** consent categories, including deferred script loading for registered integrations.
- Added ACP management for categories, registered services, consent versioning, and admin-managed integrations.
- Added consent logging for audit/compliance purposes and CSV export of consent logs.
- Added developer documentation and extension integration hooks for consent-aware script registration.

### Changed

- Overrode phpBB's default cookie notice so forums can use a single consent experience managed by this extension.
