<?php

/**
 * @file pages/webProjectPreview/index.php
 *
 * @brief Route web project preview page requests.
 */

switch ($op) {
	case 'status':
	case 'view':
	case 'asset':
		define('HANDLER_CLASS', 'WebProjectPreviewHandler');
		require_once(dirname(__FILE__) . '/WebProjectPreviewHandler.inc.php');
		break;
}
