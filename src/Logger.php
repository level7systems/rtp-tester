<?php
namespace Level7\RtpTester;

require_once('src/LoggerInterface.php');

class Logger implements \Level7\RtpTester\LoggerInterface
{
	private $core;
	private $logDir;
	private $logFileRaw;
	private $logFileCsv;
	private $csvReport = false;
	private $logCount = 0;

	public function __construct($argv, \Level7\RtpTester\Core $core)
	{
		$this->core = $core;
		
		$cwd = $core->getCwd();

		$this->logDir = $cwd . DIRECTORY_SEPARATOR . "log";

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

		$usage = " -csv             output stats in csv format\n";

		$core->setUsage($usage);

		if (in_array("-csv", $argv)) {
			$this->csvReport = true;
		}
	}

	public function __destruct()
	{
		if ($this->core->getMode() == \Level7\RtpTester\Core::MODE_CLIENT) {
			return;
		}

		if ($this->logCount) {
			echo sprintf(" - raw data saved in: %s\n - csv log in: %s\n", $this->logFileRaw, $this->logFileCsv);
		} else {
			echo "No data received\n";
			unlink($this->logFileRaw);
	    	unlink($this->logFileCsv);
	    }
	}

	public function logRaw($packet)
	{

	}

	public function flushRaw($buffer)
	{
		if (!$buffer) {
			return;
		}

		$this->logCount++;

    	if (!file_put_contents($this->logFileRaw, implode("\n", $buffer)."\n", FILE_APPEND)) {
    		echo sprintf("Error: failed to write to $this->logFileRaw\n");
    	}
	}

	public function logStats($data)
	{
		if ($this->csvReport) {
			echo implode(", ", $data) ."\n";
		} else {
			echo sprintf("%s (%s:%s): %d seq, time from previous %sms (%sms/%sms), latency %sms (%sms/%sms), jitter: %sms, lost %d%%, out of order: %d\n", 
	       		$data['timestamp'],
	       		$data['from_ip'],
	       		$data['from_port'],
	       		$data['seq'],
	       		$data['previous'],
	       		$data['previous_max'],
	       		$data['previous_min'],
	       		$data['latency'],
	       		$data['latency_max'],
	       		$data['latency_min'],
	       		$data['jitter'],
	       		$data['lost_percent'],
	       		$data['out_of_order']
	       	);
		}

    	if (!file_put_contents($this->logFileCsv, implode(",", $data)."\n", FILE_APPEND)) {
    		echo sprintf("Error: failed to write to $this->logFileCsv\n");
    	}
	}
}