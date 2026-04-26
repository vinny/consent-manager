# Consent Manager Developer Documentation

Consent Manager is a *GDPR/cookie consent* extension for [phpBB](https://www.phpbb.com). It shows users a cookie consent dialog and delays all registered scripts until consent is granted.

Extensions that use scripts for **non-functional** or **non-essential** purposes — analytics, advertising, pixels, tracking codes, and similar optional JavaScript or cookies — must register with Consent Manager so users can accept or reject them.

Integration requires two things:

1. Register your extension with our PHP event listener.
2. Choose the right script-loading pattern(s) for your JavaScript files.

Your extension will then appear in the consent UI, and optional scripts will stay inactive until consent is granted.

## Table of contents

- [Strategy guide](#strategy-guide)
- [PHP registration](#php-registration)
  - [Hook into the registration event](#hook-into-the-registration-event)
  - [Registration signature](#registration-signature)
  - [Registration rules](#registration-rules)
  - [Definition options](#definition-options)
  - [Category template flags](#category-template-flags)
- [Script-loading patterns](#script-loading-patterns)
  - [Pattern 1: A script your extension already loads with INCLUDEJS](#pattern-1-a-script-your-extension-already-loads-with-includejs)
  - [Pattern 2: A script your extension already prints with a SCRIPT tag](#pattern-2-a-script-your-extension-already-prints-with-a-script-tag)
  - [Pattern 3: A script contains both necessary and optional code](#pattern-3-a-script-contains-both-necessary-and-optional-code)
  - [Pattern 4: Remote script not already loaded by your extension](#pattern-4-less-common-remote-script-not-already-loaded-by-your-extension)
- [JavaScript API](#javascript-api)
  - [`consentManager.ready(callback)`](#consentmanagerreadycallback)
  - [`consentManager.hasConsent(category)`](#consentmanagerhasconsentcategory)
  - [`consentManager.onChange(callback)`](#consentmanageronchangecallback)
  - [`consentManager.registerScript(id, options)`](#consentmanagerregisterscriptid-options)
  - [`consentManager.openSettings()`](#consentmanageropensettings)
  - [`consentManager.getState()`](#consentmanagergetstate)
  - [`window.phpbbConsentManagerPayload`](#windowphpbbconsentmanagerpayload)
- [What happens when consent changes](#what-happens-when-consent-changes)
- [Examples of Consent Manager integrations](#examples-of-consent-manager-integrations)

## Strategy guide

| Your situation                                                                                                                                             | What to do                                                                                                                                                                                                                     |
|------------------------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Your extension loads JavaScript files with `INCLUDEJS`                                                                                                     | Do **PHP registration with `asset`**, then use a fallback so `INCLUDEJS` only runs when the Consent Manager category is unavailable ([Pattern 1](#pattern-1-a-script-your-extension-already-loads-with-includejs))             |
| Your extension prints `<script>` tags directly in HTML template files                                                                                      | Do **basic PHP registration**, and turn the `<script>` tag into a deferred placeholder with `type="text/plain"` and `data-consent-category` ([Pattern 2](#pattern-2-a-script-your-extension-already-prints-with-a-script-tag)) |
| Your JavaScript file contains both necessary logic and optional data tracking logic                                                                        | Do **basic PHP registration**, keep loading the file normally, and gate only the optional part with the **JavaScript API** ([Pattern 3](#pattern-3-a-script-contains-both-necessary-and-optional-code))                        |
| You want Consent Manager to load a remote script from a CDN or third-party site, and your extension does **not** print or include that script tag anywhere | Do **PHP registration with `src`** ([Pattern 4](#pattern-4-less-common-remote-script-not-already-loaded-by-your-extension))                                                                                                    |

> Info: **`src` / `asset` / placeholder tags are for delaying an entire script.**
>
> If only a small part of the file is optional, you do not have to delay the whole file. Use PHP registration for the UI, then gate the optional code with the JavaScript API.

## PHP registration

PHP registration tells Consent Manager:

- the name shown in the consent UI
- which category the integration belongs to
- the description shown to the user
- optionally, which script Consent Manager should load after consent

Consent Manager has three categories:

| Category    | Purpose                                       | How it works   |
|-------------|-----------------------------------------------|----------------|
| `necessary` | Technically required functionality            | Always allowed |
| `analytics` | Metrics, analytics, usage tracking            | Optional       |
| `marketing` | Advertising, remarketing, cross-site tracking | Optional       |

If you have scripts that are necessary for the board to work, you may register them with Consent Manager as `necessary`.
However, because the necessary scripts are always loaded, registering them is completely optional.

### Hook into the registration event

Extensions can register themselves with Consent Manager through the event `phpbb.consentmanager.collect_registrations`.

Your listener should take the Consent Manager service from the event and call `register()`.

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
					'asset' => '@vendor_example/js/analytics.js',
				],
			],
		]);
	}
}
```

### Registration signature

```php
$accepted = $consent_manager->register(string $id, array $definition);
```

- Returns `true` when the registration is accepted.
- Returns `false` when the registration itself is invalid.
- If one script entry inside `scripts` is invalid, Consent Manager skips only that script entry.

### Registration rules

- Registration IDs and script IDs may only use letters, numbers, `.`, `_`, `:`, and `-`, and must start with a letter or number.
- Supported categories are `necessary`, `analytics`, and `marketing`.
- Each `scripts` definition must use **one** of these execution sources: `src`, `asset`, or `inline`.
- `src` accepts `http`, `https`, or relative URLs. URLs such as `//example.com/...` are not allowed.
- `asset` must be a local phpBB asset path such as `@vendor_example/js/file.js`.
- Unsafe HTML event-handler attributes such as `onclick` are ignored.

### Definition options

#### Registration-level options

| Option        | Required     | What it does                                                                                                   | Example                                                           |
|---------------|--------------|----------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------|
| `label`       | Optional     | Name shown in the consent UI. Defaults to the registration ID.                                                 | `'label' => 'Example Analytics'`                                  |
| `category`    | **Required** | Default consent category for this integration.                                                                 | `'category' => 'analytics'`                                       |
| `description` | Optional     | Text shown in the consent UI.                                                                                  | `'description' => 'Tracks page views after consent.'`             |
| `scripts`     | Optional     | List of scripts for this integration. Use this when Consent Manager should inject one or more scripts for you. | `'scripts' => [[ 'asset' => '@vendor_example/js/analytics.js' ]]` |

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

You can also register only the display information:

```php
$consent_manager->register('vendor.example.inline', [
	'label' => 'Example Inline Tracker',
	'category' => 'analytics',
	'description' => 'Shown in the consent dialog; the script itself is handled elsewhere.',
]);
```

That is the correct choice when:

- your HTML template already contains the script tag, or
- your JavaScript file contains both necessary and non-essential code, so you only want to gate part of it

#### Script-level options

Each entry inside `scripts` supports the following options.

| Option               | Required                     | What it does                                                                                                            | Example                                           |
|----------------------|------------------------------|-------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------|
| `id`                 | Optional                     | Unique ID for this script. If omitted, Consent Manager creates one when needed.                                         | `'id' => 'vendor.example.analytics.loader'`       |
| `category`           | Optional                     | Lets this script use a different category from the main registration. Most extensions should keep the same category.    | `'category' => 'marketing'`                       |
| `src`                | Needed for remote files      | URL of an external script, or a relative URL. Do not combine with `asset` or `inline`.                                  | `'src' => 'https://cdn.example.com/analytics.js'` |
| `asset`              | Needed for extension files   | Local phpBB asset path. Best for JavaScript files that ship with your extension. Do not combine with `src` or `inline`. | `'asset' => '@vendor_example/js/analytics.js'`    |
| `inline`             | Needed for inline JavaScript | JavaScript code that Consent Manager should inject after consent. Do not combine with `src` or `asset`.                 | `'inline' => 'window.tracker.start();'`           |
| `async`              | Optional                     | Sets `<script async>`. Defaults to `true` for `src` and `asset`, and `false` for `inline`.                              | `'async' => true`                                 |
| `defer`              | Optional                     | Sets `<script defer>`. Defaults to `false`.                                                                             | `'defer' => true`                                 |
| `wait_for_dom_ready` | Optional                     | Waits for `DOMContentLoaded` before adding the script. Useful when the file expects page HTML to exist already.         | `'wait_for_dom_ready' => true`                    |
| `attributes`         | Optional                     | Extra safe attributes copied onto the injected script tag. Unsafe names are ignored.                                    | `'attributes' => ['data-site-id' => 'abc123']`    |

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

Consent Manager assigns these template flags on board pages:

- `S_CONSENTMANAGER_ENABLED`
- `S_CONSENTMANAGER_ANALYTICS_ENABLED`
- `S_CONSENTMANAGER_MARKETING_ENABLED`

Use the category flags when you need a fallback for boards where:

- Consent Manager is not installed
- Consent Manager is installed, but that category is disabled in the ACP

## Script-loading patterns

### Pattern 1: A script your extension already loads with INCLUDEJS

Use this when the JavaScript file belongs to your extension, and you already load it with `INCLUDEJS`.

In this case:

1. Register it in PHP with `asset` so phpBB resolves the local asset path correctly.
2. Keep your `INCLUDEJS` only as a fallback for boards where the category is unavailable.

PHP registration:

```php
$consent_manager->register('vendor.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'description' => 'Loads the extension analytics file after consent.',
	'scripts' => [
		[
			'asset' => '@vendor_example/js/analytics.js',
			'wait_for_dom_ready' => true,
		],
	],
]);
```

Template file fallback pattern:

```twig
{% if not S_CONSENTMANAGER_ANALYTICS_ENABLED %}
	{% INCLUDEJS '@vendor_example/js/analytics.js' %}
{% endif %}
```

Why this pattern works:

- Consent Manager delays the file until consent is granted.
- Boards without the category still get your original behavior.
- `asset` is the correct choice for files that ship with your extension.

> Tip: `wait_for_dom_ready` is useful when the script expects page HTML to already exist. This is often the closest match to a footer-loaded `INCLUDEJS` file.

### Pattern 2: A script your extension already prints with a SCRIPT tag

Use this when your extension already prints a `<script>` tag directly in the template.

In this case, **do not** ask Consent Manager to load the script again with `src` or `asset`.

Instead:

1. Do a **basic PHP registration** so the integration appears in the consent UI.
2. Turn your existing `<script>` tag into a deferred placeholder when the category is enabled.

PHP registration:

```php
$consent_manager->register('vendor.example.ads', [
	'label' => 'Example Ads',
	'category' => 'marketing',
	'description' => 'Displays personalized ads.',
]);
```

Template file placeholder pattern:

```twig
<script{% if S_CONSENTMANAGER_MARKETING_ENABLED %} type="text/plain" data-consent-category="marketing"{% endif %}
	src="https://cdn.example.com/ads.js"
	async
	data-client-id="board-123"></script>
```

Add `{% if S_CONSENTMANAGER_MARKETING_ENABLED %} type="text/plain" data-consent-category="marketing"{% endif %}` to your `<script>` tag.

Use the correct `data-consent-category` value and template flag for your category:

- Analytics: `"analytics"` and `S_CONSENTMANAGER_ANALYTICS_ENABLED`
- Marketing: `"marketing"` and `S_CONSENTMANAGER_MARKETING_ENABLED`

What this does:

- `type="text/plain"` stops the browser from executing the script immediately.
- `data-consent-category="marketing"` tells Consent Manager when it may activate the script.
- When consent is granted, Consent Manager turns the placeholder into a real `<script>` tag.

This is usually the best pattern for third-party snippets copied from provider documentation.

> Important: do **not** output `type="text/plain"` all the time. Only add it when the matching Consent Manager category is enabled, so your template still works on boards without this extension or without that category.

The same idea also works for inline script tags:

```twig
<script{% if S_CONSENTMANAGER_ANALYTICS_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %}>
	window.exampleTracker && window.exampleTracker.page();
</script>
```

### Pattern 3: A script contains both necessary and optional code

Sometimes one JavaScript file does two jobs:

- some code is **necessary** for your extension to work
- a small part is **optional**, such as tracking, analytics, or advertising

When that happens, do **not**:

- register the whole file with `src` or `asset`
- wrap the entire `INCLUDEJS` in a category check
- turn the whole script tag into a deferred placeholder

Those approaches delay the **entire** file, which would also delay the necessary code.

Instead:

1. Do a **basic PHP registration** so the integration appears in the consent UI.
2. Keep loading your JavaScript file normally.
3. Use the **JavaScript API** inside that file to gate only the optional part.

PHP registration:

```php
$consent_manager->register('vendor.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'description' => 'Tracks page views after analytics consent is granted.',
]);
```

Example of an existing file with only a small section gated:

```js
// Necessary code: this should always run.
window.exampleWidget = window.exampleWidget || {};
window.exampleWidget.init = function () {
	document.documentElement.classList.add('example-widget-ready');
};

window.exampleWidget.init();

// Optional code: only this part should wait for consent.
window.consentManager.ready(function (cm) {
	var analyticsStarted = false;

	cm.onChange(function () {
		if (analyticsStarted || !cm.hasConsent('analytics')) {
			return;
		}

		analyticsStarted = true;
		window.exampleTracker.init();
		window.exampleTracker.page();
	});
});

// More necessary code can still run below.
window.exampleWidget.bindEvents = function () {
	// ...
};
```

This is the right pattern when only a small part of the file is non-essential.

### Pattern 4: Remote script not already loaded by your extension

This is the least common pattern — it has no fallback if Consent Manager or the category is unavailable.

Use it when the script comes from a remote site and your extension does **not** already print it with `INCLUDEJS` or a `<script>` tag.

In that case, let Consent Manager load it for you with `src`.

```php
$consent_manager->register('vendor.example.analytics', [
	'label' => 'Example Analytics',
	'category' => 'analytics',
	'description' => 'Loads a remote analytics library after consent.',
	'scripts' => [
		[
			'src' => 'https://cdn.example.com/analytics.js',
		],
	],
]);
```

Use this pattern for:

- CDN-hosted analytics libraries
- marketing pixels loaded from a third-party site
- remote widgets that should not run before consent

Do **not** use this pattern if your extension already outputs the same script with `INCLUDEJS` or a `<script>` tag somewhere else. If it does, use Pattern 1 or Pattern 2 instead.

## JavaScript API

Consent Manager adds a global `window.consentManager` object.

A lightweight placeholder is created early in the page load, so calls to `ready()`, `registerScript()`, `onChange()`, and `openSettings()` are queued until the full script loads.

For most extensions, `ready()` is the safest starting point.

### `consentManager.ready(callback)`

Runs `callback` once the full Consent Manager API is available.

```js
window.consentManager.ready(function (cm) {
	if (cm.hasConsent('analytics')) {
		window.exampleTracker.page();
	}
});
```

Use this when your own JavaScript depends on the Consent Manager API being fully ready.

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

Registers a listener for consent changes.

The callback runs immediately with the current state, and then again whenever the visitor changes their preferences.

```js
window.consentManager.ready(function (cm) {
	cm.onChange(function (state) {
		if (!state || !cm.hasConsent('analytics')) {
			window.exampleTracker.deleteCookies();
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

> Tip: Use `onChange()` to clean up when consent is revoked. When `state` is `null` or `hasConsent()` returns `false`, delete any cookies, clear local storage, and stop any active tracking — as shown in the example above. **This is an important part of GDPR compliance.**

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

Supported `options` are:

- `category` **required**
- `src` or `inline`
- `async`
- `defer`
- `wait_for_dom_ready`
- `attributes`

Use `registerScript()` when script details are only known at runtime in JavaScript. For most integrations, PHP registration is preferable because it also adds the entry to the consent UI.

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

### `consentManager.getState()`

Returns the current consent state snapshot, or `null` when the visitor has not made a choice yet.

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

Exposes Consent Manager's startup data. This is for internal use; extension integrations should use `window.consentManager` instead.

## What happens when consent changes

When consent is granted, Consent Manager executes newly allowed scripts and notifies listeners via the JavaScript API.

When consent is revoked for a category that already ran scripts, Consent Manager reloads the page.

Keep this in mind when writing integration code:

- make setup code safe to run again after a reload
- assume a category can be turned off later
- keep behavior for each category separate where possible
- use `onChange()` if you need to delete cookies, local storage, or other optional data after consent is revoked

## Examples of Consent Manager integrations

### phpBB Google Analytics Extension

The following PHP registration was added to the extension's event listener class:

```php
/**
 * Register Google Analytics with Consent Manager when available.
 *
 * @param \phpbb\event\data|array $event The event object or event data
 * @return void
 */
public function register_analytics($event)
{
	if (!$this->config['googleanalytics_id'])
	{
		return;
	}

	$this->language->add_lang('common', 'phpbb/googleanalytics');

	$event['consent_manager']->register('phpbb.googleanalytics', [
		'label'       => $this->language->lang('GOOGLEANALYTICS_LABEL'),
		'category'    => 'analytics',
		'description' => $this->language->lang('GOOGLEANALYTICS_DESCRIPTION'),
	]);
}
```

> Note: Prefer using language strings for `label` and `description` to avoid translation issues.

The following placeholder changes were made to its `script` tags in its template file:

```twig
{% if GOOGLEANALYTICS_ID %}
	<!-- Google tag (gtag.js) - Google Analytics -->
	<script{% if S_CONSENTMANAGER_ANALYTICS_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %} async src="https://www.googletagmanager.com/gtag/js?id={{ GOOGLEANALYTICS_ID }}"></script>
	<script{% if S_CONSENTMANAGER_ANALYTICS_ENABLED %} type="text/plain" data-consent-category="analytics"{% endif %}>
		window.dataLayer = window.dataLayer || [];
		function gtag(){dataLayer.push(arguments);}
		gtag('js', new Date());

		gtag('config', '{{ GOOGLEANALYTICS_ID }}', {
			{%- EVENT phpbb_googleanalytics_gtag_options -%}
			{%- if S_REGISTERED_USER %}'user_id': '{{ GOOGLEANALYTICS_USER_ID }}',{% endif -%}
			{%- if S_ANONYMIZE_IP %}'anonymize_ip': true,{% endif -%}
			{%- if S_COOKIE_SECURE -%}'cookie_flags': 'samesite=none;secure',{%- endif -%}
		});
	</script>
{% endif %}
```
