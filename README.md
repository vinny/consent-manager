# Consent Manager

Consent Manager provides centralized, category-based consent handling for phpBB. It keeps optional analytics and marketing integrations out of the page until the visitor explicitly opts in, exposes a PHP registry for other extensions, and provides a JavaScript API that is available early in the page lifecycle.

## Critical audit summary

The original implementation had three important weaknesses:

1. The full runtime only initialized in the footer, so early extension scripts could observe a partial stub instead of the real API.
2. PHP-side registrations validated ACP JSON input, but did not consistently reject unsafe or malformed script definitions coming from extension code.
3. Script blocking was deterministic only for scripts routed through the registry; this needed to be stated clearly and supported with a stronger deferred-script pattern.

These issues are addressed by earlier runtime boot, stricter registration validation, synchronized consent state handling, and clearer integration rules.

## How script blocking works

Consent Manager can only guarantee blocking for integrations that cooperate with it.

1. The extension publishes the consent payload and bootstraps `window.consentManager` in the document head.
2. Optional scripts registered through the PHP or JavaScript API are kept as metadata, not executable markup.
3. The runtime creates real `<script>` elements only after the relevant category has been granted.
4. Inline or external template scripts can be deferred safely with placeholder tags:

```html
<script type="text/plain" data-consent-category="analytics" src="https://cdn.example.com/analytics.js"></script>

<script type="text/plain" data-consent-category="analytics">
	window.exampleTracker && window.exampleTracker.page();
</script>
```

5. The runtime watches the DOM for newly added consent placeholders and activates them only after consent exists.

### Important limitation

If another extension outputs a normal executable `<script>` tag directly, Consent Manager cannot retroactively stop the browser from running it. Extension authors must register optional integrations with Consent Manager or use the `type="text/plain"` placeholder pattern.

## PHP integration API

Listen to `phpbb.consentmanager.collect_registrations` and register your integration through `phpbb.consentmanager.service`:

```php
$consent_manager->register('ext.example.ads', [
	'label' => 'Example Ads',
	'category' => 'marketing',
	'description' => 'Loads advertising and attribution scripts after marketing consent.',
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

### Registration rules

- `id` must be a stable identifier using letters, numbers, `.`, `_`, `:`, or `-`
- `category` must be `necessary`, `analytics`, or `marketing`
- each script may declare **either** `src` **or** `inline`, not both
- `src` must be an `http`, `https`, or relative URL
- event-handler attributes such as `onclick` are rejected
- ACP-managed integrations intentionally do **not** support arbitrary inline JavaScript

## JavaScript integration API

The runtime is initialized in the document head, so client code can rely on it before later page scripts run.

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

You can also register client-side scripts:

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

## Consent withdrawal and re-prompting

- Visitors can reopen the settings modal at any time through the persistent **Cookie settings** control.
- Rejecting categories is as easy as accepting them.
- When a visitor withdraws consent after optional scripts already executed on the current page, Consent Manager stores the new decision and reloads the page so subsequent rendering happens without those optional scripts.

## Failure scenarios

### JavaScript disabled

Optional integrations remain off because Consent Manager never turns their metadata into executable scripts. A `noscript` notice explains that optional services remain disabled.

### Missing or corrupted consent state

Invalid or outdated cookie/localStorage data is discarded. The visitor is treated as not having consented and is prompted again.

### New categories or changed consent model

The consent version is part of the stored state. Increasing the version in the ACP invalidates previous client-side consent state and forces a fresh prompt.

## Security considerations

- ACP-managed integrations reject unsafe sources such as `javascript:`, `data:`, and protocol-relative URLs.
- Registration attribute validation strips event-handler attributes and reserved script attributes that could bypass runtime checks.
- Consent logs store only a phpBB-side HMAC of the current user/session identifier, not the raw identifier.
- The logging endpoint requires a phpBB link hash to reduce CSRF exposure.
- Inline scripts are supported only for trusted extension code that registers through PHP or the JavaScript runtime.

## Remaining limitations

- Consent Manager cannot block executable script tags emitted directly by unrelated extensions or template customizations.
- It cannot undo third-party requests or cookies that were already created before consent was withdrawn; it can only prevent future execution and reload the page into the new consent state.
- Full CSP nonce/hash management is not yet implemented for dynamically injected scripts.
