const fs = require('fs');
const path = require('path');
const { JSDOM, VirtualConsole } = require('jsdom');

const scriptSource = fs.readFileSync(
	path.join(__dirname, '..', '..', 'styles', 'all', 'template', 'js', 'consentmanager.js'),
	'utf8'
);

function createPayload(overrides) {
	return Object.assign({
		version: '2026-04-28',
		storageKey: 'phpbb-consent-state',
		cookieName: 'phpbb_consent_state',
		logEndpoint: '/app.php/consent/log',
		logHash: 'test-hash',
		categories: [
			{ id: 'necessary', enabled: true, required: true },
			{ id: 'analytics', enabled: true, required: false },
			{ id: 'marketing', enabled: true, required: false },
			{ id: 'media', enabled: true, required: false }
		],
		requiredCategories: ['necessary'],
		enabledCategories: ['necessary', 'analytics', 'marketing', 'media'],
		optionalCategories: ['analytics', 'marketing', 'media'],
		scripts: []
	}, overrides || {});
}

function createLang(overrides) {
	return Object.assign({
		mediaPlaceholderLabel: 'This content is blocked until you allow embedded media in the Privacy Settings.'
	}, overrides || {});
}

function createMarkup(extraMarkup) {
	return `
		<!doctype html>
		<html>
			<head></head>
			<body>
				<div id="consent-manager-root">
					<div id="consent-manager-banner" hidden></div>
					<a id="consent-manager-link" href="#">Privacy settings</a>
					<div id="consent-manager-link-item" hidden></div>
					<div id="consent-manager-modal" hidden>
						<div class="consent-manager-modal-panel" tabindex="-1">
							<input type="checkbox" data-consent-toggle="analytics">
							<input type="checkbox" data-consent-toggle="marketing">
							<input type="checkbox" data-consent-toggle="media">
							<button type="button" data-consent-action="accept-all">Accept all</button>
							<button type="button" data-consent-action="reject-all">Reject all</button>
							<button type="button" data-consent-action="open-settings">Open settings</button>
							<button type="button" data-consent-action="close-settings">Close settings</button>
							<button type="button" data-consent-action="save-settings">Save settings</button>
						</div>
					</div>
				</div>
				${extraMarkup || ''}
			</body>
		</html>
	`;
}

function createState(categories, timestamp) {
	return {
		categories: categories,
		timestamp: timestamp,
		version: '2026-04-28'
	};
}

function getCookieValue(document, name) {
	const cookies = document.cookie ? document.cookie.split('; ') : [];

	for (let index = 0; index < cookies.length; index++) {
		if (cookies[index].indexOf(name + '=') === 0) {
			return decodeURIComponent(cookies[index].substring(name.length + 1));
		}
	}

	return '';
}

function click(window, selector) {
	window.document.querySelector(selector).dispatchEvent(new window.MouseEvent('click', {
		bubbles: true
	}));
}

function setupConsentManager(options) {
	const settings = options || {};
	const payload = createPayload(settings.payload);
	const jsdomErrors = [];
	const virtualConsole = new VirtualConsole();
	virtualConsole.on('jsdomError', (error) => {
		jsdomErrors.push(error);
	});
	const dom = new JSDOM(createMarkup(settings.extraMarkup), {
		runScripts: 'dangerously',
		url: 'https://example.com/',
		virtualConsole
	});
	const { window } = dom;
	const requests = [];

	Object.defineProperty(window.document, 'readyState', {
		configurable: true,
		value: settings.readyState || 'complete'
	});

	window.XMLHttpRequest = class FakeXMLHttpRequest {
		constructor() {
			this.headers = {};
		}

		open(method, url, async) {
			this.method = method;
			this.url = url;
			this.async = async;
		}

		setRequestHeader(name, value) {
			this.headers[name] = value;
		}

		send(body) {
			this.body = body;
			requests.push(this);
		}
	};

	if (settings.queue) {
		window.consentManager = settings.queue;
	}

	if (settings.localState) {
		window.localStorage.setItem(payload.storageKey, JSON.stringify(settings.localState));
	}

	if (settings.cookieState) {
		window.document.cookie = payload.cookieName + '=' + encodeURIComponent(JSON.stringify(settings.cookieState));
	}

	window.phpbbConsentManagerPayload = payload;
	window.phpbbConsentManagerLang = createLang(settings.lang);
	window.eval(scriptSource);

	if (settings.readyState === 'loading') {
		window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
	}

	return {
		dom,
		window,
		document: window.document,
		payload,
		requests,
		jsdomErrors
	};
}

afterEach(() => {
	jest.restoreAllMocks();
});

test('prefers the newest stored state and synchronizes cookie and local storage', () => {
	const localState = createState(['necessary', 'marketing'], '2026-04-27T00:00:00.000Z');
	const cookieState = createState(['necessary', 'analytics'], '2026-04-28T00:00:00.000Z');
	const { window, document, payload } = setupConsentManager({
		localState,
		cookieState
	});

	expect(window.consentManager.getState()).toEqual(cookieState);
	expect(JSON.parse(window.localStorage.getItem(payload.storageKey))).toEqual(cookieState);
	expect(JSON.parse(getCookieValue(document, payload.cookieName))).toEqual(cookieState);
});

