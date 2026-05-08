<?php
/* Enchilada Framework 3.0
 * Application Bootstrap
 *
 * Loads application configuration, autoloader, and include components.
 * This file lives in system/ and is vendored from the framework.
 *
 * Software License Agreement (BSD License)
 *
 * Copyright (c) 2013-2026, The Daniel Morante Company, Inc.
 * All rights reserved.
 */

@include_once("system/multisite.inc.php"); //Enables Multisite Capabilities
require_once("system/app.conf.php"); //Application Constants
@include_once("config/local.conf.php"); //User Made Application Options
require_once('system/autoload.inc.php'); // Libraries and Classes autoloader

// Debug mode
if (defined('APPLICATION_DEBUG') && APPLICATION_DEBUG) {
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
}

// Timezone
date_default_timezone_set(defined('APPLICATION_TIMEZONE') ? APPLICATION_TIMEZONE : 'UTC');

/*
 * Dynamic Component Loader
 *
 * Auto include anything in the includes/ directory that ends with '.inc.php'.
 * The $SETTINGS object instantiated by 'settings.inc.php' is usually a pre-requisite
 * for other components, so it is loaded first.
 */
$component_loader = function() {
	$components = [];
	$dir = dir('includes');
	if (!$dir) return $components;
	while (false !== ($entry = $dir->read())) {
		if ($entry === 'settings.inc.php' || $entry === 'bootstrap.inc.php') continue;
		$file = 'includes' . DIRECTORY_SEPARATOR . $entry;
		if (is_file($file) && substr($entry, -strlen('.inc.php')) === '.inc.php') {
			$components[] = $file;
		}
	}
	$dir->close();
	return $components;
};

// Load the Settings Component
if (is_file('includes/settings.inc.php')) {
	include 'includes/settings.inc.php';
}

// Load Components
foreach ($component_loader() as $include_file) { include $include_file; }
// Clean Up
unset($component_loader);
