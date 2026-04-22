# Consent Manager Developer Documentation

Consent Manager allows phpBB extensions to declare any optional cookies, scripts, or third-party services they use and ensures those features are only activated after the user has given consent.

This applies to analytics, advertising, and any functionality that relies on non-essential cookies or external tracking code.

Integration has two parts:

1. Register your extension’s features so they appear in the consent UI.
2. Ensure its scripts or tracking codes are only executed after consent has been granted.

## Table of contents

- [Overview](#overview)
- [PHP integration](#php-integration)
  - [Hook into the registration event](#hook-into-the-registration-event)
  - [Registration signature](#registration-signature)
  - [Registration rules](#registration-rules)
  - [Definition options](#definition-options)
  - [Category template flags](#category-template-flags)
- [Script handling](#script-handling)
  - [External scripts](#external-scripts)
  - [Inline scripts](#inline-scripts)
  - [Delayed scripts with placeholders](#delayed-scripts-with-placeholders)
  - [Delayed scripts with PHP registration](#delayed-scripts-with-php-registration)
  - [What happens when consent changes](#what-happens-when-consent-changes)
- [JavaScript API](#javascript-api)
  - [`consentManager.ready(callback)`](#consentmanagerreadycallback)
  - [`consentManager.hasConsent(category)`](#consentmanagerhasconsentcategory)
  - [`consentManager.onChange(callback)`](#consentmanageronchangecallback)
  - [`consentManager.registerScript(id, options)`](#consentmanagerregisterscriptid-options)
  - [`consentManager.openSettings()`](#consentmanageropensettings)
  - [`consentManager.getState()`](#consentmanagergetstate)
  - [`window.phpbbConsentManagerPayload`](#windowphpbbconsentmanagerpayload)
- [Recommended integration patterns](#recommended-integration-patterns)
  - [Analytics integration](#analytics-integration)
  - [Advertising script integration](#advertising-script-integration)
  - [Simple conditional execution](#simple-conditional-execution)
- [Integration checklist](#integration-checklist)

## Overview

Consent Manager has three categories:

| Category    | Purpose                                       | How it works   |
|-------------|-----------------------------------------------|----------------|
| `necessary` | Technically required functionality            | Always allowed |
| `analytics` | Metrics, analytics, usage tracking            | Optional       |
| `marketing` | Advertising, remarketing, cross-site tracking | Optional       |

If you want your extension to work with Consent Manager, these are the parts you will use:

- **PHP registration event:** `phpbb.consentmanager.collect_registrations`
- **Registration service:** `register(string $id, array $definition): bool`
- **Template flags:** `S_CONSENTMANAGER_ANALYTICS_ENABLED`, `S_CONSENTMANAGER_MARKETING_ENABLED`
- **JavaScript API:** `window.consentManager`

Only use `necessary` for code the board truly cannot work without. Most third-party cookies, analytics, pixels, and ad scripts belong in `analytics` or `marketing`.

## PHP integration

### Hook into the registration event

PHP registration is the main integration point. This is how your extension tells Consent Manager:

- what the integration is called
- which category it belongs to
- what description should be shown in the consent UI

Consent Manager asks other extensions to register themselves by firing the phpBB event `phpbb.consentmanager.collect_registrations`. Your extension should listen for that event and then call the consent manager service from the event data.

```php
namespace vendor\example\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'phpbb.consentmanager.collect_registrations' => 'register_consent_services',
		];
	}

	public function register_consent_services($event)
	{
		/** @var \phpbb\consentmanager\service\consent_manager_interface $consent_manager */
		$consent_manager = $event['consent_manager'];

		$consent_manager->register('vendor.example.analytics', [
			'label' => 'Example Analytics',
			'category' => 'analytics',
			'description' => 'Tracks page views after analytics consent is granted.',
			'scripts' => [
				[
					'id' => 'vendor.example.analytics.loader',
					'asset' => '@vendor_example/js/analytics.js',
					'wait_for_dom_ready' => true,
				],
			],
		]);
	}
}
```

The same service also exists in the container as `phpbb.consentmanager.service`, but for most extension authors the event example above is the simplest and best approach.

### Registration signature

```php
$accepted = $consent_manager->register(string $id, array $definition);
```

- Returns `true` when the registration is accepted.
- Returns `false` when the registration itself is invalid.
- If one script entry inside `scripts` is invalid, Consent Manager skips that entry instead of breaking the whole page.

### Registration rules

- Registration IDs and script IDs may only use letters, numbers, `.`, `_`, `:`, and `-`, and must start with a letter or number.
- Supported categories are `necessary`, `analytics`, and `marketing`.
- Each script definition must use **one** of these: `src`, `asset`, or `inline`.
- `src` accepts `http`, `https`, or relative URLs. URLs such as `//example.com/...` are not allowed.
- `asset` must be a local phpBB asset path such as `@vendor_example/js/file.js`.
- Unsafe HTML event-handler attributes such as `onclick` are ignored.

### Definition options

#### Registration-level options

| Option        | Required     | What it does                                                                                                                                                      | Example                                                           |
|---------------|--------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| `label`       | Optional     | Human-readable name shown in the consent UI. Defaults to the registration ID.                                                                                     | `'label' => 'Example Analytics'`                                  |
| `category`    | **Required** | Default consent category for the integration.                                                                                                                     | `'category' => 'analytics'`                                       |
| `description` | Optional     | Explanatory text shown in the consent UI.                                                                                                                         | `'description' => 'Tracks page views after consent.'`             |
| `scripts`     | Optional     | A list of scripts for this integration. Use this when you need more than one script, or when you want to keep the display text separate from the script settings. | `'scripts' => [[ 'asset' => '@vendor_example/js/analytics.js' ]]` |

If you leave out `scripts`, Consent Manager also supports a shorter form where the script options are placed directly on the registration:

```php
$consent_manager->register('vendor.example.pixel', [
	'label' => 'Example Pixel',
	'category' => 'marketing',
	'description' => 'Loads a marketing pixel after consent.',
	'src' => 'https://cdn.example.com/pixel.js',
	'async' => true,
]);
```

You can also register just the display information:

```php
$consent_manager->register('vendor.example.inline', [
	'label' => 'Example Inline Tracker',
	'category' => 'analytics',
	'description' => 'Shown in the consent dialog; execution is handled by template placeholders.',
]);
```

This is useful when your extension already prints its own delayed script tags instead of asking Consent Manager to add the script for you.

#### Script-level options

Each entry inside `scripts` supports the following options.

| Option               | Required                                              | What it does                                                                                                                                                   | Example                                                                      |
|----------------------|-------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|
| `id`                 | Optional                                              | A unique ID for this script. If you leave it out, Consent Manager creates one when needed.                                                                     | `'id' => 'vendor.example.analytics.loader'`                                  |
| `category`           | Optional                                              | Lets this script use a different category from the main registration. Most extensions should keep it the same.                                                 | `'category' => 'marketing'`                                                  |
| `src`                | Needed if you are loading an external file            | The URL of an external script, or a relative URL. Do not use this together with `asset` or `inline`.                                                           | `'src' => 'https://cdn.example.com/analytics.js'`                            |
| `asset`              | Needed if you are loading one of your extension files | A local phpBB asset path. This is usually the best choice for JavaScript files that ship with your extension. Do not use this together with `src` or `inline`. | `'asset' => '@vendor_example/js/analytics.js'`                               |
| `inline`             | Needed if you want to run inline JavaScript           | JavaScript code that Consent Manager will inject after consent. Do not use this together with `src` or `asset`.                                                | `'inline' => 'window.tracker.start();'`                                      |
| `async`              | Optional                                              | Sets the injected `<script async>` flag. Defaults to `true` for `src`/`asset` scripts and `false` for inline scripts.                                          | `'async' => true`                                                            |
| `defer`              | Optional                                              | Sets the injected `<script defer>` flag. Defaults to `false`.                                                                                                  | `'defer' => true`                                                            |
| `wait_for_dom_ready` | Optional                                              | Waits until `DOMContentLoaded` before adding the script. Useful for files that expect the page HTML to already exist. Defaults to `false`.                     | `'wait_for_dom_ready' => true`                                               |
| `attributes`         | Optional                                              | Additional safe attributes copied onto the injected script tag. Unsafe names are ignored.                                                                      | `'attributes' => ['data-site-id' => 'abc123', 'crossorigin' => 'anonymous']` |

Example with multiple scripts:

```php
$consent_manager->register('vendor.example.marketing', [
	'label' => 'Example Ads',
	'category' => 'marketing',
	'description' => 'Loads the ad network and then configures it.',
	'scripts' => [
		[
			'id' => 'vendor.example.marketing.sdk',
			'src' => 'https://cdn.example.com/ads.js',
			'async' => true,
			'attributes' => [
				'crossorigin' => 'anonymous',
				'data-client-id' => 'board-123',
			],
		],
		[
			'id' => 'vendor.example.marketing.bootstrap',
			'inline' => 'window.exampleAds = window.exampleAds || []; window.exampleAds.push({ board: 123 });',
		],
	],
]);
```

### Category template flags

Consent Manager always assigns these template flags on board pages:

- `S_CONSENTMANAGER_ENABLED`
- `S_CONSENTMANAGER_ANALYTICS_ENABLED`
- `S_CONSENTMANAGER_MARKETING_ENABLED`

Use the category flags when you need a fallback for boards where that category is turned off in the ACP or when Consent Manager is not installed.

## Script handling

### External scripts

For third-party or CDN-hosted files, prefer PHP registration with `src`:

```php
$consent_manager->register('vendor.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'scripts' => [
		[
			'src' => 'https://cdn.example.com/analytics.js',
			'async' => true,
		],
	],
]);
```

For extension-owned files, prefer `asset` so phpBB resolves the local asset path correctly:

```php
$consent_manager->register('vendor.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'scripts' => [
		[
			'asset' => '@vendor_example/js/analytics.js',
			'wait_for_dom_ready' => true,
		],
	],
]);
```

If your extension already uses `INCLUDEJS`, keep it only as a fallback:

```twig
{% if not S_CONSENTMANAGER_ANALYTICS_ENABLED %}
	{% INCLUDEJS '@vendor_example/js/analytics.js' %}
{% endif %}
```

When analytics consent is enabled, Consent Manager injects the registered asset after consent. When the category is unavailable, your original `INCLUDEJS` path still works.

### Inline scripts

You have two safe options for inline code:

1. **Register inline code in PHP** with `inline`.
2. **Render an inert placeholder** in Twig using `type="text/plain"` and `data-consent-category`.

PHP-registered inline example:

```php
$consent_manager->register('vendor.example.analytics.init', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'inline' => 'window.exampleTracker && window.exampleTracker.page();',
]);
```

Template placeholder example:

```twig
<script{% if S_CONSENTMANAGER_ANALYTICS_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %} src="https://cdn.example.com/analytics.js"></script>

<script{% if S_CONSENTMANAGER_ANALYTICS_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %}>
	window.exampleTracker && window.exampleTracker.page();
</script>
```

Use the placeholder approach when your extension already prints script tags from templates, and you do not want Consent Manager to add the code itself.

### Deferred execution with placeholders

Consent Manager treats tags like these as delayed placeholders:

```html
<script type="text/plain" data-consent-category="analytics">...</script>
<script type="text/plain" data-consent-category="marketing" src="https://cdn.example.com/ad.js" async></script>
```

Behavior:

- The `type="text/plain"` value keeps the browser from executing the script immediately.
- Consent Manager upgrades the placeholder to a real `<script>` element after the matching category is allowed.
- Consent Manager checks on initial page load **and** watches for matching tags added later.
- `async` and `defer` are preserved on placeholder-based external scripts.

Important: do **not** output `type="text/plain"` all the time unless your extension only works when Consent Manager is installed. Use the category flags so the same template still works without this extension.

### Deferred execution with PHP registration

For PHP-registered scripts, set `wait_for_dom_ready` when the script expects the page HTML to exist before it runs:

```php
[
	'asset' => '@vendor_example/js/analytics.js',
	'wait_for_dom_ready' => true,
]
```

This is the closest equivalent to a footer-loaded `INCLUDEJS` file.

### What happens when consent changes

When consent is granted, Consent Manager executes any newly allowed scripts and then notifies listeners through the JS API.

When a visitor removes consent from a category that already ran scripts, Consent Manager reloads the page. Keep that in mind when writing your code:

- Make sure setup code can safely run once without causing problems if the page is loaded again later.
- Assume a category can be turned off after it was previously allowed.
- Keep behavior for each category separate where possible.

## JavaScript API

Consent Manager adds a global `window.consentManager` object. A simple placeholder version is created early in the page, so calls to `ready()`, `registerScript()`, `onChange()`, and `openSettings()` can be stored until the full script has loaded.

If you want to be sure everything is fully loaded first, put your code inside `ready()`.

### `consentManager.ready(callback)`

Runs `callback` once the full Consent Manager API is available.

```js
window.consentManager.ready(function (cm) {
	if (cm.hasConsent('analytics')) {
		window.exampleTracker.page();
	}
});
```

For most extensions, this is the safest place to start your JavaScript.

### `consentManager.hasConsent(category)`

Returns `true` when the category is currently allowed. `necessary` always returns `true`.

```js
window.consentManager.ready(function (cm) {
	if (!cm.hasConsent('marketing')) {
		return;
	}

	window.exampleAds.start();
});
```

Use this when your own JavaScript should only run after consent.

### `consentManager.onChange(callback)`

Registers a listener for consent changes. The callback receives the current state immediately and again whenever the visitor changes preferences.

```js
window.consentManager.ready(function (cm) {
	cm.onChange(function (state) {
		if (!state || !cm.hasConsent('analytics')) {
			return;
		}

		window.exampleTracker.page();
	});
});
```

The state object is either `null` or:

```js
{
	categories: ['necessary', 'analytics'],
	timestamp: '2026-04-21T20:00:00.000Z',
	version: 1
}
```

Because the callback fires immediately, protect one-time setup with your own guard if needed.

### `consentManager.registerScript(id, options)`

Registers a script from JavaScript and executes it immediately if consent already exists.

```js
window.consentManager.ready(function (cm) {
	cm.registerScript('vendor.example.analytics.runtime', {
		category: 'analytics',
		src: 'https://cdn.example.com/runtime.js',
		async: true
	});
});
```

Supported `options` mirror the PHP script definition:

- `category` **required**
- `src` or `inline`
- `async`
- `defer`
- `wait_for_dom_ready`
- `attributes`

Use this when you only know the script details in JavaScript. For most extensions, PHP registration is still better because it also shows the integration in the consent UI.

### `consentManager.openSettings()`

Opens the Consent Manager settings modal.

```js
document.getElementById('privacy-link').addEventListener('click', function (event) {
	event.preventDefault();

	window.consentManager.ready(function (cm) {
		cm.openSettings();
	});
});
```

Use this when your extension offers its own privacy or settings link.

### `consentManager.getState()`

Returns the current consent state snapshot or `null` when the visitor has not made a choice yet.

```js
window.consentManager.ready(function (cm) {
	var state = cm.getState();

	if (!state) {
		return;
	}

	console.log(state.categories);
});
```

Unlike `ready()`, `onChange()`, `registerScript()`, and `openSettings()`, this method is only available on the fully initialized API, so call it inside `ready()`.

### `window.phpbbConsentManagerPayload`

Consent Manager also exposes its startup data as `window.phpbbConsentManagerPayload`. This is mainly for Consent Manager itself, not for normal extension integration.

Only use it if you have a very specific reason to inspect the raw data. For normal extension work, use `window.consentManager`.

## Recommended integration patterns

### Analytics integration

Use PHP registration for the script, then gate any tracker calls in JavaScript.

```php
$consent_manager->register('vendor.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'description' => 'Anonymous page-view analytics.',
	'scripts' => [
		[
			'asset' => '@vendor_example/js/analytics.js',
			'wait_for_dom_ready' => true,
		],
	],
]);
```

```twig
{% if not S_CONSENTMANAGER_ANALYTICS_ENABLED %}
	{% INCLUDEJS '@vendor_example/js/analytics.js' %}
{% endif %}
```

Why this works well:

- The integration is visible in the consent UI.
- The asset is not injected before consent.
- When the Analytics category is unavailable, your original INCLUDEJS path still works.

#### Gate a portion of your JavaScript instead of the whole file

If you don't want the whole asset gated because some of it contains necessary JS code and only a portion of it handles tracking, you can use the JS API.

```js
// your existing neccessary JS code

window.consentManager.ready(function (cm) {
	var booted = false;

	cm.onChange(function () {
		if (booted || !cm.hasConsent('analytics')) {
			return;
		}

		booted = true;
		window.exampleTracker.init();
		window.exampleTracker.page();
	});
});

// the rest of your neccessary JS code
```

Why this works well:

- Tracker calls are still guarded while the rest of your own JS runs.

### Advertising script integration

If your extension already renders a third-party ad tag in Twig, keep the markup but turn it into a placeholder when Consent Manager is active.

```php
$consent_manager->register('vendor.example.ads', [
	'label' => 'Example Ads',
	'category' => 'marketing',
	'description' => 'Displays personalized ads.',
]);
```

```twig
<script{% if S_CONSENTMANAGER_MARKETING_ENABLED %} type="text/plain" data-consent-category="marketing"{% endif %}
	src="https://cdn.example.com/ads.js"
	async
	data-client-id="board-123"></script>
```

This is usually the safest pattern for ad snippets copied from a provider because the original tag stays close to the provider's example while still staying inactive until consent.

### Simple conditional execution

For lightweight behavior that does not need script injection, gate it directly:

```js
window.consentManager.ready(function (cm) {
	if (!cm.hasConsent('analytics')) {
		return;
	}

	window.exampleTracker.page();
});
```

This is ideal for:

- manual page-view calls
- optional UI instrumentation
- event tracking attached to buttons or forms

## Integration checklist

1. Register every non-essential integration in `phpbb.consentmanager.collect_registrations`.
2. Put the integration in the correct category.
3. Make sure the actual script is delayed by PHP registration, a placeholder tag, or explicit JS gating.
4. Use template flags when rendering fallback script tags.
5. Write `onChange()` callbacks so they can run more than once without causing duplicate setup.

If you follow those rules, your extension will integrate cleanly with Consent Manager and avoid executing optional cookies, analytics, or advertising code before consent exists.
