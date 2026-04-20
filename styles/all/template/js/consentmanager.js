(function (window, document) {
	'use strict';

	var payload = window.phpbbConsentManagerPayload || null;
	var existingApi = window.consentManager || {};
	var queued = existingApi._queue ? existingApi._queue.slice(0) : [];
	var listeners = [];
	var consentCallbacks = [];
	var registry = {};
	var executedScripts = {};
	var executedCategories = {};
	var root = null;
	var state = null;
	var isRendered = false;
	var isBound = false;
	var pendingOpenSettings = false;
	var keydownBound = false;
	var lastFocusedElement = null;
	var categoriesById = {};
	var requiredCategories = [];
	var enabledCategories = [];
	var optionalCategories = [];
	var hasStructuredPolicy = false;
	var i;

	if (!payload || !payload.categories)
	{
		return;
	}

	function isArray(value)
	{
		return Object.prototype.toString.call(value) === '[object Array]';
	}

	for (i = 0; i < payload.categories.length; i++)
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
		for (i = 0; i < payload.categories.length; i++)
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
		var cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';

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
		var cookies = document.cookie ? document.cookie.split('; ') : [];
		var index;

		for (index = 0; index < cookies.length; index++)
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
		var serialized = JSON.stringify(nextState);

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
		var normalized = requiredCategories.slice(0);
		var index;
		var categoryId;

		if (!isArray(categories))
		{
			return unique(normalized);
		}

		for (index = 0; index < categories.length; index++)
		{
			categoryId = String(categories[index]);
			if (requiredCategories.indexOf(categoryId) === -1 && enabledCategories.indexOf(categoryId) !== -1 && categoriesById[categoryId])
			{
				normalized.push(categoryId);
			}
		}

		return unique(normalized);
	}

	function validateState(candidate)
	{
		var timestamp;

		if (!candidate || candidate.version !== payload.version || !isArray(candidate.categories))
		{
			return null;
		}

		timestamp = typeof candidate.timestamp === 'string' ? candidate.timestamp : '';

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
		var localTime;
		var cookieTime;

		if (localState && cookieState)
		{
			localTime = Date.parse(localState.timestamp || '');
			cookieTime = Date.parse(cookieState.timestamp || '');

			if (!isNaN(localTime) && !isNaN(cookieTime))
			{
				return localTime >= cookieTime ? localState : cookieState;
			}
		}

		return localState || cookieState || null;
	}

	function loadAndSyncState()
	{
		var localRaw = '';
		var cookieRaw = getCookie(payload.cookieName);
		var localState = null;
		var cookieState = null;

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
		var deduplicated = [];
		var index;

		for (index = 0; index < items.length; index++)
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
		var snapshot = getStateSnapshot();
		var index;

		for (index = 0; index < listeners.length; index++)
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

	function runConsentCallback(entry)
	{
		if (!entry || entry.fired || !hasConsent(entry.category))
		{
			return false;
		}

		entry.fired = true;

		try
		{
			entry.callback(getStateSnapshot());
			executedCategories[entry.category] = true;
			return true;
		}
		catch (error)
		{
			if (window.console && typeof window.console.error === 'function')
			{
				window.console.error(error);
			}
		}

		return false;
	}

	function processConsentCallbacks()
	{
		var index;

		for (index = 0; index < consentCallbacks.length; index++)
		{
			runConsentCallback(consentCallbacks[index]);
		}
	}

	function sameCategories(left, right)
	{
		return left.join('|') === right.join('|');
	}

	function shouldReload(previousState, nextState)
	{
		var removedCategories = [];
		var index;
		var scriptId;

		if (!previousState)
		{
			return false;
		}

		for (index = 0; index < previousState.categories.length; index++)
		{
			if (requiredCategories.indexOf(previousState.categories[index]) === -1 && nextState.categories.indexOf(previousState.categories[index]) === -1)
			{
				removedCategories.push(previousState.categories[index]);
			}
		}

		for (scriptId in executedScripts)
		{
			if (executedScripts.hasOwnProperty(scriptId) && removedCategories.indexOf(executedScripts[scriptId]) !== -1)
			{
				return true;
			}
		}

		for (index = 0; index < removedCategories.length; index++)
		{
			if (executedCategories[removedCategories[index]])
			{
				return true;
			}
		}

		return false;
	}

	function logDecision()
	{
		var request;

		if (!payload.logEndpoint || !state)
		{
			return;
		}

		request = new XMLHttpRequest();
		request.open('POST', payload.logEndpoint, true);
		request.setRequestHeader('Content-Type', 'application/json');
		request.send(JSON.stringify({
			hash: payload.logHash,
			version: payload.version,
			categories: state.categories
		}));
	}

	function setState(categories)
	{
		var nextState = {
			categories: normalizeCategories(categories),
			timestamp: new Date().toISOString(),
			version: payload.version
		};
		var reloadRequired = shouldReload(state, nextState);

		if (state && sameCategories(state.categories, nextState.categories))
		{
			state.timestamp = nextState.timestamp;
			persistState(state);
			updateUi();
			return;
		}

		state = nextState;
		persistState(state);
		updateUi();
		processRegisteredScripts();
		processDeferredNodes(document);
		processConsentCallbacks();
		logDecision();
		emitChange();

		if (reloadRequired)
		{
			window.location.reload();
		}
	}

	function isSafeScriptSource(src)
	{
		var link;
		var protocol;

		if (!src || /[<>"']/.test(src) || src.indexOf('//') === 0 || /^(?:javascript|data|vbscript|file):/i.test(src))
		{
			return false;
		}

		link = document.createElement('a');
		link.href = src;
		protocol = (link.protocol || '').toLowerCase();

		return protocol === '' || protocol === 'http:' || protocol === 'https:';
	}

	function isSafeAttributeName(name)
	{
		return !!name
			&& /^[a-zA-Z_:][a-zA-Z0-9_:\.-]*$/.test(name)
			&& !/^on/i.test(name)
			&& ['src', 'type', 'async', 'defer'].indexOf(String(name).toLowerCase()) === -1;
	}

	function applyAttributes(element, attributes)
	{
		var name;

		for (name in attributes)
		{
			if (attributes.hasOwnProperty(name) && isSafeAttributeName(name))
			{
				element.setAttribute(name, attributes[name]);
			}
		}
	}

	function executeScript(script)
	{
		var element;

		if (!script || !script.id || executedScripts[script.id] || !hasConsent(script.category))
		{
			return false;
		}

		if (script.src && !isSafeScriptSource(script.src))
		{
			return false;
		}

		element = document.createElement('script');
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
			attributes: options.attributes || {}
		};

		return executeScript(registry[id]);
	}

	function processRegisteredScripts()
	{
		var scriptId;

		for (scriptId in registry)
		{
			if (registry.hasOwnProperty(scriptId))
			{
				executeScript(registry[scriptId]);
			}
		}
	}

	function collectDeferredNodes(scope)
	{
		var nodes = [];
		var matched;
		var index;

		if (!scope)
		{
			return nodes;
		}

		if (scope.nodeType === 1 && scope.matches && scope.matches(payload.deferredSelector))
		{
			nodes.push(scope);
		}

		if (scope.querySelectorAll)
		{
			matched = scope.querySelectorAll(payload.deferredSelector);
			for (index = 0; index < matched.length; index++)
			{
				nodes.push(matched[index]);
			}
		}

		return nodes;
	}

	function processDeferredNodes(scope)
	{
		var nodes = collectDeferredNodes(scope);
		var index;
		var source;
		var liveScript;
		var attributeIndex;
		var attribute;
		var sourceUrl;
		var category;

		for (index = 0; index < nodes.length; index++)
		{
			source = nodes[index];
			category = source.getAttribute('data-consent-category');

			if (source.getAttribute('data-consent-processed') === '1' || !hasConsent(category))
			{
				continue;
			}

			sourceUrl = source.getAttribute('src');
			if (sourceUrl && !isSafeScriptSource(sourceUrl))
			{
				continue;
			}

			liveScript = document.createElement('script');
			liveScript.type = 'text/javascript';

			for (attributeIndex = 0; attributeIndex < source.attributes.length; attributeIndex++)
			{
				attribute = source.attributes[attributeIndex];
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

	function observeDeferredNodes()
	{
		var observer;

		if (typeof MutationObserver === 'undefined' || !document.documentElement)
		{
			return;
		}

		observer = new MutationObserver(function (mutations) {
			var mutationIndex;
			var nodeIndex;

			for (mutationIndex = 0; mutationIndex < mutations.length; mutationIndex++)
			{
				for (nodeIndex = 0; nodeIndex < mutations[mutationIndex].addedNodes.length; nodeIndex++)
				{
					processDeferredNodes(mutations[mutationIndex].addedNodes[nodeIndex]);
				}
			}
		});

		observer.observe(document.documentElement, {
			childList: true,
			subtree: true
		});
	}

	function escapeHtml(value)
	{
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function ensureRoot()
	{
		if (root && root.parentNode)
		{
			return root;
		}

		root = document.getElementById(payload.rootId);
		if (!root && document.body)
		{
			root = document.createElement('div');
			root.id = payload.rootId;
			root.className = 'consent-manager-root';
			document.body.appendChild(root);
		}

		return root;
	}

	function groupServices(categoryId)
	{
		var services = [];
		var index;

		for (index = 0; index < payload.services.length; index++)
		{
			if (payload.services[index].category === categoryId)
			{
				services.push(payload.services[index]);
			}
		}

		return services;
	}

	function renderUi()
	{
		var target = ensureRoot();
		var modalHtml = '';
		var categoryIndex;
		var category;
		var services;
		var serviceIndex;
		var serviceHtml;

		if (!target)
		{
			return;
		}

		for (categoryIndex = 0; categoryIndex < payload.categories.length; categoryIndex++)
		{
			category = payload.categories[categoryIndex];
			if (!category.enabled)
			{
				continue;
			}

			services = groupServices(category.id);
			serviceHtml = '';

			if (services.length)
			{
				serviceHtml += '<div class="consent-manager-category-services"><ul>';
				for (serviceIndex = 0; serviceIndex < services.length; serviceIndex++)
				{
					serviceHtml += '<li><strong>' + escapeHtml(services[serviceIndex].label) + '</strong>';
					if (services[serviceIndex].description)
					{
						serviceHtml += ': ' + escapeHtml(services[serviceIndex].description);
					}
					serviceHtml += '</li>';
				}
				serviceHtml += '</ul></div>';
			}

			modalHtml += ''
				+ '<section class="consent-manager-category">'
				+ '<div class="consent-manager-category-header">'
				+ '<div>'
				+ '<h3 class="consent-manager-category-title">' + escapeHtml(category.label) + '</h3>'
				+ '<p class="consent-manager-category-description">' + escapeHtml(category.description) + '</p>'
				+ serviceHtml
				+ '</div>'
				+ '<label class="consent-manager-toggle">'
				+ '<input type="checkbox" data-consent-toggle="' + escapeHtml(category.id) + '"' + (category.required ? ' checked="checked" disabled="disabled"' : '') + '>'
				+ '<span>' + escapeHtml(category.required ? payload.strings.alwaysActive : payload.strings.allowed) + '</span>'
				+ '</label>'
				+ '</div>'
				+ '</section>';
		}

		target.innerHTML = ''
			+ '<div class="consent-manager-banner" id="consent-manager-banner" role="region" aria-labelledby="consent-manager-banner-title" aria-describedby="consent-manager-banner-copy">'
			+ '<h2 class="consent-manager-heading" id="consent-manager-banner-title">' + escapeHtml(payload.banner.title) + '</h2>'
			+ '<p class="consent-manager-copy" id="consent-manager-banner-copy">' + escapeHtml(payload.banner.text) + '</p>'
			+ '<div class="consent-manager-actions">'
			+ '<button type="button" class="consent-manager-button" data-consent-action="accept-all">' + escapeHtml(payload.strings.acceptAll) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="reject-all">' + escapeHtml(payload.strings.rejectAll) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="open-settings">' + escapeHtml(payload.strings.customize) + '</button>'
			+ '</div>'
			+ '</div>'
			+ '<div class="consent-manager-modal" id="consent-manager-modal" hidden="hidden" role="dialog" aria-modal="true" aria-labelledby="consent-manager-modal-title" aria-describedby="consent-manager-modal-copy">'
			+ '<div class="consent-manager-modal-panel" tabindex="-1">'
			+ '<div class="consent-manager-actions" style="justify-content: space-between; margin-top: 0;">'
			+ '<h2 class="consent-manager-heading" id="consent-manager-modal-title" style="margin: 0;">' + escapeHtml(payload.strings.settingsTitle) + '</h2>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="close-settings">' + escapeHtml(payload.strings.close) + '</button>'
			+ '</div>'
			+ '<p class="consent-manager-copy" id="consent-manager-modal-copy">' + escapeHtml(payload.banner.text) + '</p>'
			+ modalHtml
			+ '<div class="consent-manager-actions">'
			+ '<button type="button" class="consent-manager-button consent-manager-button-primary" data-consent-action="save-settings">' + escapeHtml(payload.strings.savePreferences) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="accept-all">' + escapeHtml(payload.strings.acceptAll) + '</button>'
			+ '<button type="button" class="consent-manager-button" data-consent-action="reject-all">' + escapeHtml(payload.strings.rejectAll) + '</button>'
			+ '</div>'
			+ '</div>'
			+ '</div>';

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
		var banner;
		var link;
		var linkItem;

		if (!isRendered)
		{
			return;
		}

		banner = document.getElementById('consent-manager-banner');
		link = document.getElementById('consent-manager-link');
		linkItem = document.getElementById('consent-manager-link-item');

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
		var selected = [];
		var checkboxes;
		var index;

		if (!root)
		{
			return selected;
		}

		checkboxes = root.querySelectorAll('[data-consent-toggle]');
		for (index = 0; index < checkboxes.length; index++)
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
		var modal = getModal();
		return modal ? modal.querySelector('.consent-manager-modal-panel') : null;
	}

	function getFocusableNodes(container)
	{
		var nodes;
		var focusable = [];
		var index;

		if (!container)
		{
			return focusable;
		}

		nodes = container.querySelectorAll('a[href], button:not([disabled]), textarea, input:not([disabled]), select, [tabindex]:not([tabindex="-1"])');
		for (index = 0; index < nodes.length; index++)
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
		var modal = getModal();
		var focusable;
		var first;
		var last;

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

		focusable = getFocusableNodes(getModalPanel());
		if (!focusable.length)
		{
			return;
		}

		first = focusable[0];
		last = focusable[focusable.length - 1];

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
		var modal;
		var panel;
		var checkboxes;
		var index;

		if (!isRendered)
		{
			pendingOpenSettings = true;
			return;
		}

		modal = getModal();
		panel = getModalPanel();
		checkboxes = root.querySelectorAll('[data-consent-toggle]');

		for (index = 0; index < checkboxes.length; index++)
		{
			checkboxes[index].checked = hasConsent(checkboxes[index].getAttribute('data-consent-toggle'));
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
		var modal = getModal();

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
		var footerLink = document.getElementById('consent-manager-link');

		root.addEventListener('click', function (event) {
			var action = event.target.getAttribute('data-consent-action');

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
			footerLink.addEventListener('click', function (event) {
				event.preventDefault();
				openSettings();
			});
		}

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

	function onConsent(category, callback)
	{
		var entry;

		if (typeof callback !== 'function' || typeof category === 'undefined' || category === null)
		{
			return false;
		}

		entry = {
			category: String(category),
			callback: callback,
			fired: false
		};

		consentCallbacks.push(entry);
		runConsentCallback(entry);

		return true;
	}

	function ready(callback)
	{
		if (typeof callback !== 'function')
		{
			return;
		}

		callback(api);
	}

	var api = {
		registerScript: registerScript,
		hasConsent: hasConsent,
		onConsent: onConsent,
		onChange: onChange,
		openSettings: openSettings,
		getState: getStateSnapshot,
		ready: ready
	};

	window.consentManager = api;

	for (i = 0; i < payload.scripts.length; i++)
	{
		registerScript(payload.scripts[i].id, payload.scripts[i]);
	}

	for (i = 0; i < queued.length; i++)
	{
		if (queued[i][0] === 'registerScript')
		{
			registerScript(queued[i][1], queued[i][2]);
		}
		else if (queued[i][0] === 'onChange')
		{
			onChange(queued[i][1]);
		}
		else if (queued[i][0] === 'onConsent')
		{
			onConsent(queued[i][1], queued[i][2]);
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
	processConsentCallbacks();
	observeDeferredNodes();

	if (document.readyState === 'loading')
	{
		document.addEventListener('DOMContentLoaded', renderUi);
	}
	else
	{
		renderUi();
	}
})(window, document);
