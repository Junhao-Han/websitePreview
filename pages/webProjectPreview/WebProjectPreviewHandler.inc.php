<?php

/**
 * @file pages/webProjectPreview/WebProjectPreviewHandler.inc.php
 *
 * @class WebProjectPreviewHandler
 * @brief Preview a static website ZIP uploaded as a submission file.
 */

import('classes.handler.Handler');
import('lib.pkp.classes.submission.SubmissionFile');

class WebProjectPreviewHandler extends Handler {
	const MAX_FILES = 300;
	const MAX_UNCOMPRESSED_BYTES = 52428800; // 50 MB

	/**
	 * Report whether a submission has a viewable web project ZIP.
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function status($args, $request) {
		$submission = $this->getAuthorizedSubmission($request, $args);
		$zipFile = $this->getZipSubmissionFile($submission);
		$hasProject = false;

		if ($zipFile) {
			$extractDir = $this->prepareExtractedProject($request, $submission, $zipFile, true);
			$hasProject = $extractDir && (bool) $this->findIndexPath($extractDir);
		}

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode([
			'hasProject' => $hasProject,
		]);
		exit;
	}

	/**
	 * Show the web project in a sandboxed iframe.
	 *
	 * @param array $args
	 * @param Request $request
	 */
	public function view($args, $request) {
		$submission = $this->getAuthorizedSubmission($request, $args);
		$zipFile = $this->getZipSubmissionFile($submission);

		if (!$zipFile) {
			$this->showMessage($request, __('plugins.generic.webProjectPreview.noZip'));
			return;
		}

		$extractDir = $this->prepareExtractedProject($request, $submission, $zipFile);
		if (!$extractDir) {
			return;
		}

		$indexPath = $this->findIndexPath($extractDir);
		if (!$indexPath) {
			$this->showMessage($request, __('plugins.generic.webProjectPreview.noIndex'));
			return;
		}

		$assetUrl = $request->getDispatcher()->url(
			$request,
			ROUTE_PAGE,
			$request->getContext()->getPath(),
			'webProjectPreview',
			'asset',
			array_merge([
				$submission->getId(),
				$zipFile->getId(),
			], explode('/', $indexPath))
		);

		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . htmlspecialchars($submission->getLocalizedTitle(), ENT_QUOTES, 'UTF-8') . '</title>';
		echo '<style>html,body{height:100%;margin:0;}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f4f4;}iframe{width:100%;height:100%;border:0;background:#fff;}</style>';
		echo '</head><body>';
		echo '<iframe sandbox="allow-same-origin allow-scripts allow-forms allow-popups" src="' . htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8') . '"></iframe>';
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
		$submissionFileId = array_shift($args);
		$relativePath = implode('/', $args);

		if (!$submissionFileId || !$relativePath) {
			$request->getDispatcher()->handle404();
		}

		$zipFile = Services::get('submissionFile')->get((int) $submissionFileId);
		if (!$zipFile || $zipFile->getData('submissionId') != $submission->getId()) {
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
		header('Content-Type: ' . $mimeType);
		header('Content-Length: ' . filesize($assetPath));
		header('Cache-Control: private');
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

		$reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao ReviewAssignmentDAO */
		$reviewAssignments = $reviewAssignmentDao->getBySubmissionReviewer($submission->getId(), $user->getId());
		while ($reviewAssignment = $reviewAssignments->next()) {
			if (!$reviewAssignment->getDeclined() && !$reviewAssignment->getCancelled()) {
				return true;
			}
		}

		return false;
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
	 * Find the most recent ZIP submission file.
	 *
	 * @param Submission $submission
	 * @return SubmissionFile|null
	 */
	protected function getZipSubmissionFile($submission) {
		$zipFile = null;
		$submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
			'fileStages' => [
				SUBMISSION_FILE_SUBMISSION,
				SUBMISSION_FILE_REVIEW_FILE,
				SUBMISSION_FILE_REVIEW_REVISION,
				SUBMISSION_FILE_INTERNAL_REVIEW_FILE,
				SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
			],
		]);

		foreach ($submissionFiles as $submissionFile) {
			if (Services::get('file')->getDocumentType($submissionFile->getData('mimetype')) !== DOCUMENT_TYPE_ZIP) {
				continue;
			}
			if (!$zipFile || strtotime($submissionFile->getData('updatedAt')) > strtotime($zipFile->getData('updatedAt'))) {
				$zipFile = $submissionFile;
			}
		}

		return $zipFile;
	}

	/**
	 * Extract the ZIP if needed and return the extraction directory.
	 *
	 * @param Request $request
	 * @param Submission $submission
	 * @param SubmissionFile $zipFile
	 * @return string|null
	 */
	protected function prepareExtractedProject($request, $submission, $zipFile, $silent = false) {
		$extractDir = $this->getExtractDir($submission, $zipFile);
		$markerPath = $extractDir . DIRECTORY_SEPARATOR . '.web-project-preview-ready';
		if (is_file($markerPath)) {
			return $extractDir;
		}

		$zipPath = $this->getAbsoluteSubmissionFilePath($zipFile);
		if (!$zipPath || !is_file($zipPath)) {
			if ($silent) {
				return null;
			}
			$request->getDispatcher()->handle404();
		}

		$this->removeDirectory($extractDir);
		if (!mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
			if (!$silent) {
				$this->showMessage($request, __('plugins.generic.webProjectPreview.invalidZip'));
			}
			return null;
		}

		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true) {
			if (!$silent) {
				$this->showMessage($request, __('plugins.generic.webProjectPreview.invalidZip'));
			}
			return null;
		}

