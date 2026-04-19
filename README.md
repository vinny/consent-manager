# Consent Manager

Consent Manager adds a cookie and tracking consent system to phpBB.

It gives your board a clear consent banner, a settings modal, category-based choices, and a central place for extensions to declare analytics, advertising, and other tracking-related scripts. Visitors can accept all, reject all, or choose categories individually, and they can reopen their settings later from the footer.

Out of the box, the extension supports these categories:

- `necessary`
- `analytics`
- `marketing`

It also includes ACP settings for category availability, simple admin-managed integrations, server-side logging of consent decisions, and consent version resets.

Consent Manager is designed to coordinate consent for **cooperating integrations**.

## For extension authors

If your extension adds analytics, advertising, or other tracking/cookie-related JavaScript, this is the usual integration path:

1. register your tracking integration in PHP
2. if your extension uses `INCLUDEJS`, add a small consent check in that JS file where tracking starts
3. if your extension outputs direct `<script>` tags in templates, convert those tags into consent-aware placeholder tags

### 1. Register in PHP

PHP registration is the main integration point.

This is how your extension tells Consent Manager:

- what the integration is called
- which category it belongs to
- what description should be shown in the consent UI

Listen to `phpbb.consentmanager.collect_registrations` and register your service through `phpbb.consentmanager.service`:

Basic example:

```php
$consent_manager->register('ext.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'description' => 'Tracks page views after analytics consent is granted.',
	'scripts' => [],
]);
```

That example registers the integration for display in the consent UI, but does **not** ask Consent Manager to load any script files for you.

#### About the `scripts` array

The `scripts` array is mainly for **known external third-party script URLs** that Consent Manager should inject only after consent exists.

Example:

```php
$consent_manager->register('ext.example.ads', [
	'label' => 'Example Ads',
	'category' => 'marketing',
	'description' => 'Loads advertising and attribution scripts after marketing consent is granted.',
	'scripts' => [
		[
			'id' => 'ext.example.ads.loader',
			'src' => 'https://cdn.example.com/ads.js',
			'async' => true,
			'attributes' => [
				'crossorigin' => 'anonymous',
				'integrity' => 'sha384-...',
			],
		],
	],
]);
```

That works well for stable external URLs such as ad, analytics, or tag-loader scripts.

For extension-owned JavaScript files that you normally load with `INCLUDEJS`, the better pattern is usually to keep using `INCLUDEJS` and add a small consent check in the JS file instead of routing that local file through the `scripts` array.

#### Registration rules

- `id` must use letters, numbers, `.`, `_`, `:`, or `-`
- `category` must be `necessary`, `analytics`, or `marketing`
- each script entry may define either `src` or `inline`, not both
- `src` must be an `http`, `https`, or relative URL
- unsafe attributes such as event handlers are rejected

### 2. If your extension already uses INCLUDEJS

If your extension already loads a normal JS file through `INCLUDEJS`, you usually do **not** need to redesign the extension.

Keep the include:

```html
{% INCLUDEJS '@vendor_extension/js/feature.js' %}
```

Then add a small consent check around the place where tracking starts:

```js
(function (window) {
	function startTracking() {
		// Existing analytics / cookie logic
	}

	if (window.consentManager) {
		window.consentManager.ready(function (consentManager) {
			if (consentManager && typeof consentManager.hasConsent === 'function') {
				if (consentManager.hasConsent('analytics')) {
					startTracking();
				}

				consentManager.onChange(function (state) {
					if (state && state.categories.indexOf('analytics') !== -1) {
						startTracking();
					}
				});

				return;
			}

			startTracking();
		});
		return;
	}

	startTracking();
})(window);
```

That is the normal pattern for extension-local JS files.

### 3. If your extension outputs direct script tags

If your extension writes live `<script>` tags directly in a template event, PHP registration by itself is **not** enough. Those tags would still run as soon as the browser parses them.

For that pattern, your template usually keeps the same `<script>` tags and conditionally adds Consent Manager's placeholder attributes:

```html
<script{% if S_CONSENTMANAGER_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %} src="https://cdn.example.com/analytics.js"></script>

<script{% if S_CONSENTMANAGER_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %}>
	window.exampleTracker && window.exampleTracker.page();
</script>
```

When `S_CONSENTMANAGER_ENABLED` is true, Consent Manager sees `type="text/plain"` plus `data-consent-category` and upgrades the placeholder after the matching category is allowed. When Consent Manager is absent, those attributes are omitted and the same tags run normally.

`type="text/plain"` is intentionally inert, so do not output it unconditionally unless your extension depends on Consent Manager being installed.

## Small JavaScript runtime helpers

Consent Manager also exposes a small runtime object at `window.consentManager`.

For most extensions, the main helpers are:

- `hasConsent(category)`
- `onChange(callback)`
- `ready(callback)`
- `openSettings()`

Simple example:

```js
if (window.consentManager.hasConsent('marketing')) {
	loadAds();
}

window.consentManager.onChange(function (state) {
	if (state && state.categories.indexOf('marketing') !== -1) {
		loadAds();
	}
});
```

There is also a `registerScript()` helper, but that is a more advanced option and is not the preferred integration path for typical extensions:

```js
window.consentManager.registerScript('ext.example.analytics.loader', {
	category: 'analytics',
	src: 'https://cdn.example.com/analytics.js',
	async: true
});
```

If you need a stable hook after the full manager is installed:

```js
window.consentManager.ready(function (consentManager) {
	if (consentManager.hasConsent('analytics')) {
		// Safe to run analytics-dependent code
	}
});
```

## ACP-managed integrations

The ACP includes a JSON setting for simple admin-managed integrations.

This is mainly for cases where a board admin wants to add a straightforward external analytics or advertising script URL directly from the ACP and have it appear in the consent UI.

ACP-managed integrations are intentionally limited to simple metadata plus a script `src`. They do **not** allow arbitrary inline JavaScript.

For full extension development, PHP registration remains the preferred path.
