(function() {
	var config = window.websitePreviewWorkflowConfig || {};
	var previewUrlPrefix = config.previewUrlPrefix;
	var buttonLabel = config.buttonLabel || 'Website';
	var warned = {};

	var SELECTORS = {
		targetBodyClasses: [
			'pkp_page_workflow',
			'pkp_page_authorDashboard'
		],
		actions: '.pkpWorkflow__header .pkpHeader__actions',
		submissionId: '.pkpWorkflow__identificationId',
		previewButton: '[data-website-preview-plugin]',
		stageTabLink: '#stageTabs a',
		activeStage: [
			'#stageTabs > ul > li.ui-tabs-active',
			'#stageTabs > ul > li.ui-state-active',
			'#stageTabs > ul > li[aria-selected=true]',
			'#stageTabs > ul > li.ui-tabs-active a',
			'#stageTabs > ul > li.ui-state-active a',
			'#stageTabs > ul > li[aria-selected=true] a'
		]
	};

	if (!previewUrlPrefix) {
		return;
	}

	function warnOnce(key, message) {
		if (warned[key] || !window.console || !window.console.warn) {
			return;
		}
		warned[key] = true;
		window.console.warn('[Website Preview] ' + message);
	}

	function isTargetPage() {
		if (!document.body) {
			return false;
		}
		return SELECTORS.targetBodyClasses.some(function(className) {
			return document.body.classList.contains(className);
		});
	}

	function insertWebsiteButton(actions, submissionId, stageId) {
		if (actions.querySelector(SELECTORS.previewButton)) {
			return;
		}

		var button = document.createElement('a');
		button.className = 'pkpButton';
		button.href = previewUrlPrefix
			+ encodeURIComponent(submissionId)
			+ '/'
			+ encodeURIComponent(stageId);
		button.target = '_blank';
		button.rel = 'noopener noreferrer';
		button.textContent = buttonLabel;
		button.setAttribute('data-website-preview-plugin', 'true');
		actions.insertBefore(button, actions.firstChild);
	}

	function getCurrentStageId() {
		for (var i = 0; i < SELECTORS.activeStage.length; i++) {
			var activeStage = document.querySelector(SELECTORS.activeStage[i]);
			var stageId = getStageIdFromElement(activeStage);
			if (stageId) {
				return stageId;
			}
		}

		var workflowMatch = window.location.pathname.match(/\/workflow\/index\/\d+\/(\d+)(?:\/|$)/);
		return workflowMatch ? workflowMatch[1] : null;
	}

	function getStageIdFromElement(element) {
		if (!element) {
			return null;
		}

		var stageClass = (element.className || '').toString().match(/stageId(\d+)/);
		if (stageClass) {
			return stageClass[1];
		}

		var link = element.matches && element.matches('a')
			? element
			: element.querySelector && element.querySelector('a');
		if (!link) {
			return null;
		}

		var href = link.getAttribute('href') || '';
		var stageQuery = href.match(/[?&]stageId=(\d+)/);
		if (stageQuery) {
			return stageQuery[1];
		}

		var linkClass = (link.className || '').toString().match(/stageId(\d+)/);
		return linkClass ? linkClass[1] : null;
	}

	function removeWebsiteButton(actions) {
		var existingButton = actions.querySelector(SELECTORS.previewButton);
		if (existingButton) {
			existingButton.parentNode.removeChild(existingButton);
		}
		actions.removeAttribute('data-website-preview-status');
	}

	function addWebsiteButton() {
		if (!isTargetPage()) {
			return true;
		}

		var actions = document.querySelector(SELECTORS.actions);
		var submissionIdElement = document.querySelector(SELECTORS.submissionId);
		if (!actions || !submissionIdElement) {
			warnOnce(
				'missingMount',
				'Could not find the workflow header actions or submission id. The Website button was not inserted.'
			);
			return false;
		}

		var stageId = getCurrentStageId();
		if (!stageId) {
			removeWebsiteButton(actions);
			warnOnce(
				'missingStage',
				'Could not determine the active workflow stage. The Website button was not inserted.'
			);
			return false;
		}

		var submissionId = (submissionIdElement.textContent || '').trim();
		if (!/^\d+$/.test(submissionId)) {
			warnOnce(
				'invalidSubmissionId',
				'Could not read a numeric submission id from the workflow header.'
			);
			return true;
		}

		var statusKey = submissionId + ':' + stageId;
		if (actions.getAttribute('data-website-preview-status') === statusKey) {
			return true;
		}

		removeWebsiteButton(actions);
		actions.setAttribute('data-website-preview-status', statusKey);
		insertWebsiteButton(actions, submissionId, stageId);
		return true;
	}

	function initWebsiteButton() {
		var updateTimer;
		function scheduleWebsiteButtonUpdate() {
			window.clearTimeout(updateTimer);
			updateTimer = window.setTimeout(addWebsiteButton, 50);
		}

		addWebsiteButton();

		var observer = new MutationObserver(function() {
			scheduleWebsiteButtonUpdate();
		});
		observer.observe(document.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: ['class', 'aria-selected']
		});
		document.addEventListener('click', function(event) {
			if (event.target.closest && event.target.closest(SELECTORS.stageTabLink)) {
				scheduleWebsiteButtonUpdate();
			}
		}, true);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initWebsiteButton);
	} else {
		initWebsiteButton();
	}
})();
