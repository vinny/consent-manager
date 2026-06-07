(function(window, document) {
	'use strict';

	const payload = window.phpbbConsentManagerPayload || null;
	const lang = window.phpbbConsentManagerLang || {};
	const existingApi = window.consentManager || {};
	const queued = existingApi._queue ? existingApi._queue.slice(0) : [];
	const listeners = [];
	const registry = {};
	const executedScripts = {};
	const executedCategories = {};
	let root = null;
	let state = null;
	let isRendered = false;
	let isBound = false;
	let pendingOpenSettings = false;
	let keydownBound = false;
	let lastFocusedElement = null;
	const categoriesById = {};
	const deferredSelector = 'script[type="text/plain"][data-consent-category]';
	const deferredEmbedSelector = '[data-consent-media-container][data-consent-category]';
	const googleConsentMode = payload.googleConsentMode || {};
	const googleConsentTypes = googleConsentMode.types || {};
	let requiredCategories = [];
	let enabledCategories = [];
	let optionalCategories = [];
	let hasStructuredPolicy = false;

	if (!payload || !payload.categories)
	{
		return;
	}

	const mediaPlaceholderLabel = typeof lang.mediaPlaceholderLabel === 'string' ? lang.mediaPlaceholderLabel : '';

	function isArray(value)
	{
		return Object.prototype.toString.call(value) === '[object Array]';
	}

	for (let i = 0; i < payload.categories.length; i++)
	{
		categoriesById[payload.categories[i].id] = payload.categories[i];
	}

	if (isArray(payload.requiredCategories))
	{
		requiredCategories = unique(payload.requiredCategories);
	}

	if (isArray(payload.enabledCategories))
	{
		enabledCategories = unique(payload.enabledCategories);
	}

	if (isArray(payload.optionalCategories))
	{
		optionalCategories = unique(payload.optionalCategories);
	}

	hasStructuredPolicy = isArray(payload.requiredCategories) && isArray(payload.enabledCategories) && isArray(payload.optionalCategories);
	if (!hasStructuredPolicy)
	{
		for (let i = 0; i < payload.categories.length; i++)
		{
			if (payload.categories[i].enabled)
			{
				enabledCategories.push(payload.categories[i].id);
			}

			if (payload.categories[i].required)
			{
				requiredCategories.push(payload.categories[i].id);
			}

			if (payload.categories[i].enabled && !payload.categories[i].required)
			{
				optionalCategories.push(payload.categories[i].id);
			}
		}

		requiredCategories = unique(requiredCategories);
		enabledCategories = unique(enabledCategories);
		optionalCategories = unique(optionalCategories);
	}

	state = loadAndSyncState();

	function safeParse(raw)
	{
		try
		{
			return JSON.parse(raw);
		}
		catch (error)
		{
			return null;
		}
	}

	function setCookie(name, value, maxAge)
	{
		let cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';

		if (typeof maxAge === 'number')
		{
			cookie += '; max-age=' + maxAge;
		}

		document.cookie = cookie;
	}

	function clearCookie(name)
	{
		setCookie(name, '', 0);
	}

	function getCookie(name)
	{
		const cookies = document.cookie ? document.cookie.split('; ') : [];

		for (let index = 0; index < cookies.length; index++)
		{
			if (cookies[index].indexOf(name + '=') === 0)
			{
				return decodeURIComponent(cookies[index].substring(name.length + 1));
			}
		}

		return '';
	}

	function removeStoredState()
	{
		try
		{
			if (window.localStorage)
			{
				window.localStorage.removeItem(payload.storageKey);
			}
		}
		catch (error)
		{
		}

		clearCookie(payload.cookieName);
	}

	function persistState(nextState)
	{
		const serialized = JSON.stringify(nextState);

		try
		{
			if (window.localStorage)
			{
				window.localStorage.setItem(payload.storageKey, serialized);
			}
		}
		catch (error)
		{
		}

		setCookie(payload.cookieName, serialized, 31536000);
	}

	function normalizeCategories(categories)
	{
		const normalized = requiredCategories.slice(0);

		if (!isArray(categories))
		{
			return unique(normalized);
		}

		for (let index = 0; index < categories.length; index++)
		{
			const categoryId = String(categories[index]);
			if (requiredCategories.indexOf(categoryId) === -1 && enabledCategories.indexOf(categoryId) !== -1 && categoriesById[categoryId])
			{
				normalized.push(categoryId);
			}
		}

		return unique(normalized);
	}

	function validateState(candidate)
	{
		if (!candidate || candidate.version !== payload.version || !isArray(candidate.categories))
		{
			return null;
		}

		const timestamp = typeof candidate.timestamp === 'string' ? candidate.timestamp : '';

		return {
			categories: normalizeCategories(candidate.categories),
			timestamp: timestamp || new Date().toISOString(),
			version: payload.version
		};
	}

	function compareStates(left, right)
	{
		return left && right
			&& left.version === right.version
			&& left.categories.join('|') === right.categories.join('|')
			&& left.timestamp === right.timestamp;
	}

	function choosePreferredState(localState, cookieState)
	{
		if (localState && cookieState)
		{
			const localTime = Date.parse(localState.timestamp || '');
			const cookieTime = Date.parse(cookieState.timestamp || '');

			if (!isNaN(localTime) && !isNaN(cookieTime))
			{
				return localTime >= cookieTime ? localState : cookieState;
			}
		}

		return localState || cookieState || null;
	}

	function loadAndSyncState()
	{
		let localRaw = '';
		const cookieRaw = getCookie(payload.cookieName);
		let localState = null;
		let cookieState = null;

		try
		{
			if (window.localStorage)
			{
				localRaw = window.localStorage.getItem(payload.storageKey) || '';
			}
		}
		catch (error)
		{
			localRaw = '';
		}

		if (localRaw)
		{
			localState = validateState(safeParse(localRaw));
		}

		if (cookieRaw)
		{
			cookieState = validateState(safeParse(cookieRaw));
		}

		state = choosePreferredState(localState, cookieState);

		if (!state)
		{
			removeStoredState();
			return null;
		}

		if (!compareStates(localState, state) || !compareStates(cookieState, state))
		{
			persistState(state);
		}

		return state;
	}

	function unique(items)
	{
		const deduplicated = [];

		for (let index = 0; index < items.length; index++)
		{
			if (deduplicated.indexOf(items[index]) === -1)
			{
				deduplicated.push(items[index]);
			}
		}

		return deduplicated;
	}

	function hasConsent(category)
	{
		if (requiredCategories.indexOf(category) !== -1)
		{
			return true;
		}

		return !!(state && enabledCategories.indexOf(category) !== -1 && state.categories.indexOf(category) !== -1);
	}

	function getStateSnapshot()
	{
		return state ? {
			categories: state.categories.slice(0),
			timestamp: state.timestamp,
			version: state.version
		} : null;
	}

	function emitChange()
	{
		const snapshot = getStateSnapshot();

		for (let index = 0; index < listeners.length; index++)
		{
			try
			{
				listeners[index](snapshot);
			}
			catch (error)
			{
				if (window.console && typeof window.console.error === 'function')
				{
					window.console.error(error);
				}
			}
		}
	}

	function buildGoogleConsentModeState()
	{
		const consentState = {};

		if (!googleConsentMode.enabled)
		{
			return consentState;
		}

		for (const consentType in googleConsentTypes)
		{
			if (Object.prototype.hasOwnProperty.call(googleConsentTypes, consentType))
			{
				consentState[consentType] = hasConsent(googleConsentTypes[consentType]) ? 'granted' : 'denied';
			}
		}

		return consentState;
	}

	function applyGoogleConsentMode()
	{
		if (!googleConsentMode.enabled || typeof window.gtag !== 'function')
		{
			return;
		}

		const consentState = buildGoogleConsentModeState();

		for (const consentType in consentState)
		{
			if (Object.prototype.hasOwnProperty.call(consentState, consentType))
			{
				window.gtag('consent', 'update', consentState);
				return;
			}
		}
	}

	function sameCategories(left, right)
	{
		return left.join('|') === right.join('|');
	}

	function shouldReload(previousState, nextState)
	{
		const removedCategories = [];

		if (!previousState)
		{
			return false;
		}

		for (let index = 0; index < previousState.categories.length; index++)
		{
			if (requiredCategories.indexOf(previousState.categories[index]) === -1 && nextState.categories.indexOf(previousState.categories[index]) === -1)
			{
				removedCategories.push(previousState.categories[index]);
			}
		}

		for (const scriptId in executedScripts)
		{
			if (executedScripts.hasOwnProperty(scriptId) && removedCategories.indexOf(executedScripts[scriptId]) !== -1)
			{
				return true;
			}
		}

		for (let index = 0; index < removedCategories.length; index++)
		{
			if (removedCategories[index] === 'media')
			{
				// Media embeds may be rendered live by the server when consent was already granted,
				// so revoking media consent must reload even if no client-side activation was tracked.
				return true;
			}

			if (executedCategories[removedCategories[index]])
			{
				return true;
			}
		}

		return false;
	}

	function logDecision()
	{
		if (!payload.logEndpoint || !state)
		{
			return;
		}

		const request = new XMLHttpRequest();
		request.open('POST', payload.logEndpoint, true);
		request.setRequestHeader('Content-Type', 'application/json');
		request.send(buildLogDecisionBody());
	}

	function buildLogDecisionBody()
	{
		return JSON.stringify({
			hash: payload.logHash,
			version: payload.version,
			categories: state.categories
		});
	}

	function logDecisionBeforeReload(onComplete)
	{
		if (!payload.logEndpoint || !state)
		{
			onComplete();
			return;
		}

		const body = buildLogDecisionBody();

		if (window.navigator && typeof window.navigator.sendBeacon === 'function')
		{
			try
			{
				if (window.navigator.sendBeacon(payload.logEndpoint, body))
				{
					onComplete();
					return;
				}
			}
			catch (error)
			{
			}
		}

		const request = new XMLHttpRequest();
		let completed = false;
		const finish = function() {
			if (completed)
			{
				return;
			}

			completed = true;
			onComplete();
		};

		request.open('POST', payload.logEndpoint, true);
		request.setRequestHeader('Content-Type', 'application/json');
		request.onloadend = finish;
		request.onerror = finish;

		window.setTimeout(finish, 1000);

		try
		{
			request.send(body);
		}
		catch (error)
		{
			finish();
		}
	}

	function setState(categories)
	{
		const nextState = {
			categories: normalizeCategories(categories),
			timestamp: new Date().toISOString(),
			version: payload.version
		};
		const reloadRequired = shouldReload(state, nextState);

		if (state && sameCategories(state.categories, nextState.categories))
		{
			state.timestamp = nextState.timestamp;
			persistState(state);
			updateUi();
			applyGoogleConsentMode();
			return;
		}

		state = nextState;
		persistState(state);
		updateUi();
		applyGoogleConsentMode();
		processRegisteredScripts();
		processDeferredNodes(document);
		processDeferredEmbeds(document);
		emitChange();

		if (reloadRequired)
		{
			logDecisionBeforeReload(function() {
				window.location.reload();
			});
			return;
		}

		logDecision();
	}

	function isSafeScriptSource(src)
	{
		if (!src || /[<>"']/.test(src) || src.indexOf('//') === 0 || /^(?:javascript|data|vbscript|file):/i.test(src))
		{
			return false;
		}

		const link = document.createElement('a');
		link.href = src;
		const protocol = (link.protocol || '').toLowerCase();

		return protocol === '' || protocol === 'http:' || protocol === 'https:';
	}

	function isSafeEmbedSource(src)
	{
		if (!src || /[<>"']/.test(src) || /^(?:javascript|data|vbscript|file):/i.test(src))
		{
			return false;
		}

		let normalized = src;
		if (src.indexOf('//') === 0)
		{
			normalized = window.location.protocol + src;
		}

		const link = document.createElement('a');
		link.href = normalized;
		const protocol = (link.protocol || '').toLowerCase();

		return protocol === 'http:' || protocol === 'https:';
	}

	function isSafeAttributeName(name)
	{
		return !!name
			&& /^[a-zA-Z_:][a-zA-Z0-9_:\.-]*$/.test(name)
			&& !/^on/i.test(name)
			&& [ 'src', 'type', 'async', 'defer' ].indexOf(String(name).toLowerCase()) === -1;
	}

	function applyAttributes(element, attributes)
	{
		for (const name in attributes)
		{
			if (attributes.hasOwnProperty(name) && isSafeAttributeName(name))
			{
				element.setAttribute(name, attributes[name]);
			}
		}
	}

	function executeScript(script)
	{
		if (!script || !script.id || executedScripts[script.id] || !hasConsent(script.category))
		{
			return false;
		}

		if (script.src && !isSafeScriptSource(script.src))
		{
			return false;
		}

		if (script.wait_for_dom_ready && document.readyState === 'loading')
		{
			return false;
		}

		const element = document.createElement('script');
		element.type = 'text/javascript';

		if (script.src)
		{
			element.src = script.src;
			if (script.async)
			{
				element.async = true;
			}
			if (script.defer)
			{
				element.defer = true;
			}
		}
		else if (script.inline)
		{
			element.text = script.inline;
		}
		else
		{
			return false;
		}

		applyAttributes(element, script.attributes || {});
		document.head.appendChild(element);

		executedScripts[script.id] = script.category;
		executedCategories[script.category] = true;

		return true;
	}

	function registerScript(id, options)
	{
		if (!id || !options || !options.category)
		{
			return false;
		}

		registry[id] = {
			id: String(id),
			category: String(options.category),
			src: options.src ? String(options.src) : '',
			inline: options.inline ? String(options.inline) : '',
			async: !!options.async,
			defer: !!options.defer,
			wait_for_dom_ready: !!options.wait_for_dom_ready,
			attributes: options.attributes || {}
		};

		return executeScript(registry[id]);
	}

	function processRegisteredScripts()
	{
		for (const scriptId in registry)
		{
			if (registry.hasOwnProperty(scriptId))
			{
				executeScript(registry[scriptId]);
			}
		}
	}

	function collectMatchingNodes(scope, selector)
	{
		const nodes = [];

		if (!scope)
		{
			return nodes;
		}

		if (scope.nodeType === 1 && scope.matches && scope.matches(selector))
		{
			nodes.push(scope);
		}

		if (scope.querySelectorAll)
		{
			const matched = scope.querySelectorAll(selector);
			for (let index = 0; index < matched.length; index++)
			{
				nodes.push(matched[index]);
			}
		}

		return nodes;
	}

	function processDeferredNodes(scope)
	{
		const nodes = collectMatchingNodes(scope, deferredSelector);

		for (let index = 0; index < nodes.length; index++)
		{
			const source = nodes[index];
			const category = source.getAttribute('data-consent-category');

			if (source.getAttribute('data-consent-processed') === '1' || !hasConsent(category))
			{
				continue;
			}

			const sourceUrl = source.getAttribute('src');
			if (sourceUrl && !isSafeScriptSource(sourceUrl))
			{
				continue;
			}

			const liveScript = document.createElement('script');
			liveScript.type = 'text/javascript';

			for (let attributeIndex = 0; attributeIndex < source.attributes.length; attributeIndex++)
			{
				const attribute = source.attributes[attributeIndex];
				if (attribute.name === 'type' || attribute.name.indexOf('data-consent-') === 0 || attribute.name === 'src')
				{
					continue;
				}

				if (isSafeAttributeName(attribute.name))
				{
					liveScript.setAttribute(attribute.name, attribute.value);
				}
			}

			if (sourceUrl)
			{
				liveScript.src = sourceUrl;
				if (source.hasAttribute('async'))
				{
					liveScript.async = true;
				}
				if (source.hasAttribute('defer'))
				{
					liveScript.defer = true;
				}
			}
			else
			{
				liveScript.text = source.textContent;
			}

			source.setAttribute('data-consent-processed', '1');
			source.parentNode.insertBefore(liveScript, source.nextSibling);
			executedCategories[category] = true;
		}
	}

	function renderMediaPlaceholder(placeholder)
	{
		if (!placeholder)
		{
			return;
		}

		const source = placeholder.getAttribute('data-consent-link');
		placeholder.textContent = mediaPlaceholderLabel || '';

		if (!source || !isSafeEmbedSource(source))
		{
			return;
		}

		if (mediaPlaceholderLabel)
		{
			placeholder.appendChild(document.createTextNode(' '));
		}

		const link = document.createElement('a');
		link.href = source;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.textContent = source;
		placeholder.appendChild(link);
	}

	function processDeferredEmbeds(scope)
	{
		const nodes = collectMatchingNodes(scope, deferredEmbedSelector);

		for (let index = 0; index < nodes.length; index++)
		{
			const container = nodes[index];
			const category = container.getAttribute('data-consent-category');
			const content = container.querySelector('[data-consent-media-content]');
			const placeholder = container.querySelector('[data-consent-media-placeholder]');
			const frames = container.querySelectorAll('[data-consent-media-frame]');

			renderMediaPlaceholder(placeholder);

			if (!content || !frames.length)
			{
				continue;
			}

			if (!hasConsent(category))
			{
				content.hidden = true;
				if (placeholder)
				{
					placeholder.hidden = false;
				}
				continue;
			}

			if (container.getAttribute('data-consent-processed') !== '1')
			{
				let activated = 0;

				for (let frameIndex = 0; frameIndex < frames.length; frameIndex++)
				{
					const frame = frames[frameIndex];
					const source = frame.getAttribute('data-consent-src');

					if (!source || !isSafeEmbedSource(source))
					{
						continue;
					}

					if (frame.hasAttribute('data-consent-onload'))
					{
						frame.setAttribute('onload', frame.getAttribute('data-consent-onload'));
						frame.removeAttribute('data-consent-onload');
					}

					frame.setAttribute('src', source);
					frame.removeAttribute('data-consent-src');

					activated++;
				}

				if (!activated)
				{
					continue;
				}

				container.setAttribute('data-consent-processed', '1');
			}

			content.hidden = false;
			if (placeholder)
			{
				placeholder.hidden = true;
			}
			executedCategories[category] = true;
		}
	}

	function observeDeferredNodes()
	{
		if (typeof MutationObserver === 'undefined' || !document.documentElement)
		{
			return;
		}

		const observer = new MutationObserver(function(mutations) {
			for (let mutationIndex = 0; mutationIndex < mutations.length; mutationIndex++)
			{
				for (let nodeIndex = 0; nodeIndex < mutations[mutationIndex].addedNodes.length; nodeIndex++)
				{
					processDeferredNodes(mutations[mutationIndex].addedNodes[nodeIndex]);
					processDeferredEmbeds(mutations[mutationIndex].addedNodes[nodeIndex]);
				}
			}
		});

		observer.observe(document.documentElement, {
			childList: true,
			subtree: true
		});
	}

	function initUi()
	{
		root = document.getElementById('consent-manager-root');

		if (!root)
		{
			return;
		}

		isRendered = true;
		updateUi();

		if (!isBound)
		{
			bindUi();
		}

		if (pendingOpenSettings)
		{
			pendingOpenSettings = false;
			openSettings();
		}
	}

	function updateUi()
	{
		if (!isRendered)
		{
			return;
		}

		const banner = document.getElementById('consent-manager-banner');
		const link = document.getElementById('consent-manager-link');
		const linkItem = document.getElementById('consent-manager-link-item');

		if (banner)
		{
			banner.hidden = !!state || !optionalCategories.length;
		}

		if (linkItem)
		{
			linkItem.hidden = !state || !optionalCategories.length;
		}

		if (link)
		{
			link.setAttribute('aria-hidden', !state || !optionalCategories.length ? 'true' : 'false');
		}
	}

	function selectedOptionalCategories()
	{
		const selected = [];

		if (!root)
		{
			return selected;
		}

		const checkboxes = root.querySelectorAll('[data-consent-toggle]');
		for (let index = 0; index < checkboxes.length; index++)
		{
			if (checkboxes[index].checked)
			{
				selected.push(checkboxes[index].getAttribute('data-consent-toggle'));
			}
		}

		return selected;
	}

	function getModal()
	{
		return document.getElementById('consent-manager-modal');
	}

	function getModalPanel()
	{
		const modal = getModal();
		return modal ? modal.querySelector('.consent-manager-modal-panel') : null;
	}

	function getFocusableNodes(container)
	{
		const focusable = [];

		if (!container)
		{
			return focusable;
		}

		const nodes = container.querySelectorAll('a[href], button:not([disabled]), textarea, input:not([disabled]), select, [tabindex]:not([tabindex="-1"])');
		for (let index = 0; index < nodes.length; index++)
		{
			if (!nodes[index].hidden)
			{
				focusable.push(nodes[index]);
			}
		}

		return focusable;
	}

	function handleModalKeydown(event)
	{
		const modal = getModal();

		if (!modal || modal.hidden)
		{
			return;
		}

		if (event.key === 'Escape')
		{
			closeSettings();
			return;
		}

		if (event.key !== 'Tab')
		{
			return;
		}

		const focusable = getFocusableNodes(getModalPanel());
		if (!focusable.length)
		{
			return;
		}

		const first = focusable[0];
		const last = focusable[focusable.length - 1];

		if (event.shiftKey && document.activeElement === first)
		{
			last.focus();
			event.preventDefault();
		}
		else if (!event.shiftKey && document.activeElement === last)
		{
			first.focus();
			event.preventDefault();
		}
	}

	function openSettings()
	{
		if (!isRendered)
		{
			pendingOpenSettings = true;
			return;
		}

		const modal = getModal();
		const panel = getModalPanel();
		const checkboxes = root.querySelectorAll('[data-consent-toggle]');

		for (let index = 0; index < checkboxes.length; index++)
		{
			checkboxes[index].checked = hasConsent(checkboxes[index].getAttribute('data-consent-toggle'));
		}

		if (!modal)
		{
			return;
		}

		lastFocusedElement = document.activeElement;
		modal.hidden = false;

		if (document.body)
		{
			document.body.classList.add('consent-manager-open');
		}

		if (!keydownBound)
		{
			document.addEventListener('keydown', handleModalKeydown);
			keydownBound = true;
		}

		if (panel)
		{
			panel.focus();
		}
	}

	function closeSettings()
	{
		const modal = getModal();

		if (!modal)
		{
			return;
		}

		modal.hidden = true;

		if (document.body)
		{
			document.body.classList.remove('consent-manager-open');
		}

		if (keydownBound)
		{
			document.removeEventListener('keydown', handleModalKeydown);
			keydownBound = false;
		}

		if (lastFocusedElement && typeof lastFocusedElement.focus === 'function')
		{
			lastFocusedElement.focus();
		}
	}

	function bindUi()
	{
		const footerLink = document.getElementById('consent-manager-link');

		root.addEventListener('click', function(event) {
			const action = event.target.getAttribute('data-consent-action');

			if (!action)
			{
				if (event.target.id === 'consent-manager-link')
				{
					openSettings();
				}

				return;
			}

			if (action === 'accept-all')
			{
				setState(optionalCategories.concat(requiredCategories));
				closeSettings();
			}
			else if (action === 'reject-all')
			{
				setState(requiredCategories);
				closeSettings();
			}
			else if (action === 'open-settings')
			{
				openSettings();
			}
			else if (action === 'close-settings')
			{
				closeSettings();
			}
			else if (action === 'save-settings')
			{
				setState(selectedOptionalCategories().concat(requiredCategories));
				closeSettings();
			}
		});

		if (footerLink)
		{
			footerLink.addEventListener('click', function(event) {
				event.preventDefault();
				openSettings();
			});
		}

		document.addEventListener('click', function(event) {
			let node = event.target;

			while (node && node !== document)
			{
				if (node.getAttribute && node.getAttribute('data-consent-open-settings') === '1')
				{
					event.preventDefault();
					openSettings();
					return;
				}

				node = node.parentNode;
			}
		});

		isBound = true;
	}

	function onChange(callback)
	{
		if (typeof callback !== 'function')
		{
			return;
		}

		listeners.push(callback);
		callback(getStateSnapshot());
	}

	function ready(callback)
	{
		if (typeof callback !== 'function')
		{
			return;
		}

		callback(api);
	}

	const api = {
		registerScript: registerScript,
		hasConsent: hasConsent,
		onChange: onChange,
		openSettings: openSettings,
		getState: getStateSnapshot,
		ready: ready
	};

	window.consentManager = api;

	applyGoogleConsentMode();

	for (let i = 0; i < payload.scripts.length; i++)
	{
		registerScript(payload.scripts[i].id, payload.scripts[i]);
	}

	for (let i = 0; i < queued.length; i++)
	{
		if (queued[i][0] === 'registerScript')
		{
			registerScript(queued[i][1], queued[i][2]);
		}
		else if (queued[i][0] === 'onChange')
		{
			onChange(queued[i][1]);
		}
		else if (queued[i][0] === 'openSettings')
		{
			pendingOpenSettings = true;
		}
		else if (queued[i][0] === 'ready')
		{
			ready(queued[i][1]);
		}
	}

	processRegisteredScripts();
	processDeferredNodes(document);
	processDeferredEmbeds(document);
	observeDeferredNodes();

	if (document.readyState === 'loading')
	{
		document.addEventListener('DOMContentLoaded', function() {
			processRegisteredScripts();
			initUi();
		});
	}
	else
	{
		initUi();
	}
})(window, document);
