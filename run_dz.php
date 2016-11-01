<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);

require 'DzSync.php';


function exception_error_handler($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
}


function main($argc, $argv) {
	if (php_sapi_name() != "cli")
	    die("Please use php-cli\n");

	if (phpversion() < "5.4")
	  die("Required PHP >= 5.4");

	if ($argc < 2) 
		die("Usage: php dz.php config.ini\n");

	set_error_handler("exception_error_handler");

	$daemon = new DozorroSyncDaemon($argv[1]);
	$daemon->run();
	return 0;
}

exit(main($argc, $argv));
