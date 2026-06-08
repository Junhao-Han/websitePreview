<?php

require_once(dirname(__FILE__) . '/../classes/WebsitePreviewArchiveService.inc.php');

if (!class_exists('ZipArchive')) {
	fwrite(STDERR, "ZipArchive is not available\n");
	exit(1);
}

class TestSubmissionFile {
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

$service = new WebsitePreviewArchiveService();
$root = sys_get_temp_dir() . '/website-preview-test-' . uniqid('', true);
mkdir($root, 0775, true);

register_shutdown_function(function () use ($root) {
	removeTestDirectory($root);
});

function assertSameValue($expected, $actual, $message) {
	if ($expected !== $actual) {
		fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

function assertTrueValue($actual, $message) {
	assertSameValue(true, $actual, $message);
}

function assertFalseValue($actual, $message) {
	assertSameValue(false, $actual, $message);
}

function makeDirectory($path) {
	if (!is_dir($path) && !mkdir($path, 0775, true)) {
		throw new RuntimeException('Unable to create directory: ' . $path);
	}
}

function writeFile($path, $content = '') {
	makeDirectory(dirname($path));
	file_put_contents($path, $content);
}

function removeTestDirectory($path) {
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

function makeZip($path, $entries) {
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
		throw new RuntimeException('Unable to create ZIP: ' . $path);
	}
	foreach ($entries as $name => $content) {
		$zip->addFromString($name, $content);
	}
	$zip->close();
}

function openZip($path) {
	$zip = new ZipArchive();
	if ($zip->open($path) !== true) {
		throw new RuntimeException('Unable to open ZIP: ' . $path);
	}
	return $zip;
}

// index.html selection
$project = $root . '/root-index';
writeFile($project . '/index.html', 'root');
writeFile($project . '/nested/index.html', 'nested');
$error = null;
$candidates = [];
assertSameValue('index.html', $service->findIndexPath($project, $error, $candidates), 'Root index.html should win.');
assertSameValue(null, $error, 'Root index.html should not report an error.');

$project = $root . '/single-nested-index';
writeFile($project . '/web-project/index.html', 'nested');
$error = null;
$candidates = [];
assertSameValue('web-project/index.html', $service->findIndexPath($project, $error, $candidates), 'A single nested index.html should be accepted.');

$project = $root . '/multiple-nested-index';
writeFile($project . '/a/index.html', 'a');
writeFile($project . '/b/index.html', 'b');
$error = null;
$candidates = [];
assertSameValue(null, $service->findIndexPath($project, $error, $candidates), 'Multiple nested index.html files should not be guessed.');
assertSameValue('multiple', $error, 'Multiple nested index.html files should report a multiple error.');

$project = $root . '/missing-index';
writeFile($project . '/page.html', 'page');
$error = null;
$candidates = [];
assertSameValue(null, $service->findIndexPath($project, $error, $candidates), 'Missing index.html should fail.');
assertSameValue('missing', $error, 'Missing index.html should report a missing error.');

// Path and file-type validation
assertTrueValue($service->isSafeRelativePath('web-project/index.html'), 'Normal relative paths should be safe.');
assertFalseValue($service->isSafeRelativePath('../index.html'), 'Parent traversal should be unsafe.');
assertFalseValue($service->isSafeRelativePath('web-project/../../evil.html'), 'Nested parent traversal should be unsafe.');
assertFalseValue($service->isSafeRelativePath('/absolute/path.html'), 'Absolute paths should be unsafe.');
assertTrueValue($service->isAllowedStaticFile('web-project/script.js'), 'JavaScript files should be allowed.');
assertFalseValue($service->isAllowedStaticFile('web-project/server.php'), 'PHP files should not be allowed.');

$validZipPath = $root . '/valid.zip';
makeZip($validZipPath, ['web-project/index.html' => '<html></html>', 'web-project/style.css' => 'body {}']);
$zip = openZip($validZipPath);
assertTrueValue($service->validateZip($zip), 'A static website ZIP should validate.');
$zip->close();

$unsafeZipPath = $root . '/unsafe.zip';
makeZip($unsafeZipPath, ['../evil.html' => 'evil']);
$zip = openZip($unsafeZipPath);
assertFalseValue($service->validateZip($zip), 'A ZIP with parent traversal should fail validation.');
$zip->close();

$phpZipPath = $root . '/php.zip';
makeZip($phpZipPath, ['web-project/index.html' => '<html></html>', 'web-project/server.php' => '<?php']);
$zip = openZip($phpZipPath);
assertFalseValue($service->validateZip($zip), 'A ZIP with server-side code should fail validation.');
$zip->close();

// Cache marker validation
$zipFile = new TestSubmissionFile(10, [
	'fileId' => 20,
	'path' => 'journals/1/articles/11/submission/web-project.zip',
	'updatedAt' => '2026-06-08 12:00:00',
]);
$markerPath = $root . '/marker.json';
$fingerprint = $service->getZipFingerprint($zipFile, $validZipPath, true);
$service->writeExtractMarker($markerPath, $fingerprint);
assertTrueValue($service->isExtractCacheCurrent($markerPath, $fingerprint), 'An unchanged ZIP fingerprint should keep the cache current.');

$changedFingerprint = $fingerprint;
$changedFingerprint['fileId'] = 21;
assertFalseValue($service->isExtractCacheCurrent($markerPath, $changedFingerprint), 'A changed file id should invalidate the cache.');

$changedFingerprint = $fingerprint;
$changedFingerprint['sha256'] = str_repeat('0', 64);
assertFalseValue($service->isExtractCacheCurrent($markerPath, $changedFingerprint), 'A changed ZIP hash should invalidate the cache.');

echo "Archive service smoke tests passed\n";
