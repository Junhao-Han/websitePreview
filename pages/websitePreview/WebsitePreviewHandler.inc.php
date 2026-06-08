<?php

/**
 * @file pages/websitePreview/WebsitePreviewHandler.inc.php
 *
 * @class WebsitePreviewHandler
 * @brief Preview a static website ZIP uploaded as a submission file.
 */

import('classes.handler.Handler');
import('lib.pkp.classes.file.FileManager');
import('lib.pkp.classes.security.authorization.SubmissionFileAccessPolicy');
import('lib.pkp.classes.submission.SubmissionFile');

require_once(dirname(__FILE__) . '/../../classes/WebsitePreviewArchiveService.inc.php');

class WebsitePreviewHandler extends Handler {
	const WEB_PROJECT_GENRE_KEY = 'WEBPROJECT';
	const MARKER_FILE = '.website-preview-ready.json';
	const PREVIEW_PAGE_CSP = "default-src 'none'; frame-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors 'self'";
	// Many static website ZIPs rely on CDN-hosted scripts, images, fonts, or media.
	// Same-origin is allowed for compatibility; forms, objects, and XHR/WebSocket calls stay blocked by CSP.
	const ASSET_CSP = "default-src 'self' data: blob: http: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: http: https:; style-src 'self' 'unsafe-inline' http: https:; img-src 'self' data: blob: http: https:; font-src 'self' data: http: https:; media-src 'self' data: blob: http: https:; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";
	/** @var WebsitePreviewArchiveService|null */
	protected $archiveService = null;

	/**
	 * Show the web project in a sandboxed iframe.
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function view($args, $request) {
		$submission = $this->getAuthorizedSubmission($request, $args);
		$stageId = $this->getAuthorizedStageId($request, $submission, $args);
		$zipFile = $this->getZipSubmissionFile($request, $submission, $stageId);

		if (!$zipFile) {
			$this->showPreviewError(__('plugins.generic.websitePreview.noWebProjectZip'));
			return;
		}

		$extractDir = $this->prepareExtractedProject($request, $submission, $zipFile);
		if (!$extractDir) {
			return;
		}

		$indexError = null;
		$indexCandidates = [];
		$indexPath = $this->findIndexPath($extractDir, $indexError, $indexCandidates);
		if (!$indexPath) {
			if ($indexError === 'multiple') {
				$this->showPreviewError(
					__('plugins.generic.websitePreview.multipleIndexHtml'),
					200,
					$indexCandidates
				);
				return;
			}

			$this->showPreviewError(__('plugins.generic.websitePreview.noIndexHtml'));
			return;
		}

		$assetUrl = $request->getDispatcher()->url(
			$request,
			ROUTE_PAGE,
			$request->getContext()->getPath(),
			'websitePreview',
			'asset',
			array_merge([
				$submission->getId(),
				$stageId,
				$zipFile->getId(),
			], explode('/', $indexPath))
		);

		$this->sendHtmlHeaders(200, self::PREVIEW_PAGE_CSP);
		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . htmlspecialchars($submission->getLocalizedTitle(), ENT_QUOTES, 'UTF-8') . '</title>';
		echo '<style>html,body{height:100%;margin:0;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f4f4;}iframe{width:100%;height:100%;border:0;background:#fff;}</style>';
		echo '</head><body>';
		echo '<iframe title="' . htmlspecialchars(__('plugins.generic.websitePreview.previewFrameTitle'), ENT_QUOTES, 'UTF-8') . '" sandbox="allow-same-origin allow-scripts allow-popups" src="' . htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') . '"></iframe>';
		echo '</body></html>';
	}

	/**
	 * Serve one static asset from the extracted ZIP.
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function asset($args, $request) {
		$submission = $this->getAuthorizedSubmission($request, $args);
		$stageId = $this->getAuthorizedStageId($request, $submission, $args);
		$submissionFileId = !empty($args) ? (int) array_shift($args) : 0;
		$relativePath = implode('/', $args);

		if (!$submissionFileId || !$relativePath || !$this->isAllowedStaticFile($relativePath)) {
			$request->getDispatcher()->handle404();
		}

		$zipFile = Services::get('submissionFile')->get($submissionFileId);
		if (
			!$zipFile ||
			$zipFile->getData('submissionId') != $submission->getId() ||
			!$this->isWebProjectZipFile($zipFile, $request->getContext()) ||
			!$this->isSubmissionFileInWorkflowStage($zipFile, $stageId) ||
			!$this->canPreviewSubmissionFile($request, $submission, $zipFile, $stageId)
		) {
			$request->getDispatcher()->handle404();
		}

		$extractDir = $this->prepareExtractedProject($request, $submission, $zipFile);
		if (!$extractDir) {
			return;
		}

		$assetPath = $this->resolveAssetPath($extractDir, $relativePath);
		if (!$assetPath || !is_file($assetPath)) {
			$request->getDispatcher()->handle404();
		}

		$mimeType = $this->getMimeType($assetPath);
		$this->sendAssetHeaders($mimeType, filesize($assetPath));
		readfile($assetPath);
		exit;
	}

	/**
	 * Get an authorized submission from route args.
	 *
	 * @param Request $request
	 * @param array $args
	 * @return Submission
	 */
	protected function getAuthorizedSubmission($request, &$args) {
		$submissionId = !empty($args) ? (int) array_shift($args) : 0;
		$submission = $submissionId ? Services::get('submission')->get($submissionId) : null;
		$context = $request->getContext();

		if (!$submission || !$context || $submission->getData('contextId') !== $context->getId()) {
			$request->getDispatcher()->handle404();
		}

		if (!$this->canPreviewSubmission($request, $submission)) {
			$request->getDispatcher()->handle404();
		}

		return $submission;
	}

