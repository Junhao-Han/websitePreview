<?php
/**
 * @file WebProjectPreviewPlugin.inc.php
 *
 * @class WebProjectPreviewPlugin
 * @brief Preview uploaded static website ZIP projects from submission workflow pages.
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.submission.Genre');

class WebProjectPreviewPlugin extends GenericPlugin {
	const WEB_PROJECT_GENRE_KEY = 'WEBPROJECT';
	const WEB_PROJECT_GENRE_NAME = 'Web Project';
	const WEB_PROJECT_CHECKLIST = 'If the submission is a web project, upload it as a ZIP file and include an index.html file as the website entry point.';

	/**
	 * @copydoc GenericPlugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success && $this->getEnabled($mainContextId)) {
			$this->ensureJournalSetup($mainContextId);
			HookRegistry::register('LoadHandler', [$this, 'setPageHandler']);
			HookRegistry::register('TemplateManager::setupBackendPage', [$this, 'addBackendScripts']);
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.webProjectPreview.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.webProjectPreview.description');
	}

	/**
	 * Ensure journals using this plugin have a Web Project file type and checklist item.
	 *
	 * @param int|null $mainContextId
	 */
	protected function ensureJournalSetup($mainContextId = null) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context && $mainContextId) {
			$context = Services::get('context')->get($mainContextId);
		}
		if (!$context) {
			return;
		}

		$this->ensureWebProjectGenre($context);
		$this->ensureWebProjectChecklist($context);
	}

	/**
	 * Add a Web Project file kind to the journal if it does not exist.
	 *
	 * @param Context $context
	 */
	protected function ensureWebProjectGenre($context) {
		$genreDao = DAORegistry::getDAO('GenreDAO'); /* @var $genreDao GenreDAO */
		$genre = $genreDao->getByKey(self::WEB_PROJECT_GENRE_KEY, $context->getId());
		if ($genre) {
			if (!$genre->getEnabled()) {
				$genre->setEnabled(true);
				$genreDao->updateObject($genre);
			}
			return $genre;
		}

		$maxSequence = 0;
		$genres = $genreDao->getByContextId($context->getId());
		while ($existingGenre = $genres->next()) {
			$maxSequence = max($maxSequence, (float) $existingGenre->getSequence());
		}

		$genre = new Genre();
		$genre->setKey(self::WEB_PROJECT_GENRE_KEY);
		$genre->setContextId($context->getId());
		$genre->setSequence($maxSequence + 1);
		$genre->setCategory(GENRE_CATEGORY_SUPPLEMENTARY);
		$genre->setDependent(false);
		$genre->setSupplementary(true);
		$genre->setEnabled(true);
		$genre->setName(self::WEB_PROJECT_GENRE_NAME, $context->getPrimaryLocale());
		$genreDao->insertObject($genre);
		return $genre;
	}

	/**
	 * Add a web project requirement to the submission checklist if missing.
	 *
	 * @param Context $context
	 */
	protected function ensureWebProjectChecklist($context) {
		$primaryLocale = $context->getPrimaryLocale();
		$checklist = (array) $context->getData('submissionChecklist', $primaryLocale);
		foreach ($checklist as $item) {
			if (isset($item['content']) && $item['content'] === self::WEB_PROJECT_CHECKLIST) {
				return;
			}
		}

		$maxOrder = 0;
		foreach ($checklist as $item) {
			if (isset($item['order'])) {
				$maxOrder = max($maxOrder, (int) $item['order']);
			}
		}

		$checklist[] = [
			'order' => $maxOrder + 1,
			'content' => self::WEB_PROJECT_CHECKLIST,
		];

		$journalSettingsDao = DAORegistry::getDAO('JournalSettingsDAO'); /* @var $journalSettingsDao JournalSettingsDAO */
		$journalSettingsDao->updateSetting(
			$context->getId(),
			'submissionChecklist',
			[$primaryLocale => $checklist],
			'object',
			true
		);
	}

	/**
	 * Route webProjectPreview page requests to this plugin.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	public function setPageHandler($hookName, $params) {
		$page =& $params[0];
		$sourceFile =& $params[2];

		if ($page !== 'webProjectPreview') {
			return false;
		}

		$sourceFile = $this->getPluginPath() . '/pages/webProjectPreview/index.php';
		return false;
	}

	/**
	 * Add backend JavaScript for workflow and submission pages.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	public function addBackendScripts($hookName, $params) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if (!$context) {
			return false;
		}

		$this->addWorkflowButtonScript($request, $context);
		$this->addSubmissionWizardScript($request, $context);

		return false;
	}

	/**
	 * Add a Web Project button to submission workflow pages.
	 *
	 * @param Request $request
	 * @param Context $context
	 */
	protected function addWorkflowButtonScript($request, $context) {
		if (!in_array($request->getRequestedPage(), ['workflow', 'authorDashboard'])) {
			return;
		}

		$previewUrlPrefix = $request->getDispatcher()->url(
			$request,
			ROUTE_PAGE,
			$context->getPath(),
			'webProjectPreview',
			'view',
			[]
		);
		$previewUrlPrefix = rtrim($previewUrlPrefix, '/') . '/';
		$statusUrlPrefix = $request->getDispatcher()->url(
			$request,
			ROUTE_PAGE,
			$context->getPath(),
			'webProjectPreview',
			'status',
			[]
		);
		$statusUrlPrefix = rtrim($statusUrlPrefix, '/') . '/';

		$script = '(function() {
	var previewUrlPrefix = ' . json_encode($previewUrlPrefix) . ';
	var statusUrlPrefix = ' . json_encode($statusUrlPrefix) . ';
	var buttonLabel = "Web Project";

	function insertWebProjectButton(actions, id) {
		if (actions.querySelector("[data-web-project-preview-plugin]")) {
			return;
		}

		var button = document.createElement("a");
		button.className = "pkpButton";
		button.href = previewUrlPrefix + id;
		button.target = "_blank";
		button.rel = "noopener noreferrer";
		button.textContent = buttonLabel;
		button.setAttribute("data-web-project-preview-plugin", "true");
		actions.insertBefore(button, actions.firstChild);
	}

	function checkWebProjectStatus(id, callback) {
		var request = new XMLHttpRequest();
		request.open("GET", statusUrlPrefix + id, true);
		request.onreadystatechange = function() {
			if (request.readyState !== 4) {
				return;
			}

			if (request.status < 200 || request.status >= 300) {
				callback(false);
				return;
			}

			try {
				callback(!!JSON.parse(request.responseText).hasProject);
			} catch (error) {
				callback(false);
			}
		};
		request.send();
	}

	function addWebProjectButton() {
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

		if (actions.querySelector("[data-web-project-preview-plugin]")) {
			return true;
		}

		var id = (submissionId.textContent || "").trim();
		if (!/^\\d+$/.test(id)) {
			return true;
		}

		if (actions.getAttribute("data-web-project-preview-status") === id) {
			return true;
		}

		actions.setAttribute("data-web-project-preview-status", id);
		checkWebProjectStatus(id, function(hasProject) {
			if (hasProject) {
				insertWebProjectButton(actions, id);
			}
		});
		return true;
	}

	function initWebProjectButton() {
		if (addWebProjectButton()) {
			return;
		}

		var observer = new MutationObserver(function() {
			if (addWebProjectButton()) {
				observer.disconnect();
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
		window.setTimeout(function() {
			observer.disconnect();
		}, 10000);
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initWebProjectButton);
	} else {
		initWebProjectButton();
	}
})();';

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->addJavaScript(
			'webProjectPreviewWorkflow',
			$script,
			[
				'contexts' => ['backend'],
				'inline' => true,
				'priority' => STYLE_SEQUENCE_LATE,
			]
		);
	}

	/**
	 * Add the Web Project file kind to the submission wizard.
	 *
	 * @param Request $request
	 * @param Context $context
	 */
	protected function addSubmissionWizardScript($request, $context) {
		if ($request->getRequestedPage() !== 'submission') {
			return;
		}

		$genre = DAORegistry::getDAO('GenreDAO')->getByKey(self::WEB_PROJECT_GENRE_KEY, $context->getId());
		if (!$genre) {
			return;
		}

		$script = '(function() {
	var genreId = ' . json_encode((int) $genre->getId()) . ';
	var genreName = ' . json_encode(self::WEB_PROJECT_GENRE_NAME) . ';

	function isSubmissionWizard() {
		return window.location.href.indexOf("/submission/wizard/") !== -1;
	}

	function addWebProjectGenreOption() {
		if (!isSubmissionWizard()) {
			return;
		}

		var modal = Array.prototype.filter.call(document.querySelectorAll(".modal, [role=dialog]"), function(element) {
			return (element.textContent || "").indexOf("What kind of file is this?") !== -1;
		})[0];
		if (!modal || (modal.textContent || "").indexOf(genreName) !== -1) {
			return;
		}

		var options = modal.querySelector("fieldset") || modal.querySelector(".modal__content") || modal;
		var otherInput = Array.prototype.filter.call(options.querySelectorAll("input[type=radio]"), function(input) {
			var label = input.closest("label");
			return label && (label.textContent || "").trim() === "Other";
		})[0];
		var referenceLabel = otherInput ? otherInput.closest("label") : null;
		if (!referenceLabel) {
			return;
		}

		var label = referenceLabel.cloneNode(true);
		var input = label.querySelector("input[type=radio]");
		if (!input) {
			return;
		}
		input.value = String(genreId);
		input.checked = false;
		input.removeAttribute("checked");
		input.id = "genreId-web-project";

		Array.prototype.forEach.call(label.childNodes, function(node) {
			if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
				node.textContent = " " + genreName;
			}
		});
		if ((label.textContent || "").indexOf(genreName) === -1) {
			label.appendChild(document.createTextNode(" " + genreName));
		}

		referenceLabel.parentNode.insertBefore(label, referenceLabel);
	}

	function initSubmissionWizardSupport() {
		addWebProjectGenreOption();
		var observer = new MutationObserver(function() {
			addWebProjectGenreOption();
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", initSubmissionWizardSupport);
	} else {
		initSubmissionWizardSupport();
	}
})();';

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->addJavaScript(
			'webProjectPreviewSubmissionWizard',
			$script,
			[
				'contexts' => ['backend'],
				'inline' => true,
				'priority' => STYLE_SEQUENCE_LATE,
			]
		);
	}

	/**
	 * @deprecated Use addBackendScripts().
	 */
	public function addWorkflowButton($hookName, $params) {
		return false;
	}
}
