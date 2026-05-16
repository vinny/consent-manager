# Consent Manager

> This extension is under development and will become available on [phpBB.com](https://phpbb.com) when it's ready

[![Build Status](https://github.com/phpbb-extensions/consent-manager/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-extensions/consent-manager/actions)
[![codecov](https://codecov.io/gh/phpbb-extensions/consent-manager/graph/badge.svg?token=IE2YWG6N9V)](https://codecov.io/gh/phpbb-extensions/consent-manager)
![Stability](https://img.shields.io/badge/stability-dev-orange?logo=phpBB&logoColor=white)

Consent Manager is a GDPR-ready privacy/cookie consent management solution built for phpBB forums.

It adds a consent banner, settings modal, and category-based controls, allowing visitors to accept all, reject all, or choose specific cookie types. A footer link lets users revisit and update their preferences at any time.

The extension also provides an easy integration point for other phpBB extensions, enabling them to make their non-essential scripts compliant.

Out of the box, Consent Manager supports these categories:

- Necessary (always on)
- Analytics (optional)
- Marketing (optional)
- Embedded media (optional)

It also includes ACP settings for enabling categories, simple admin-managed integrations, detailed consent logging for audit and compliance purposes, and consent version resets to prompt users to review their choices when policies or integrations change.

## For extension authors

If your extension adds analytics, advertising, or other tracking or cookie-related JavaScript, you’ll want to integrate it with Consent Manager so those scripts only run after user consent is granted.

See the [Developer Documentation](DOCUMENTATION.md) for a complete guide.

## License

[GNU General Public License v2](license.txt)