	/**
	 * Get and validate the requested workflow stage id from route args.
	 *
	 * @param Request $request
	 * @param Submission $submission
	 * @param array $args
	 * @return int
	 */
	protected function getAuthorizedStageId($request, $submission, &$args) {
		$stageId = !empty($args) ? (int) array_shift($args) : 0;
		if (!$stageId || !$this->getFileStagesForWorkflowStage($stageId)) {
			$request->getDispatcher()->handle404();
		}

		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user || !$context) {
			$request->getDispatcher()->handle404();
		}

		$accessibleWorkflowStages = Services::get('user')->getAccessibleWorkflowStages(
			$user->getId(),
			$context->getId(),
			$submission,
			$this->getUserRoleIds($user, $context)
		);

		if (array_key_exists($stageId, $accessibleWorkflowStages)) {
			return $stageId;
		}

		foreach ($this->getActiveReviewAssignments($submission, $user, $stageId) as $reviewAssignment) {
			return $stageId;
		}

		$request->getDispatcher()->handle404();
	}

	/**
	 * Check OJS workflow access or assigned reviewer access.
	 *
	 * @param Request $request
	 * @param Submission $submission
	 * @return bool
	 */
	protected function canPreviewSubmission($request, $submission) {
		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user || !$context) {
			return false;
		}

		$accessibleWorkflowStages = Services::get('user')->getAccessibleWorkflowStages(
			$user->getId(),
			$context->getId(),
			$submission,
			$this->getUserRoleIds($user, $context)
		);
		if (!empty($accessibleWorkflowStages)) {
			return true;
		}

		return !empty($this->getActiveReviewAssignments($submission, $user));
	}

	/**
	 * Get active review assignments for a user.
	 *
	 * @param Submission $submission
	 * @param User $user
	 * @param int|null $stageId
	 * @return array
	 */
	protected function getActiveReviewAssignments($submission, $user, $stageId = null) {
		$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao ReviewAssignmentDAO */
		$reviewAssignments = $reviewAssignmentDao->getBySubmissionReviewer(
			$submission->getId(),
			$user->getId(),
			$stageId
		);
		$activeReviewAssignments = [];

		foreach ($reviewAssignments as $reviewAssignment) {
			if (!$reviewAssignment->getDeclined() && !$reviewAssignment->getCancelled()) {
				$activeReviewAssignments[] = $reviewAssignment;
			}
		}

		return $activeReviewAssignments;
	}

	/**
	 * Get current user's role ids.
	 *
	 * @param User $user
	 * @param Context $context
	 * @return array
	 */
	protected function getUserRoleIds($user, $context) {
		$roleDao = DAORegistry::getDAO('RoleDAO'); /* @var $roleDao RoleDAO */
		$userRoles = $roleDao->getByUserIdGroupedByContext($user->getId());
		$userRoleIds = [];

		if (array_key_exists($context->getId(), $userRoles)) {
			foreach ($userRoles[$context->getId()] as $contextRole) {
				$userRoleIds[] = $contextRole->getRoleId();
			}
		}

		if (array_key_exists(CONTEXT_ID_NONE, $userRoles)) {
			foreach ($userRoles[CONTEXT_ID_NONE] as $siteRole) {
				if ($siteRole->getRoleId() == ROLE_ID_SITE_ADMIN) {
					$userRoleIds[] = ROLE_ID_SITE_ADMIN;
					break;
				}
			}
		}

		return array_values(array_unique($userRoleIds));
	}

	/**
	 * Find the most recent authorized Web Project ZIP submission file for a workflow stage.
	 *
	 * @param Request $request
	 * @param Submission $submission
	 * @param int $stageId
	 * @return SubmissionFile|null
	 */
	protected function getZipSubmissionFile($request, $submission, $stageId) {
		$fileStages = $this->getFileStagesForWorkflowStage($stageId);
		$genre = $this->getWebProjectGenre($request->getContext());
		if (!$fileStages || !$genre) {
			return null;
		}

		$zipFile = null;
		$submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
			'fileStages' => $fileStages,
			'genreIds' => [$genre->getId()],
		]);

		foreach ($submissionFiles as $submissionFile) {
			if (
				!$this->isWebProjectZipFile($submissionFile, $request->getContext()) ||
				!$this->canPreviewSubmissionFile($request, $submission, $submissionFile, $stageId)
			) {
				continue;
			}

			if (!$zipFile || $this->isNewerSubmissionFile($submissionFile, $zipFile)) {
				$zipFile = $submissionFile;
			}
		}

		return $zipFile;
	}

	/**
	 * Check whether a submission file is newer than another.
	 *
	 * @param SubmissionFile $candidate
	 * @param SubmissionFile $current
	 * @return bool
	 */
	protected function isNewerSubmissionFile($candidate, $current) {
		$candidateUpdatedAt = strtotime($candidate->getData('updatedAt'));
		$currentUpdatedAt = strtotime($current->getData('updatedAt'));
		if ($candidateUpdatedAt === $currentUpdatedAt) {
			return (int) $candidate->getId() > (int) $current->getId();
		}
		return $candidateUpdatedAt > $currentUpdatedAt;
	}

	/**
	 * Check whether a file is a Web Project ZIP.
	 *
	 * @param SubmissionFile $submissionFile
	 * @param Context $context
	 * @return bool
	 */
	protected function isWebProjectZipFile($submissionFile, $context) {
		if (Services::get('file')->getDocumentType($submissionFile->getData('mimetype')) !== DOCUMENT_TYPE_ZIP) {
			return false;
		}

		$genreId = (int) $submissionFile->getData('genreId');
		if (!$genreId) {
			return false;
		}

		$genreDao = DAORegistry::getDAO('GenreDAO'); /* @var $genreDao GenreDAO */
		$genre = $genreDao->getById($genreId, $context->getId());
		return $genre && $genre->getEnabled() && $genre->getKey() === self::WEB_PROJECT_GENRE_KEY;
	}

	/**
	 * Get the Web Project genre for the current context.
	 *
	 * @param Context $context
	 * @return Genre|null
	 */
	protected function getWebProjectGenre($context) {
		if (!$context) {
			return null;
		}

		$genreDao = DAORegistry::getDAO('GenreDAO'); /* @var $genreDao GenreDAO */
		return $genreDao->getByKey(self::WEB_PROJECT_GENRE_KEY, $context->getId());
	}

	/**
	 * Check whether a submission file belongs to the requested workflow stage.
	 *
	 * @param SubmissionFile $submissionFile
	 * @param int $stageId
	 * @return bool
	 */
	protected function isSubmissionFileInWorkflowStage($submissionFile, $stageId) {
		return in_array(
			(int) $submissionFile->getData('fileStage'),
			$this->getFileStagesForWorkflowStage($stageId)
		);
	}

	/**
	 * Check whether the current user can read a specific submission file.
	 *
	 * @param Request $request
	 * @param Submission $submission
	 * @param SubmissionFile $submissionFile
	 * @param int $stageId
	 * @return bool
	 */
	protected function canPreviewSubmissionFile($request, $submission, $submissionFile, $stageId) {
		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user || !$context) {
			return false;
		}

		if (
			$submissionFile->getData('submissionId') != $submission->getId() ||
			!$this->isSubmissionFileInWorkflowStage($submissionFile, $stageId)
		) {
			return false;
		}

		$userRoleIds = $this->getUserRoleIds($user, $context);
		if (in_array(ROLE_ID_SITE_ADMIN, $userRoleIds)) {
			return true;
		}

		$accessibleWorkflowStages = Services::get('user')->getAccessibleWorkflowStages(
			$user->getId(),
			$context->getId(),
			$submission,
			$userRoleIds
		);
		$assignedFileStages = Services::get('submissionFile')->getAssignedFileStages(
			$accessibleWorkflowStages,
			SUBMISSION_FILE_ACCESS_READ
		);
		if (in_array((int) $submissionFile->getData('fileStage'), $assignedFileStages)) {
			return true;
		}

		if ((int) $submissionFile->getData('uploaderUserId') === (int) $user->getId()) {
			return true;
		}

		return $this->canReviewerPreviewSubmissionFile($request, $submissionFile, $stageId);
	}

	/**
	 * Check whether a reviewer can read a review file assigned to them.
	 *
	 * @param Request $request
	 * @param SubmissionFile $submissionFile
	 * @param int $stageId
	 * @return bool
	 */
	protected function canReviewerPreviewSubmissionFile($request, $submissionFile, $stageId) {
		if (!in_array($stageId, [WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW])) {
			return false;
		}

		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user || !$context) {
			return false;
		}

		$reviewFileStage = $stageId == WORKFLOW_STAGE_ID_INTERNAL_REVIEW
			? SUBMISSION_FILE_INTERNAL_REVIEW_FILE
			: SUBMISSION_FILE_REVIEW_FILE;
		if ((int) $submissionFile->getData('fileStage') !== $reviewFileStage) {
			return false;
		}

		$reviewFilesDao = DAORegistry::getDAO('ReviewFilesDAO'); /* @var $reviewFilesDao ReviewFilesDAO */
		foreach ($this->getActiveReviewAssignmentsForFile($request, $submissionFile, $stageId) as $reviewAssignment) {
			if (
				$context->getData('restrictReviewerFileAccess') &&
				!$reviewAssignment->getDateConfirmed()
			) {
				continue;
			}
			if ($reviewFilesDao->check($reviewAssignment->getId(), $submissionFile->getId())) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get active review assignments for a submission file.
	 *
	 * @param Request $request
	 * @param SubmissionFile $submissionFile
	 * @param int $stageId
	 * @return array
	 */
	protected function getActiveReviewAssignmentsForFile($request, $submissionFile, $stageId) {
		$submission = Services::get('submission')->get($submissionFile->getData('submissionId'));
		if (!$submission) {
			return [];
		}

		return $this->getActiveReviewAssignments($submission, $request->getUser(), $stageId);
	}

	/**
	 * Get submission file stages that belong to a workflow stage.
	 *
	 * @param int $stageId
	 * @return array
	 */
	protected function getFileStagesForWorkflowStage($stageId) {
		switch ($stageId) {
			case WORKFLOW_STAGE_ID_SUBMISSION:
				return [SUBMISSION_FILE_SUBMISSION];
			case WORKFLOW_STAGE_ID_INTERNAL_REVIEW:
				return [SUBMISSION_FILE_INTERNAL_REVIEW_FILE, SUBMISSION_FILE_INTERNAL_REVIEW_REVISION];
			case WORKFLOW_STAGE_ID_EXTERNAL_REVIEW:
				return [SUBMISSION_FILE_REVIEW_FILE, SUBMISSION_FILE_REVIEW_REVISION];
			case WORKFLOW_STAGE_ID_EDITING:
				return [SUBMISSION_FILE_FINAL, SUBMISSION_FILE_COPYEDIT];
			case WORKFLOW_STAGE_ID_PRODUCTION:
				return [SUBMISSION_FILE_PRODUCTION_READY];
			default:
				return [];
		}
	}

	/**
	 * Extract the ZIP if needed and return the extraction directory.
	 *
	 * @param Request $request
	 * @param Submission $submission
	 * @param SubmissionFile $zipFile
	 * @return string|null
	 */
	protected function prepareExtractedProject($request, $submission, $zipFile) {
		$zipPath = $this->getAbsoluteSubmissionFilePath($zipFile);
		if (!$zipPath || !is_file($zipPath)) {
			$request->getDispatcher()->handle404();
		}

		$extractDir = $this->getExtractDir($submission, $zipFile);
		$markerPath = $extractDir . DIRECTORY_SEPARATOR . self::MARKER_FILE;
		$fingerprint = $this->getZipFingerprint($zipFile, $zipPath);
		if (is_file($markerPath) && $this->isExtractCacheCurrent($markerPath, $fingerprint)) {
			return $extractDir;
		}

		$this->removeDirectory($extractDir);
		if (!mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
			$this->showPreviewError(__('plugins.generic.websitePreview.extractFailed'));
			return null;
		}

		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			$this->showPreviewError(__('plugins.generic.websitePreview.invalidZip'));
			return null;
		}

		if (!$this->validateZip($zip)) {
			$zip->close();
			$this->removeDirectory($extractDir);
			$this->showPreviewError(__('plugins.generic.websitePreview.unsafeZip'));
			return null;
		}

		if (!$this->extractZip($zip, $extractDir)) {
			$zip->close();
			$this->removeDirectory($extractDir);
			$this->showPreviewError(__('plugins.generic.websitePreview.extractFailed'));
			return null;
		}

		$zip->close();
		$this->writeExtractMarker($markerPath, $this->getZipFingerprint($zipFile, $zipPath, true));

		return $extractDir;
	}

	/**
	 * Build a fingerprint for the uploaded ZIP.
	 *
	 * @param SubmissionFile $zipFile
	 * @param string $zipPath
	 * @param bool $includeHash
	 * @return array
	 */
	protected function getZipFingerprint($zipFile, $zipPath, $includeHash = false) {
		return $this->getArchiveService()->getZipFingerprint($zipFile, $zipPath, $includeHash);
	}

	/**
	 * Check whether an extracted project still matches its ZIP.
	 *
	 * @param string $markerPath
	 * @param array $fingerprint
	 * @return bool
	 */
	protected function isExtractCacheCurrent($markerPath, $fingerprint) {
		return $this->getArchiveService()->isExtractCacheCurrent($markerPath, $fingerprint);
	}

	/**
	 * Write an extraction cache marker.
	 *
	 * @param string $markerPath
	 * @param array $fingerprint
	 */
	protected function writeExtractMarker($markerPath, $fingerprint) {
		$this->getArchiveService()->writeExtractMarker($markerPath, $fingerprint);
	}

	/**
	 * Validate a ZIP archive before extraction.
	 *
	 * @param ZipArchive $zip
	 * @return bool
	 */
	protected function validateZip($zip) {
		return $this->getArchiveService()->validateZip($zip);
	}

	/**
	 * Extract a validated ZIP archive.
	 *
	 * @param ZipArchive $zip
	 * @param string $extractDir
	 * @return bool
	 */
	protected function extractZip($zip, $extractDir) {
		return $this->getArchiveService()->extractZip($zip, $extractDir);
	}

	/**
	 * Find the preview entry point in the extracted project.
	 *
	 * Root index.html wins. If no root index exists, exactly one nested
	 * index.html is allowed so projects with several entry points fail clearly.
	 *
	 * @param string $extractDir
	 * @param string|null $error
	 * @param array $candidates
	 * @return string|null
	 */
	protected function findIndexPath($extractDir, &$error = null, &$candidates = []) {
		return $this->getArchiveService()->findIndexPath($extractDir, $error, $candidates);
	}

	/**
	 * Get all index.html candidates in a deterministic order.
	 *
	 * @param string $extractDir
	 * @return array
	 */
	protected function getIndexCandidates($extractDir) {
		return $this->getArchiveService()->getIndexCandidates($extractDir);
	}

	/**
	 * Resolve a project asset path safely.
	 *
	 * @param string $extractDir
	 * @param string $relativePath
	 * @return string|null
	 */
	protected function resolveAssetPath($extractDir, $relativePath) {
		return $this->getArchiveService()->resolveAssetPath($extractDir, $relativePath);
	}

	/**
	 * Validate a relative path.
	 *
	 * @param string $path
	 * @return bool
	 */
	protected function isSafeRelativePath($path) {
		return $this->getArchiveService()->isSafeRelativePath($path);
	}

	/**
	 * Only allow static web project assets.
	 *
	 * @param string $path
	 * @return bool
	 */
	protected function isAllowedStaticFile($path) {
		return $this->getArchiveService()->isAllowedStaticFile($path);
	}

	/**
	 * Get the archive service.
	 *
	 * @return WebsitePreviewArchiveService
	 */
	protected function getArchiveService() {
		if (!$this->archiveService) {
			$this->archiveService = new WebsitePreviewArchiveService();
		}
		return $this->archiveService;
	}

	/**
	 * Get the absolute path to the uploaded ZIP.
	 *
	 * @param SubmissionFile $zipFile
	 * @return string|null
	 */
	protected function getAbsoluteSubmissionFilePath($zipFile) {
		$filesDir = rtrim(Config::getVar('files', 'files_dir'), DIRECTORY_SEPARATOR);
		$path = $zipFile->getData('path');
		$fullPath = $filesDir . DIRECTORY_SEPARATOR . $path;
		$realFilesDir = realpath($filesDir);
		$realFile = realpath($fullPath);

		if (!$realFilesDir || !$realFile || strpos($realFile, $realFilesDir . DIRECTORY_SEPARATOR) !== 0) {
			return null;
		}

		return $realFile;
	}

	/**
	 * Get the extraction directory.
	 *
	 * @param Submission $submission
	 * @param SubmissionFile $zipFile
	 * @return string
	 */
	protected function getExtractDir($submission, $zipFile) {
		$filesDir = rtrim(Config::getVar('files', 'files_dir'), DIRECTORY_SEPARATOR);
		return $filesDir
			. DIRECTORY_SEPARATOR . 'websitePreview'
			. DIRECTORY_SEPARATOR . (int) $submission->getId()
			. DIRECTORY_SEPARATOR . (int) $zipFile->getId();
	}

	/**
	 * Remove a directory recursively.
	 *
	 * @param string $dir
	 */
	protected function removeDirectory($dir) {
		if (!is_dir($dir)) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($dir);
	}

	/**
	 * Send a user-facing preview error page.
	 *
	 * @param string $message
	 * @param int $statusCode
	 * @param array $details
	 */
	protected function showPreviewError($message, $statusCode = 200, $details = []) {
		$this->sendHtmlHeaders($statusCode, self::PREVIEW_PAGE_CSP);
		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . htmlspecialchars(__('plugins.generic.websitePreview.previewUnavailable'), ENT_QUOTES, 'UTF-8') . '</title>';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f8;color:#333;}main{max-width:42rem;margin:12vh auto;padding:2rem;background:#fff;border:1px solid #ddd;}h1{font-size:1.25rem;margin:0 0 1rem;}p{line-height:1.5;margin:0;}ul{margin:1rem 0 0;padding-left:1.5rem;}li{line-height:1.5;}</style>';
		echo '</head><body><main>';
		echo '<h1>' . htmlspecialchars(__('plugins.generic.websitePreview.previewUnavailable'), ENT_QUOTES, 'UTF-8') . '</h1>';
		echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
		if (!empty($details)) {
			echo '<ul>';
			foreach (array_slice($details, 0, 10) as $detail) {
				echo '<li>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</li>';
			}
			echo '</ul>';
		}
		echo '</main></body></html>';
	}

	/**
	 * Send HTML page headers.
	 *
	 * @param int $statusCode
	 * @param string $contentSecurityPolicy
	 */
	protected function sendHtmlHeaders($statusCode, $contentSecurityPolicy) {
		http_response_code($statusCode);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		header('Content-Security-Policy: ' . $contentSecurityPolicy);
	}

	/**
	 * Send static asset headers.
	 *
	 * @param string $mimeType
	 * @param int $contentLength
	 */
	protected function sendAssetHeaders($mimeType, $contentLength) {
		header('Content-Type: ' . $mimeType);
		header('Content-Length: ' . $contentLength);
		header('Cache-Control: private, no-store');
		header('X-Content-Type-Options: nosniff');
		header('Content-Security-Policy: ' . self::ASSET_CSP);
	}

	/**
	 * Map common static file extensions to MIME types.
	 *
	 * @param string $path
	 * @return string
	 */
	protected function getMimeType($path) {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$types = [
			'html' => 'text/html; charset=utf-8',
			'htm' => 'text/html; charset=utf-8',
			'css' => 'text/css; charset=utf-8',
			'js' => 'text/javascript; charset=utf-8',
			'mjs' => 'text/javascript; charset=utf-8',
			'json' => 'application/json; charset=utf-8',
			'txt' => 'text/plain; charset=utf-8',
			'md' => 'text/plain; charset=utf-8',
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'svg' => 'image/svg+xml',
			'webp' => 'image/webp',
			'ico' => 'image/x-icon',
			'woff' => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf' => 'font/ttf',
			'otf' => 'font/otf',
			'mp3' => 'audio/mpeg',
			'mp4' => 'video/mp4',
			'webm' => 'video/webm',
			'ogg' => 'audio/ogg',
		];
		return $types[$extension] ?? 'application/octet-stream';
	}
}