		if (!$this->validateZip($zip)) {
			$zip->close();
			$this->removeDirectory($extractDir);
			if (!$silent) {
				$this->showMessage($request, __('plugins.generic.webProjectPreview.unsafeZip'));
			}
			return null;
		}

		if (!$this->extractZip($zip, $extractDir)) {
			$zip->close();
			$this->removeDirectory($extractDir);
			if (!$silent) {
				$this->showMessage($request, __('plugins.generic.webProjectPreview.unsafeZip'));
			}
			return null;
		}

		$zip->close();
		file_put_contents($markerPath, date('c'));

		return $extractDir;
	}

	/**
	 * Validate a ZIP archive before extraction.
	 *
	 * @param ZipArchive $zip
	 * @return bool
	 */
	protected function validateZip($zip) {
		$totalBytes = 0;
		if ($zip->numFiles > self::MAX_FILES) {
			return false;
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			$name = $stat['name'];
			if (!$this->isSafeRelativePath($name)) {
				return false;
			}
			if (substr($name, -1) === '/') {
				continue;
			}
			if (!$this->isAllowedStaticFile($name)) {
				return false;
			}
			$totalBytes += (int) $stat['size'];
			if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Extract a validated ZIP archive.
	 *
	 * @param ZipArchive $zip
	 * @param string $extractDir
	 * @return bool
	 */
	protected function extractZip($zip, $extractDir) {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			$name = $stat['name'];
			if (substr($name, -1) === '/') {
				continue;
			}

			$target = $this->resolveAssetPath($extractDir, $name);
			if (!$target) {
				return false;
			}

			$targetDir = dirname($target);
			if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
				return false;
			}

			$source = $zip->getStream($name);
			if (!$source) {
				return false;
			}
			$destination = fopen($target, 'wb');
			if (!$destination) {
				fclose($source);
				return false;
			}
			stream_copy_to_stream($source, $destination);
			fclose($source);
			fclose($destination);
		}

		return true;
	}

	/**
	 * Find index.html in the extracted project.
	 *
	 * @param string $extractDir
	 * @return string|null
	 */
	protected function findIndexPath($extractDir) {
		$rootIndex = $extractDir . DIRECTORY_SEPARATOR . 'index.html';
		if (is_file($rootIndex)) {
			return 'index.html';
		}

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir));
		foreach ($iterator as $file) {
			if (!$file->isFile()) {
				continue;
			}
			if (strtolower($file->getFilename()) === 'index.html') {
				$relative = substr($file->getPathname(), strlen($extractDir) + 1);
				return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
			}
		}

		return null;
	}

	/**
	 * Resolve a project asset path safely.
	 *
	 * @param string $extractDir
	 * @param string $relativePath
	 * @return string|null
	 */
	protected function resolveAssetPath($extractDir, $relativePath) {
		if (!$this->isSafeRelativePath($relativePath)) {
			return null;
		}

		$base = realpath($extractDir);
		if (!$base) {
			return null;
		}

		$target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
		$realTarget = realpath($target);
		if ($realTarget && strpos($realTarget, $base . DIRECTORY_SEPARATOR) === 0) {
			return $realTarget;
		}

		$targetDir = dirname($target);
		$realTargetDir = is_dir($targetDir) ? realpath($targetDir) : $targetDir;
		if (!$realTarget && ($realTargetDir === $base || strpos($realTargetDir, $base . DIRECTORY_SEPARATOR) === 0)) {
			return $target;
		}

		return null;
	}

	/**
	 * Validate a relative path.
	 *
	 * @param string $path
	 * @return bool
	 */
	protected function isSafeRelativePath($path) {
		if ($path === '' || strpos($path, "\0") !== false) {
			return false;
		}
		if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
			return false;
		}
		$parts = preg_split('/[\\\\\/]+/', $path);
		foreach ($parts as $part) {
			if ($part === '..') {
				return false;
			}
		}
		return true;
	}

	/**
	 * Only allow static web project assets.
	 *
	 * @param string $path
	 * @return bool
	 */
	protected function isAllowedStaticFile($path) {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($extension, [
			'html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'md',
			'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
			'woff', 'woff2', 'ttf', 'otf',
			'mp3', 'mp4', 'webm', 'ogg',
		]);
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
			. DIRECTORY_SEPARATOR . 'webProjectPreview'
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
	 * Send a simple HTML message.
	 *
	 * @param Request $request
	 * @param string $message
	 */
	protected function showMessage($request, $message) {
		header('Content-Type: text/html; charset=utf-8');
		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:3rem;line-height:1.5;color:#333;}</style>';
		echo '</head><body><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
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
