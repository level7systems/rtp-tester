<?php

namespace Level7;

class RtpTester
{
	const MODE_SERVER = 's';
	const MODE_CLIENT = 'c';

	private $debug = false;
	private $mode;
	private $serverIp;
	private $port = 15001;
	private $pps = 50;
	private $bs = 160;
	private $socket;
	private $keepRunning = true;
	private $cwd;
	private $logDir;
	private $logFileRaw;
	private $logFileCsv;
	private $rcvBuffer = [];
	private $csvBuffer = [];

	public function __construct($argv)
	{
		declare(ticks = 100);
		error_reporting(E_ALL);
		date_default_timezone_set('UTC');

		$this->cwd = __DIR__;

		if (!chdir($this->cwd)) {
			die(sprintf("Error: failed to 'cd %s'", $this->cwd));
		}

		$this->logDir = $this->cwd . DIRECTORY_SEPARATOR . "log";

		if (!is_dir($this->logDir) || !is_writeable($this->logDir)) {
			die(sprintf("Error: %s doesn't exit or is not writeable\n", $this->logDir));
		}

		$this->logFileRaw = $this->logDir . DIRECTORY_SEPARATOR . "rtp-tester." . time() . ".dump";

		if (file_put_contents($this->logFileRaw, "") === false) {
			die(sprintf("Error: failed to write to %s", $this->logFileRaw));
		}

		$this->logFileCsv = $this->logDir . DIRECTORY_SEPARATOR . "rtp-tester." . time() . ".csv";

		if (file_put_contents($this->logFileCsv, "") === false) {
			die(sprintf("Error: failed to write to %s", $this->logFileCsv));
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
			$this->mode = self::MODE_SERVER;
		}

		if ($key = array_search("-p", $argv)) {

			if (!isset($argv[$key+1])) {
				die("Error: <port> parameter missing\n");
			}

			if (!preg_match('/^[0-9]+$/', $argv[$key+1]) || $argv[$key+1] < 10000 || $argv[$key+1] > 20000) {
				die("Error: <port> has to be a number between 10000-20000\n");
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

			$this->mode = self::MODE_CLIENT;
		}
	}

	public function run()
	{
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

		echo sprintf("Listening for UDP packets on 0.0.0.0:%d...\n", $this->port);

		$prevTime = 0;

		$seq = 0;
		$diff = 0;
		$lost = 0;
		$outOfOrder = 0;

	    while ($this->keepRunning) {
	        if (!$data = $this->readMessage()) {
	        	continue;
	        }

	        $timestamp = microtime(true);

	        $seq++;

	        $this->rcvBuffer[] = $data['msg'];
	       	
	       	if ($prevTime) {
	       		$diff = round(($timestamp - $prevTime) * 1000, 4);
	       	}

	       	$prevTime = $timestamp;

	        $temp = explode(";", $data['msg']);

	        if (count($temp) === 3 && preg_match('/^[0-9]+$/', $temp[0]) && preg_match('/^[0-9]+\.[0-9]+$/', $temp[1])) {

	        	$rcvSeq = $temp[0];
	        	$rcvTimestamp = $temp[1];
	        	$latency = round(($timestamp - $rcvTimestamp) * 1000, 4);

	        	if ($seq > $rcvSeq) {
	        		$lost = $seq - $rcvSeq;
	        		$seq = $rcvSeq;
	        	}

	        	if ($seq < $rcvSeq) {
	        		$outOfOrder++;
	        	}

	        	echo sprintf("%s:%s: %d seq, time from previous %s ms, latency %sms, lost %d, out of order: %d\n", $data['from_ip'], $data['from_port'], $rcvSeq, $diff, $latency, $lost, $outOfOrder);

	        	$this->csvBuffer[] = "$rcvSeq,$diff,$latency,$lost,$outOfOrder";
	        }

	        if (count($this->rcvBuffer) > 1000) {
	        	if (!file_put_contents($this->logFileRaw, implode("\n", $this->rcvBuffer)."\n", FILE_APPEND)) {
	        		echo sprintf("Error: failed to write to $this->logFileRaw\n");
	        	}

	        	$this->rcvBuffer = [];

	        	if (!file_put_contents($this->logFileCsv, implode("\n", $this->csvBuffer)."\n", FILE_APPEND)) {
	        		echo sprintf("Error: failed to write to $this->logFileCsv\n");
	        	}

	        	$this->csvBuffer = [];
	        }
	    }

	    if (!$seq) {
	    	echo "No data received\n";
	    	unlink($this->logFileRaw);
	    	unlink($this->logFileCsv);
	    } else {
	        if (count($this->rcvBuffer)) {
	        	if (!file_put_contents($this->logFileRaw, implode("\n", $this->rcvBuffer)."\n", FILE_APPEND)) {
	        		echo sprintf("Error: failed to write to $this->logFileRaw\n");
	        	}
	        }
	        
	        if (count($this->csvBuffer)) {
	        	if (!file_put_contents($this->logFileCsv, implode("\n", $this->csvBuffer)."\n", FILE_APPEND)) {
	        		echo sprintf("Error: failed to write to $this->logFileCsv\n");
	        	}
	        }

	    	echo sprintf(" - raw data saved in: %s\n - csv log in: %s\n", $this->logFileRaw, $this->logFileCsv);
	    }
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
            throw new \Exception(sprintf("Failed to bind 0.0.0.0:%d, %s". $this->port, socket_strerror($err_no)));
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

	private function printUsage()
	{
		$usage = "rtp-tester.php [options]\n\n";
		$usage.= "available options:\n";
		$usage.= " -s               run is server mode\n";
		$usage.= " -c <server_ip>   run is client mode\n";
		$usage.= " -p <port>        port number to send to or bind\n";
		$usage.= " -i <pps>         packets per second to send (default: 50)\n";
		$usage.= " -b <bs>          payload size in bytes (default: 160 Bytes)\n";

		die($usage . "\n");
	}
}