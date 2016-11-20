<?php

namespace Level7\RtpTester;


/**
 * Logger interface
 */
interface LoggerInterface
{
    /**
     * Constructor
     * 
     * @param array $argv
     * @param Level7\RtpTester\Core $core
     */
    public function __construct($argv, \Level7\RtpTester\Core $core);

    /**
     * Logs raw packet
     * 
     * @param string $packet
     */
    public function logRaw($packet);

    public function flushRaw($buffer);
    
    /**
     * Logs statistics
     * 
     * @param array $data
     */
    public function logStats($data);
}
