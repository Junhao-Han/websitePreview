<?php

require_once(dirname(__FILE__) . '/../classes/WebsitePreviewArchiveService.inc.php');

use PHPUnit\Framework\TestCase;

class WebsitePreviewArchiveServiceTest extends TestCase {
	/** @var string */
	private $root;
	/** @var WebsitePreviewArchiveService */
	private $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new WebsitePreviewArchiveService();
		$this->root = sys_get_temp_dir() . '/website-preview-test-' . uniqid('', true);
		mkdir($this->root, 0775, true);
	}

	protected function tearDown(): void {
		$this->removeTestDirectory($this->root);
		parent::tearDown();
	}

	public function testRootIndexHtmlWins(): void {
		$project = $this->root . '/root-index';
		$this->writeFile($project . '/index.html', 'root');
		$this->writeFile($project . '/nested/index.html', 'nested');

		$error = null;
		$candidates = [];

		$this->assertSame('index.html', $this->service->findIndexPath($project, $error, $candidates));
		$this->assertNull($error);
	}

	public function testSingleNestedIndexHtmlIsAccepted(): void {
		$project = $this->root . '/single-nested-index';
		$this->writeFile($project . '/web-project/index.html', 'nested');

		$error = null;
		$candidates = [];

		$this->assertSame('web-project/index.html', $this->service->findIndexPath($project, $error, $candidates));
	}

	public function testMultipleNestedIndexHtmlFilesAreNotGuessed(): void {
		$project = $this->root . '/multiple-nested-index';
		$this->writeFile($project . '/a/index.html', 'a');
		$this->writeFile($project . '/b/index.html', 'b');

		$error = null;
		$candidates = [];

		$this->assertNull($this->service->findIndexPath($project, $error, $candidates));
		$this->assertSame('multiple', $error);
	}

	public function testMissingIndexHtmlFails(): void {
		$project = $this->root . '/missing-index';
		$this->writeFile($project . '/page.html', 'page');

		$error = null;
		$candidates = [];

		$this->assertNull($this->service->findIndexPath($project, $error, $candidates));
		$this->assertSame('missing', $error);
	}

	public function testPathAndFileTypeValidation(): void {
		$this->assertTrue($this->service->isSafeRelativePath('web-project/index.html'));
		$this->assertFalse($this->service->isSafeRelativePath('../index.html'));
		$this->assertFalse($this->service->isSafeRelativePath('web-project/../../evil.html'));
		$this->assertFalse($this->service->isSafeRelativePath('/absolute/path.html'));
		$this->assertTrue($this->service->isAllowedStaticFile('web-project/script.js'));
		$this->assertFalse($this->service->isAllowedStaticFile('web-project/server.php'));
	}

	public function testZipValidation(): void {
		if (!class_exists('ZipArchive')) {
			$this->markTestSkipped('ZipArchive is not available.');
		}

		$validZipPath = $this->root . '/valid.zip';
		$this->makeZip($validZipPath, [
			'web-project/index.html' => '<html></html>',
			'web-project/style.css' => 'body {}',
		]);
		$zip = $this->openZip($validZipPath);
		$this->assertTrue($this->service->validateZip($zip));
		$zip->close();

		$unsafeZipPath = $this->root . '/unsafe.zip';
		$this->makeZip($unsafeZipPath, ['../evil.html' => 'evil']);
		$zip = $this->openZip($unsafeZipPath);
		$this->assertFalse($this->service->validateZip($zip));
		$zip->close();

		$phpZipPath = $this->root . '/php.zip';
		$this->makeZip($phpZipPath, [
			'web-project/index.html' => '<html></html>',
			'web-project/server.php' => '<?php',
		]);
		$zip = $this->openZip($phpZipPath);
		$this->assertFalse($this->service->validateZip($zip));
		$zip->close();
	}

	public function testCacheMarkerValidation(): void {
		if (!class_exists('ZipArchive')) {
			$this->markTestSkipped('ZipArchive is not available.');
		}

		$validZipPath = $this->root . '/valid.zip';
		$this->makeZip($validZipPath, ['web-project/index.html' => '<html></html>']);
		$zipFile = new WebsitePreviewArchiveServiceTestSubmissionFile(10, [
			'fileId' => 20,
			'path' => 'journals/1/articles/11/submission/web-project.zip',
			'updatedAt' => '2026-06-08 12:00:00',
		]);
		$markerPath = $this->root . '/marker.json';
		$fingerprint = $this->service->getZipFingerprint($zipFile, $validZipPath, true);

		$this->service->writeExtractMarker($markerPath, $fingerprint);
		$this->assertTrue($this->service->isExtractCacheCurrent($markerPath, $fingerprint));

		$changedFingerprint = $fingerprint;
		$changedFingerprint['fileId'] = 21;
		$this->assertFalse($this->service->isExtractCacheCurrent($markerPath, $changedFingerprint));

		$changedFingerprint = $fingerprint;
		$changedFingerprint['sha256'] = str_repeat('0', 64);
		$this->assertFalse($this->service->isExtractCacheCurrent($markerPath, $changedFingerprint));
	}

	private function makeDirectory($path): void {
		if (!is_dir($path) && !mkdir($path, 0775, true)) {
			throw new RuntimeException('Unable to create directory: ' . $path);
		}
	}

	private function writeFile($path, $content = ''): void {
		$this->makeDirectory(dirname($path));
		file_put_contents($path, $content);
	}

	private function removeTestDirectory($path): void {
		if (!is_dir($path)) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($path);
	}

	private function makeZip($path, $entries): void {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Unable to create ZIP: ' . $path);
		}
		foreach ($entries as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
	}

	private function openZip($path): ZipArchive {
		$zip = new ZipArchive();
		if ($zip->open($path) !== true) {
			throw new RuntimeException('Unable to open ZIP: ' . $path);
		}
		return $zip;
	}
}

class WebsitePreviewArchiveServiceTestSubmissionFile {
	private $id;
	private $data;

	public function __construct($id, $data) {
		$this->id = $id;
		$this->data = $data;
	}

	public function getId() {
		return $this->id;
	}

	public function getData($key) {
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}
}
