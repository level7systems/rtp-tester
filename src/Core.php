<?php

namespace Level7\RtpTester;

class Core
{
	const MODE_SERVER = 's';
	const MODE_CLIENT = 'c';

	private $debug = false;
	private $mode;
	private $serverIp;
	private $port = 25000;
	private $pps = 50;
	private $bs = 160;
	private $reportInterval = 10;
	private $flushInterval = 1000;
	private $socket;
	private $keepRunning = true;
	private $logger;
	private $cwd;
	private $usage;
	
	private $rawBuffer = [];

	public function __construct($argv, $cwd)
	{
		$usage = "rtp-tester.php [options]\n\n";
		$usage.= "available options:\n";
		$usage.= " -s               run is server mode\n";
		$usage.= " -c <server_ip>   run is client mode\n";
		$usage.= " -p <port>        port number to send to or bind\n";
		$usage.= " -i <pps>         packets per second to send (default: 50)\n";
		$usage.= " -b <bs>          payload size in bytes (default: 160 Bytes)\n";

		$this->setUsage($usage);

		declare(ticks = 100);
		error_reporting(E_ALL);
		date_default_timezone_set('UTC');

		$this->cwd = $cwd;

		if (!chdir($this->cwd)) {
			die(sprintf("Error: failed to 'cd %s'", $this->cwd));
		}

        pcntl_signal(SIGTERM, array($this,"signalHandler"));
        pcntl_signal(SIGINT, array($this,"signalHandler"));

		if (in_array("-h", $argv) || in_array("--h", $argv) || in_array("-help", $argv) || in_array("--help", $argv)) {
			$this->printUsage();
		}
		
		if (in_array("-s", $argv) && in_array("-c", $argv)) {
			die("Error: both -s and -c is not allowed\n");
		}

		if (in_array("-s", $argv)) {
			$this->setMode(self::MODE_SERVER);
		}

		if ($key = array_search("-p", $argv)) {

			if (!isset($argv[$key+1])) {
				die("Error: <port> parameter missing\n");
			}

			if (!preg_match('/^[0-9]+$/', $argv[$key+1]) || $argv[$key+1] < 10000 || $argv[$key+1] > 60000) {
				die("Error: <port> has to be a number between 10000-60000\n");
			}

			$this->port = $argv[$key+1];
		}

		if (in_array("-c", $argv)) {
			
			$key = array_search("-c", $argv);

			if (!isset($argv[$key+1])) {
				die("Error: <server_ip> parameter missing\n");
			}

			if (!preg_match('/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/', $argv[$key+1])) {
				die("Error: <server_ip> parameter has invalid format\n");
			}

			$this->serverIp = $argv[$key+1];

			if ($key = array_search("-i", $argv)) {

				if (!isset($argv[$key+1])) {
					die("Error: <pps> parameter missing\n");
				}

				if (!preg_match('/^[0-9]+$/', $argv[$key+1]) || $argv[$key+1] < 30 || $argv[$key+1] > 50) {
					die("Error: <pps> has to be a number between 30-50\n");
				}

				$this->pps = $argv[$key+1];
			}

			if ($key = array_search("-b", $argv)) {

				if (!isset($argv[$key+1])) {
					die("Error: <bs> parameter missing\n");
				}

				if (!preg_match('/^[0-9]+$/', $argv[$key+1]) || $argv[$key+1] < 20 || $argv[$key+1] > 160) {
					die("Error: <bs> has to be a number between 20-160\n");
				}

				$this->bs = $argv[$key+1];
			}

			$this->setMode(self::MODE_CLIENT);
		}
	}

	public function setLogger($logger)
	{
		$this->logger = $logger;
	}

	public function getCwd()
	{
		return $this->cwd;
	}

	private function setMode($v)
	{
		$this->mode = $v;
	}

	public function getMode()
	{
		return $this->mode;
	}

	public function run()
	{
		if (!$this->logger) {
			die("Error: unable to start without logger\n");
		}

		if ($this->mode == self::MODE_CLIENT) {
			$this->startClient();
		} else if ($this->mode == self::MODE_SERVER) {
			$this->startServer();
		} else {
			$this->printUsage();
		}
	}

