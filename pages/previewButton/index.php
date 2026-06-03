<?php

/**
 * @file pages/previewButton/index.php
 *
 * @brief Route preview button plugin page requests.
 */

switch ($op) {
	case 'view':
	case 'download':
		define('HANDLER_CLASS', 'PreviewButtonHandler');
		require_once(dirname(__FILE__) . '/PreviewButtonHandler.inc.php');
		break;
}
