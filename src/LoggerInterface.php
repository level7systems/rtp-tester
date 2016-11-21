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
     * Flush raw packets
     * 
     * @param array $buffer
     */
    public function flushRaw($buffer);
    
    /**
     * Logs statistics
     * 
     * @param array $data
     */
    public function logStats($data);

    /**
     * Logs error
     * 
     * @param string $msg
     */
    public function logError($msg);
}
