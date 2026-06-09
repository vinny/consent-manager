<div align="center">

# Consent Manager
[![Build Status](https://github.com/phpbb-extensions/consent-manager/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-extensions/consent-manager/actions)
[![codecov](https://codecov.io/gh/phpbb-extensions/consent-manager/graph/badge.svg?token=IE2YWG6N9V)](https://codecov.io/gh/phpbb-extensions/consent-manager)
![Stability](https://img.shields.io/badge/stability-alpha-orange?logo=phpBB&logoColor=white)

<p><i>Modern cookie consent for phpBB</i></p>
<kbd><img src=".github/images/cm.png" width="566" height="208" alt="Consent Manager" style="width:566px; height:auto; max-width: 100%; display: block;"></kbd>
</div>

<br>

Simple, GDPR-ready privacy controls with category-based consent, ACP management tools, and extension-friendly integrations.

## Features

- Consent banner and preference modal
- Category-based consent options
- Consent logging
- Consent version resets
- Google Consent Mode
- ACP-managed categories, integrations, messaging, and audit logs
- Extension integration API

### Supported categories:

- Necessary
- Analytics
- Marketing
- Embedded media

Necessary cookies stay enabled. The rest requires consent.

## For phpBB extension developers

Consent Manager makes it easy for extension authors to ensure analytics, embeds, advertising, and other non-essential scripts only load after user consent has been granted.

See the [Developer Documentation](DOCUMENTATION.md) for a complete guide.

## License

[GNU General Public License v2](license.txt)