	private function startServer()
	{
		$this->createSocket();

		$prevTime = 0;
		$prevCseq = 0;
		$reportCounter = 0;
		$previousMin = null;
		$previousMax = null;
		$latencyMax = null;
		$latencyMin = null;
		$lost = 0;
		$jitterBuffer = [];
		$latencySum = 0;
		$previousSum = 0;
		$outOfOrder = 0;

		$reportEveryPackets = $this->reportInterval * $this->pps;

		echo sprintf("Listening for UDP packets on 0.0.0.0:%d, reporting every %d packets (%d seconds)...\n", 
			$this->port, $reportEveryPackets, $this->reportInterval);		

	    while ($this->keepRunning) {
	        if (!$data = $this->readMessage()) {
	        	continue;
	        }

	        $timestamp = microtime(true);
	        $dateTime = date("Y-m-d H:i:s");

	        $reportCounter++;

	        $this->rawBuffer[] = $dateTime.";".$timestamp.";".$data['from_ip'].":".$data['from_port'].";".$data['msg'];
	       	
	       	if ($prevTime) {
	       		$previous = round(($timestamp - $prevTime) * 1000, 4);

	       		$jitterBuffer[] = $previous;

	       		$previousSum+= $previous;

	        	if ($previousMax === null && $previousMin === null) {
	        		$previousMax = $previous;
	        		$previousMin = $previous;
	        	}

	        	if ($previous > $previousMax) {
	        		$previousMax = $previous;
	        	}

	        	if ($previous < $previousMin) {
	        		$previousMin = $previous;
	        	}
	       	}

	       	$prevTime = $timestamp;

	        $temp = explode(";", $data['msg']);

	        if (count($temp) === 3 && preg_match('/^[0-9]+$/', $temp[0]) && preg_match('/^[0-9]+(\.[0-9]+)?$/', $temp[1])) {

	        	$rcvSeq = $temp[0];

	        	if ($prevCseq) {
	        		
	        		if (($rcvSeq - $prevCseq) > 1) {
	        			$lost+= $rcvSeq - $prevCseq;
	        		}

	        		if ($rcvSeq < $prevCseq) {
	        			$outOfOrder++;
	        		}
	        	}

	        	$prevCseq = $rcvSeq;

	        	$rcvTimestamp = $temp[1];

	        	$latency = round(($timestamp - $rcvTimestamp) * 1000, 4);

	        	$latencySum+= $latency;

	        	if ($latencyMax === null && $latencyMin === null) {
	        		$latencyMax = $latency;
	        		$latencyMin = $latency;
	        	}

	        	if ($latency > $latencyMax) {
	        		$latencyMax = $latency;
	        	}

	        	if ($latency < $latencyMin) {
	        		$latencyMin = $latency;
	        	}

	        	if ($reportCounter >= $reportEveryPackets) {

	        		if ($lost) {
	        			$lostPercent = round($lost / $reportCounter * 100, 2);
	        		} else {
	        			$lostPercent = 0;
	        		}

	        		$jitter = round($this->standardDeviation($jitterBuffer), 2);

	        		$latencyAverage = round($latencySum / $reportCounter, 2);
	        		$previousAverage = round($previousSum / $reportCounter, 2);

        			$stats = [
        				"timestamp" 	=> date("Y-m-d H:i:s"),
        				"from_ip"		=> $data['from_ip'],
        				"from_port"		=> $data['from_port'],
        				"seq"			=> $rcvSeq,
        				"previous"	    => $previousAverage,
        				"previous_max"  => $previousMax,
        				"previous_min"  => $previousMin,
        				"latency"		=> $latencyAverage,
        				"latency_max"	=> $latencyMax,
        				"latency_min"	=> $latencyMin,
        				"jitter"		=> $jitter,
        				"lost_percent"	=> $lostPercent,
        				"out_of_order"	=> $outOfOrder
        			];

	        		$this->logger->logStats($stats);

	        		$reportCounter = 0;
					$previousMin = null;
					$previousMax = null;
					$latencyMax = null;
					$latencyMin = null;
	        		$lost = 0;
	        		$outOfOrder = 0;
	        		$jitterBuffer = [];
	        		$latencySum = 0;
	        		$previousSum = 0;
	        	}
	        }

	        if (count($this->rawBuffer) > $this->flushInterval) {
	        	$this->logger->flushRaw($this->rawBuffer);
	        	$this->rawBuffer = [];
	        }
	    }

	    if ($this->rawBuffer) {
	        $this->logger->flushRaw($this->rawBuffer);
	    }
	}

