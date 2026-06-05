<?php
/**
 * @file WebsitePreviewPlugin.inc.php
 *
 * @class WebsitePreviewPlugin
 * @brief Preview uploaded static website ZIP projects from submission workflow pages.
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.submission.Genre');

class WebsitePreviewPlugin extends GenericPlugin {
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
		return __('plugins.generic.websitePreview.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.websitePreview.description');
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
	 * Route websitePreview page requests to this plugin.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	public function setPageHandler($hookName, $params) {
		$page =& $params[0];
		$sourceFile =& $params[2];

		if ($page !== 'websitePreview') {
			return false;
		}

		$sourceFile = $this->getPluginPath() . '/pages/websitePreview/index.php';
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

		return false;
	}

	/**
	 * Add a Website button to submission workflow pages.
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
			'websitePreview',
			'view',
			[]
		);
		$previewUrlPrefix = rtrim($previewUrlPrefix, '/') . '/';

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->addJavaScript(
			'websitePreviewWorkflowConfig',
			'window.websitePreviewWorkflowConfig = ' . json_encode([
				'previewUrlPrefix' => $previewUrlPrefix,
				'buttonLabel' => __('plugins.generic.websitePreview.viewProject'),
			]) . ';',
			[
				'contexts' => ['backend'],
				'inline' => true,
				'priority' => STYLE_SEQUENCE_LATE,
			]
		);
		$templateMgr->addJavaScript(
			'websitePreviewWorkflow',
			$this->getJavaScriptUrl($request, 'websitePreviewWorkflow.js'),
			[
				'contexts' => ['backend'],
				'priority' => STYLE_SEQUENCE_LATE,
			]
		);
	}

	/**
	 * Get a public URL for one of this plugin's JavaScript assets.
	 *
	 * @param Request $request
	 * @param string $fileName
	 * @return string
	 */
	protected function getJavaScriptUrl($request, $fileName) {
		return rtrim($request->getBaseUrl(), '/') . '/' . $this->getPluginPath() . '/js/' . $fileName;
	}
}
