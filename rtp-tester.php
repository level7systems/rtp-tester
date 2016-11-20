#!/usr/bin/php -q
<?php
require_once('src/Core.php');
require_once('src/LoggerInterface.php');
require_once('src/Logger.php');


$rtpTester = new \Level7\RtpTester\Core($argv, __DIR__);

$logger = new \Level7\RtpTester\Logger($argv, $rtpTester);
$rtpTester->setLogger($logger);

$rtpTester->run();
