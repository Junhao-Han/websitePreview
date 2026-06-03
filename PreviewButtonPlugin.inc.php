<?php
/**
 * @file PreviewButtonPlugin.inc.php
 *
 * Copyright (c) 2017-2021 Simon Fraser University
 * Copyright (c) 2017-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PreviewButtonPlugin
 * @brief Plugin class for the Preview Button plugin.
 */
import('lib.pkp.classes.plugins.GenericPlugin');
class PreviewButtonPlugin extends GenericPlugin {

	/**
	 * @copydoc GenericPlugin::register()
	 */
	public function register($category, $path, $mainContextId = NULL) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success && $this->getEnabled($mainContextId)) {
			HookRegistry::register('LoadHandler', [$this, 'setPageHandler']);
			HookRegistry::register('TemplateManager::setupBackendPage', [$this, 'addWorkflowPreviewButton']);
			HookRegistry::register('ArticleHandler::view', [$this, 'permitPluginPreviewAccess']);
		}
		return $success;
	}

	/**
	 * Provide a name for this plugin
	 *
	 * The name will appear in the Plugin Gallery where editors can
	 * install, enable and disable plugins.
	 *
	 * @return string
	 */
	public function getDisplayName() {
		return __('plugins.generic.previewButton.displayName');
	}

	/**
	 * Provide a description for this plugin
	 *
	 * The description will appear in the Plugin Gallery where editors can
	 * install, enable and disable plugins.
	 *
	 * @return string
	 */
	public function getDescription() {
		return __('plugins.generic.previewButton.description');
	}

	/**
	 * Route previewButton page requests to this plugin.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	public function setPageHandler($hookName, $params) {
		$page =& $params[0];
		$sourceFile =& $params[2];

		if ($page !== 'previewButton') {
			return false;
		}

		$sourceFile = $this->getPluginPath() . '/pages/previewButton/index.php';
		return false;
	}

	/**
	 * Make the workflow header Preview button available before editing stage.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	public function addWorkflowPreviewButton($hookName, $params) {
		$request = Application::get()->getRequest();
		if (!in_array($request->getRequestedPage(), ['workflow', 'authorDashboard'])) {
			return false;
		}

		$context = $request->getContext();
		if (!$context) {
			return false;
		}

		$previewUrlPrefix = $request->getDispatcher()->url(
			$request,
			ROUTE_PAGE,
			$context->getPath(),
			'previewButton',
			'view',
			[]
		);
		$previewUrlPrefix = rtrim($previewUrlPrefix, '/') . '/';

		$script = '(function() {
	var previewUrlPrefix = ' . json_encode($previewUrlPrefix) . ';
	var previewLabel = ' . json_encode(__('common.preview')) . ';
	var viewLabel = ' . json_encode(__('common.view')) . ';

	function addPreviewButton() {
		if (!document.body || (
			!document.body.classList.contains("pkp_page_workflow") &&
			!document.body.classList.contains("pkp_page_authorDashboard")
		)) {
			return true;
		}

		var actions = document.querySelector(".pkpWorkflow__header .pkpHeader__actions");
		var submissionId = document.querySelector(".pkpWorkflow__identificationId");
		if (!actions || !submissionId) {
			return false;
		}

		var existingAction = Array.prototype.some.call(actions.querySelectorAll("a, button"), function(action) {
			var text = (action.textContent || "").trim();
			return text === previewLabel || text === viewLabel;
		});
		if (existingAction || actions.querySelector("[data-preview-button-plugin]")) {
			return true;
		}

		var id = (submissionId.textContent || "").trim();
		if (!/^\\d+$/.test(id)) {
			return true;
		}

		var previewButton = document.createElement("a");
		previewButton.className = "pkpButton";
		previewButton.href = previewUrlPrefix + id;
		previewButton.textContent = previewLabel;
		previewButton.setAttribute("data-preview-button-plugin", "true");
		actions.insertBefore(previewButton, actions.firstChild);
		return true;
	}

	function initPreviewButton() {
		if (addPreviewButton()) {
			return;
		}

		var observer = new MutationObserver(function() {
			if (addPreviewButton()) {
				observer.disconnect();
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
		window.setTimeout(function() {
			observer.disconnect();
		}, 10000);
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initPreviewButton);
	} else {
		initPreviewButton();
	}
})();';

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->addJavaScript(
			'previewButtonWorkflow',
			$script,
			[
				'contexts' => ['backend'],
				'inline' => true,
				'priority' => STYLE_SEQUENCE_LATE,
			]
		);

		return false;
	}

	/**
	 * Let the plugin preview endpoint show galley links as accessible.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	public function permitPluginPreviewAccess($hookName, $params) {
		$request =& $params[0];

		if ($request->getRequestedPage() !== 'previewButton') {
			return false;
		}

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('hasAccess', true);
		$templateMgr->addJavaScript(
			'previewButtonGalleys',
			'(function() {
	function rewriteGalleyLinks() {
		var links = document.querySelectorAll("a.obj_galley_link, a.obj_galley_link_supplementary");
		Array.prototype.forEach.call(links, function(link) {
			link.href = link.href.replace("/article/view/", "/previewButton/view/");
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", rewriteGalleyLinks);
	} else {
		rewriteGalleyLinks();
	}
})();',
			[
				'contexts' => ['frontend'],
				'inline' => true,
				'priority' => STYLE_SEQUENCE_LATE,
			]
		);

		return false;
	}
}
