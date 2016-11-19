# rtp-tester

This tools allows to simulate UDP RTP streams to measure network performance: jitter, packet loss and latency.

## Requirements
* php >= 5.5.17

## Installation

This tool doesn't need to be installed. Just checkout to any directory:

`git clone https://github.com/level7systems/rtp-tester.git`

and run:

`./rtp-tester.php -h`

## Usage

On receiving host run:

`./rtp-tester.php -s`

On sending host run:

`./rtp-tester.php -c <receiving_ip_address>`


Note: it is very important both sending and receiving hosts got correct time set (preferably from NTP).

## Synopsis

```
rtp-tester.php [options]

available options:
 -s               run is server mode
 -c <server_ip>   run is client mode
 -p <port>        port number to send to or bind
 -i <pps>         packets per second to send (default: 50)
 -b <bs>          payload size in bytes (default: 160 Bytes)
```

By default this tool will send 50 x 160 Bytes packets every second, which is how G711 codec behaves, you can use [this table](http://www.cisco.com/c/en/us/support/docs/voice/voice-quality/7934-bwidth-consume.html) to adjust `-i` and `-p` parameters to simulate different codecs.