test('replays queued API calls and executes consented registered scripts', () => {
	const ready = jest.fn();
	const { window } = setupConsentManager({
		localState: createState(['necessary', 'analytics'], '2026-04-28T00:00:00.000Z'),
		queue: {
			_queue: [
				[
					'registerScript',
					'analytics-inline',
					{
						category: 'analytics',
						inline: 'window.analyticsCounter = (window.analyticsCounter || 0) + 1;'
					}
				],
				['ready', ready]
			]
		}
	});

	expect(window.analyticsCounter).toBe(1);
	expect(ready).toHaveBeenCalledTimes(1);
	expect(ready).toHaveBeenCalledWith(window.consentManager);
});

test('accept-all persists consent, logs the decision, and updates the UI state', () => {
	const { window, document, payload, requests } = setupConsentManager();

	expect(document.getElementById('consent-manager-banner').hidden).toBe(false);
	expect(document.getElementById('consent-manager-link-item').hidden).toBe(true);
	expect(document.getElementById('consent-manager-link').getAttribute('aria-hidden')).toBe('true');

	click(window, '[data-consent-action="accept-all"]');

	expect(window.consentManager.getState()).toEqual({
		categories: ['necessary', 'analytics', 'marketing', 'media'],
		timestamp: expect.any(String),
		version: payload.version
	});
	expect(JSON.parse(window.localStorage.getItem(payload.storageKey))).toEqual(window.consentManager.getState());
	expect(document.getElementById('consent-manager-banner').hidden).toBe(true);
	expect(document.getElementById('consent-manager-link-item').hidden).toBe(false);
	expect(document.getElementById('consent-manager-link').getAttribute('aria-hidden')).toBe('false');
	expect(requests).toHaveLength(1);
	expect(requests[0].method).toBe('POST');
	expect(requests[0].url).toBe(payload.logEndpoint);
	expect(JSON.parse(requests[0].body)).toEqual({
		hash: payload.logHash,
		version: payload.version,
		categories: ['necessary', 'analytics', 'marketing', 'media']
	});
});

test('registerScript blocks unsafe sources and executes safe inline scripts', () => {
	const { window, document } = setupConsentManager({
		localState: createState(['necessary', 'analytics'], '2026-04-28T00:00:00.000Z')
	});

	expect(window.consentManager.registerScript('unsafe', {
		category: 'analytics',
		src: 'javascript:alert(1)'
	})).toBe(false);

	expect(window.consentManager.registerScript('safe-inline', {
		category: 'analytics',
		inline: 'window.safeInlineLoaded = true;'
	})).toBe(true);

	expect(window.safeInlineLoaded).toBe(true);
	expect(document.head.querySelectorAll('script[src]').length).toBe(0);
});

test('processes deferred consent scripts and copies only safe attributes', () => {
	const { window, document } = setupConsentManager({
		localState: createState(['necessary', 'analytics'], '2026-04-28T00:00:00.000Z'),
		extraMarkup: `
			<script
				type="text/plain"
				data-consent-category="analytics"
				data-extra="allowed"
				onclick="window.shouldNotRun = true;"
			>window.deferredLoaded = true;</script>
		`
	});

	const source = document.querySelector('script[type="text/plain"][data-consent-category="analytics"]');
	const liveScript = source.nextSibling;

	expect(source.getAttribute('data-consent-processed')).toBe('1');
	expect(liveScript.tagName).toBe('SCRIPT');
	expect(liveScript.getAttribute('data-extra')).toBe('allowed');
	expect(liveScript.hasAttribute('onclick')).toBe(false);
	expect(window.deferredLoaded).toBe(true);
});

test('activates deferred media embeds after media consent is granted', () => {
	const { window, document } = setupConsentManager({
		localState: createState(['necessary', 'media'], '2026-04-28T00:00:00.000Z'),
		extraMarkup: `
			<span data-consent-media-container="1" data-consent-category="media">
				<span data-consent-media-placeholder="1"></span>
				<span data-consent-media-content="1" hidden="hidden">
					<iframe
						data-consent-media-frame="1"
						data-consent-src="https://media.example.com/embed/123"
						data-consent-onload="window.mediaLoaded = true;"
					></iframe>
				</span>
			</span>
		`
	});

	const container = document.querySelector('[data-consent-media-container="1"]');
	const placeholder = container.querySelector('[data-consent-media-placeholder="1"]');
	const content = container.querySelector('[data-consent-media-content="1"]');
	const frame = content.querySelector('iframe');

	expect(container.getAttribute('data-consent-processed')).toBe('1');
	expect(placeholder.hidden).toBe(true);
	expect(content.hidden).toBe(false);
	expect(placeholder.textContent).toBe('This content is blocked until you allow embedded media in the Privacy Settings.');
	expect(frame.getAttribute('src')).toBe('https://media.example.com/embed/123');
	expect(frame.hasAttribute('data-consent-src')).toBe(false);
	expect(frame.getAttribute('onload')).toBe('window.mediaLoaded = true;');
});

