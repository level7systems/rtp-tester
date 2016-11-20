#!/usr/bin/php -q
<?php
require_once('src/Core.php');
require_once('src/Logger.php');

$logger = new \Level7\RtpTester\Logger();

$rtpTester = new \Level7\RtpTester\Core($argv);
$rtpTester->setLogger($logger);
$rtpTester->run();