	private function standardDeviation(array $a, $sample = false)
	{
        $n = count($a);
        if ($n === 0) {
            trigger_error("The array has zero elements", E_USER_WARNING);
            return false;
        }
        if ($sample && $n === 1) {
            trigger_error("The array has only 1 element", E_USER_WARNING);
            return false;
        }
        $mean = array_sum($a) / $n;
        $carry = 0.0;
        foreach ($a as $val) {
            $d = ((double) $val) - $mean;
            $carry += $d * $d;
        };
        if ($sample) {
           --$n;
        }
        return sqrt($carry / $n);
	}

	private function startClient()
	{
		$this->createSocket();

		echo sprintf("Sending %d x %s Bytes packets every second to %s:%s...\n", $this->pps, $this->bs, $this->serverIp, $this->port);
		echo "Press Ctrl + C to stop.\n";

		$startTime = time();

		$msec = 1000000;

		$sleep = $msec / $this->pps;

		$seq = 0;

		while ($this->keepRunning) {
			
			$seq++;

			$header = $seq . ";" . microtime(true)  .";";

			$msg = str_pad($header, $this->bs, "Q");

			$this->sendMessage($msg);
			
			usleep($sleep);
		}

		$runTime = time() - $startTime;

		socket_close($this->socket);

		echo sprintf("Sent %d packets in %d seconds\n", $seq, $runTime);
	}

    private function createSocket()
    {
        if (!$this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP))    {
            $err_no = socket_last_error($this->socket);
            throw new \Exception(socket_strerror($err_no));
        }
        
        if (!@socket_bind($this->socket, "0.0.0.0", $this->port)) {
            $err_no = socket_last_error($this->socket);
            throw new \Exception(sprintf("Failed to bind 0.0.0.0:%d, %s", $this->port, socket_strerror($err_no)));
        }
        
        if (!@socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array("sec"=>2,"usec"=>0))) {
            $err_no = socket_last_error($this->socket);
            throw new \Exception(socket_strerror($err_no));
        }
        
        if (!@socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array("sec"=>5,"usec"=>0))) {
            $err_no = socket_last_error($this->socket);
            throw new \Exception(socket_strerror($err_no));
        }
    }

    /**
     * Reads incoming UDP packet
     */
    private function readMessage()
    {
        $fromIp = "";
        $fromPort = 0;
        $msg = null;
        
        if (!@socket_recvfrom($this->socket, $msg, 65535, 0, $fromIp, $fromPort)) {
            $err_no = socket_last_error($this->socket);
            if ($err_no == 4) {
            	echo "\n\nCaught SIGINT, quiting...\n";
            	$this->keepRunning = false;
            }
        }

        if (!$msg) {
        	return false;
        }
        
        return [
        	"timestamp"	=> microtime(true),
        	"msg" 		=> $msg,
        	"from_ip" 	=> $fromIp,
        	"from_port"	=> $fromPort
        ];
    }

    private function sendMessage($data)
    {
	    if (!@socket_sendto($this->socket, $data, strlen($data), 0, $this->serverIp, $this->port)) {
	      	$err_no = socket_last_error($this->socket);
	      	throw new \Exception("Failed to send data to ".$this->serverIp.":".$this->port.", ".socket_strerror($err_no));
	    }
    }

    /**
     * Signal handler
     */
    private function signalHandler($signal)
    {
        switch($signal) {
            case SIGTERM:
            case SIGKILL:
            case SIGINT:
                echo "\n\nCaught SIGINT, quiting...\n";
                $this->keepRunning = false;
                break;
            default:
            	die("\n\nCaught $signal, terminating...\n");
        }
    }

    public function setUsage($text)
    {
    	if (!$this->usage) {
    		$this->usage = $text;
    	} else {
    		$this->usage.= $text;
    	}
    }

	private function printUsage()
	{
		die($this->usage . "\n");
	}
}