test('saving newly granted media consent activates blocked embeds immediately', () => {
	const { window, document } = setupConsentManager({
		extraMarkup: `
			<span data-consent-media-container="1" data-consent-category="media">
				<span data-consent-media-placeholder="1"></span>
				<span data-consent-media-content="1" hidden="hidden">
					<iframe
						data-consent-media-frame="1"
						data-consent-src="https://media.example.com/embed/123"
					></iframe>
				</span>
			</span>
		`
	});

	const container = document.querySelector('[data-consent-media-container="1"]');
	const placeholder = container.querySelector('[data-consent-media-placeholder="1"]');
	const content = container.querySelector('[data-consent-media-content="1"]');
	const frame = content.querySelector('iframe');
	const mediaCheckbox = document.querySelector('[data-consent-toggle="media"]');

	expect(placeholder.hidden).toBe(false);
	expect(content.hidden).toBe(true);
	expect(placeholder.textContent).toBe('This content is blocked until you allow embedded media in the Privacy Settings.');
	expect(frame.hasAttribute('src')).toBe(false);

	mediaCheckbox.checked = true;
	click(window, '[data-consent-action="save-settings"]');

	expect(container.getAttribute('data-consent-processed')).toBe('1');
	expect(placeholder.hidden).toBe(true);
	expect(content.hidden).toBe(false);
	expect(frame.getAttribute('src')).toBe('https://media.example.com/embed/123');
	expect(frame.hasAttribute('data-consent-src')).toBe(false);
});

test('activates deferred embeds when the media content element is the iframe itself', () => {
	const { document } = setupConsentManager({
		localState: createState(['necessary', 'media'], '2026-04-28T00:00:00.000Z'),
		extraMarkup: `
			<span data-consent-media-container="1" data-consent-category="media">
				<span data-consent-media-placeholder="1"></span>
				<iframe
					data-consent-media-frame="1"
					data-consent-media-content="1"
					hidden="hidden"
					data-consent-src="https://media.example.com/embed/456"
				></iframe>
			</span>
		`
	});

	const container = document.querySelector('[data-consent-media-container="1"]');
	const placeholder = container.querySelector('[data-consent-media-placeholder="1"]');
	const frame = container.querySelector('[data-consent-media-content="1"]');

	expect(container.getAttribute('data-consent-processed')).toBe('1');
	expect(placeholder.hidden).toBe(true);
	expect(frame.hidden).toBe(false);
	expect(frame.getAttribute('src')).toBe('https://media.example.com/embed/456');
	expect(frame.hasAttribute('data-consent-src')).toBe(false);
});

test('saving consent activates deferred embeds when the media content element is the iframe itself', () => {
	const { window, document } = setupConsentManager({
		extraMarkup: `
			<span data-consent-media-container="1" data-consent-category="media">
				<span data-consent-media-placeholder="1"></span>
				<iframe
					data-consent-media-frame="1"
					data-consent-media-content="1"
					hidden="hidden"
					data-consent-src="https://media.example.com/embed/456"
				></iframe>
			</span>
		`
	});

	const container = document.querySelector('[data-consent-media-container="1"]');
	const placeholder = container.querySelector('[data-consent-media-placeholder="1"]');
	const frame = container.querySelector('[data-consent-media-content="1"]');
	const mediaCheckbox = document.querySelector('[data-consent-toggle="media"]');

	expect(placeholder.hidden).toBe(false);
	expect(frame.hidden).toBe(true);
	expect(frame.hasAttribute('src')).toBe(false);

	mediaCheckbox.checked = true;
	click(window, '[data-consent-action="save-settings"]');

	expect(container.getAttribute('data-consent-processed')).toBe('1');
	expect(placeholder.hidden).toBe(true);
	expect(frame.hidden).toBe(false);
	expect(frame.getAttribute('src')).toBe('https://media.example.com/embed/456');
	expect(frame.hasAttribute('data-consent-src')).toBe(false);
});

test('revoking only media consent reloads the page', () => {
	const { window, document, jsdomErrors } = setupConsentManager({
		localState: createState(['necessary', 'media'], '2026-04-28T00:00:00.000Z')
	});
	const analyticsCheckbox = document.querySelector('[data-consent-toggle="analytics"]');
	const marketingCheckbox = document.querySelector('[data-consent-toggle="marketing"]');
	const mediaCheckbox = document.querySelector('[data-consent-toggle="media"]');

	analyticsCheckbox.checked = false;
	marketingCheckbox.checked = false;
	mediaCheckbox.checked = false;

	click(window, '[data-consent-action="save-settings"]');

	expect(jsdomErrors).toHaveLength(1);
	expect(jsdomErrors[0].message).toContain('Not implemented: navigation');
});
