<?php

namespace Level7\RtpTester;


/**
 * Logger interface
 */
interface LoggerInterface
{
    /**
     * Logs raw packet
     * 
     * @param string $packet
     */
    public function logRaw($packet);
    
    /**
     * Logs statistics
     * 
     * @param array $data
     */
    public function logStats($data);
}
