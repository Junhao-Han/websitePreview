<?php

/**
 * @file pages/websitePreview/index.php
 *
 * @brief Route website preview page requests.
 */

switch ($op) {
	case 'view':
	case 'asset':
		define('HANDLER_CLASS', 'WebsitePreviewHandler');
		require_once(dirname(__FILE__) . '/WebsitePreviewHandler.inc.php');
		break;
}
