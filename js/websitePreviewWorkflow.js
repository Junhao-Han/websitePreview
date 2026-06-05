(function() {
	var config = window.websitePreviewWorkflowConfig || {};
	var previewUrlPrefix = config.previewUrlPrefix;
	var buttonLabel = config.buttonLabel || 'Website';

	if (!previewUrlPrefix) {
		return;
	}

	function insertWebsiteButton(actions, id, stageId) {
		if (actions.querySelector('[data-website-preview-plugin]')) {
			return;
		}

		var button = document.createElement('a');
		button.className = 'pkpButton';
		button.href = previewUrlPrefix + id + '/' + stageId;
		button.target = '_blank';
		button.rel = 'noopener noreferrer';
		button.textContent = buttonLabel;
		button.setAttribute('data-website-preview-plugin', 'true');
		actions.insertBefore(button, actions.firstChild);
	}

	function getCurrentStageId() {
		var activeSelectors = [
			'#stageTabs > ul > li.ui-tabs-active',
			'#stageTabs > ul > li.ui-state-active',
			'#stageTabs > ul > li[aria-selected=true]',
			'#stageTabs > ul > li.ui-tabs-active a',
			'#stageTabs > ul > li.ui-state-active a',
			'#stageTabs > ul > li[aria-selected=true] a'
		];

		for (var i = 0; i < activeSelectors.length; i++) {
			var activeStage = document.querySelector(activeSelectors[i]);
			var stageId = getStageIdFromElement(activeStage);
			if (stageId) {
				return stageId;
			}
		}

		var workflowMatch = window.location.pathname.match(/\/workflow\/index\/\d+\/(\d+)/);
		if (workflowMatch) {
			return workflowMatch[1];
		}

		return null;
	}

	function getStageIdFromElement(element) {
		if (!element) {
			return null;
		}

		var stageClass = (element.className || '').toString().match(/stageId(\d+)/);
		if (stageClass) {
			return stageClass[1];
		}

		var link = element.matches && element.matches('a') ? element : element.querySelector && element.querySelector('a');
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

	function addWebsiteButton() {
		if (!document.body || (
			!document.body.classList.contains('pkp_page_workflow') &&
			!document.body.classList.contains('pkp_page_authorDashboard')
		)) {
			return true;
		}

		var actions = document.querySelector('.pkpWorkflow__header .pkpHeader__actions');
		var submissionId = document.querySelector('.pkpWorkflow__identificationId');
		if (!actions || !submissionId) {
			return false;
		}

		var stageId = getCurrentStageId();
		if (!stageId) {
			var existingButton = actions.querySelector('[data-website-preview-plugin]');
			if (existingButton) {
				existingButton.parentNode.removeChild(existingButton);
			}
			actions.removeAttribute('data-website-preview-status');
			return false;
		}

		var id = (submissionId.textContent || '').trim();
		if (!/^\d+$/.test(id)) {
			return true;
		}

		var statusKey = id + ':' + stageId;
		if (actions.getAttribute('data-website-preview-status') === statusKey) {
			return true;
		}

		var button = actions.querySelector('[data-website-preview-plugin]');
		if (button) {
			button.parentNode.removeChild(button);
		}

		actions.setAttribute('data-website-preview-status', statusKey);
		insertWebsiteButton(actions, id, stageId);
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
		observer.observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'aria-selected']});
		document.addEventListener('click', function(event) {
			if (event.target.closest && event.target.closest('#stageTabs a')) {
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
