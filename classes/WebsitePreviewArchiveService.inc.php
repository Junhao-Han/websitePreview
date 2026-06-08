<?php

/**
 * @file classes/WebsitePreviewArchiveService.inc.php
 *
 * @class WebsitePreviewArchiveService
 * @brief ZIP archive validation, extraction cache, and index.html selection.
 */

class WebsitePreviewArchiveService {
	const MAX_FILES = 300;
	const MAX_UNCOMPRESSED_BYTES = 52428800; // 50 MB
	const CACHE_VERSION = 2;

	/**
	 * Build a fingerprint for the uploaded ZIP.
	 *
	 * @param SubmissionFile $zipFile
	 * @param string $zipPath
	 * @param bool $includeHash
	 * @return array
	 */
	public function getZipFingerprint($zipFile, $zipPath, $includeHash = false) {
		$fingerprint = [
			'cacheVersion' => self::CACHE_VERSION,
			'submissionFileId' => (int) $zipFile->getId(),
			'fileId' => (int) $zipFile->getData('fileId'),
			'path' => (string) $zipFile->getData('path'),
			'updatedAt' => (string) $zipFile->getData('updatedAt'),
			'size' => filesize($zipPath),
			'mtime' => filemtime($zipPath),
		];

		if ($includeHash) {
			$fingerprint['sha256'] = hash_file('sha256', $zipPath);
		}

		return $fingerprint;
	}

	/**
	 * Check whether an extracted project still matches its ZIP.
	 *
	 * @param string $markerPath
	 * @param array $fingerprint
	 * @return bool
	 */
	public function isExtractCacheCurrent($markerPath, $fingerprint) {
		$marker = json_decode(file_get_contents($markerPath), true);
		if (!is_array($marker)) {
			return false;
		}

		foreach ($fingerprint as $key => $value) {
			if (!array_key_exists($key, $marker) || $marker[$key] != $value) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Write an extraction cache marker.
	 *
	 * @param string $markerPath
	 * @param array $fingerprint
	 */
	public function writeExtractMarker($markerPath, $fingerprint) {
		file_put_contents($markerPath, json_encode($fingerprint, JSON_PRETTY_PRINT));
	}

	/**
	 * Validate a ZIP archive before extraction.
	 *
	 * @param ZipArchive $zip
	 * @return bool
	 */
	public function validateZip($zip) {
		$totalBytes = 0;
		if ($zip->numFiles > self::MAX_FILES) {
			return false;
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (!$stat || !isset($stat['name'])) {
				return false;
			}

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
	public function extractZip($zip, $extractDir) {
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$stat = $zip->statIndex($i);
			if (!$stat || !isset($stat['name'])) {
				return false;
			}

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
	public function findIndexPath($extractDir, &$error = null, &$candidates = []) {
		$error = null;
		$candidates = $this->getIndexCandidates($extractDir);
		if (empty($candidates)) {
			$error = 'missing';
			return null;
		}

		foreach ($candidates as $candidate) {
			if (strtolower($candidate) === 'index.html') {
				return $candidate;
			}
		}

		if (count($candidates) === 1) {
			return $candidates[0];
		}

		$error = 'multiple';
		return null;
	}

	/**
	 * Get all index.html candidates in a deterministic order.
	 *
	 * @param string $extractDir
	 * @return array
	 */
	public function getIndexCandidates($extractDir) {
		$candidates = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if (!$file->isFile() || strtolower($file->getFilename()) !== 'index.html') {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($extractDir) + 1);
			$candidates[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
		}

		sort($candidates, SORT_NATURAL | SORT_FLAG_CASE);
		return $candidates;
	}

	/**
	 * Resolve a project asset path safely.
	 *
	 * @param string $extractDir
	 * @param string $relativePath
	 * @return string|null
	 */
	public function resolveAssetPath($extractDir, $relativePath) {
		if (!$this->isSafeRelativePath($relativePath)) {
			return null;
		}

		$base = realpath($extractDir);
		if (!$base) {
			return null;
		}

		$normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
		$target = $base . DIRECTORY_SEPARATOR . $normalizedPath;
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
	public function isSafeRelativePath($path) {
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
	public function isAllowedStaticFile($path) {
		$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($extension, [
			'html', 'htm', 'css', 'js', 'mjs', 'json', 'txt', 'md',
			'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
			'woff', 'woff2', 'ttf', 'otf',
			'mp3', 'mp4', 'webm', 'ogg',
		]);
	}
}
