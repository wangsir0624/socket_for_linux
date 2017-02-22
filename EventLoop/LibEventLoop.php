<?php
namespace EventLoop;

class LibEventLoop implements EventLoopInterface {
    private $base;

    private $running;

    private $events = array();

    public function __construct() {
        $this->base = event_base_new();
    }

    private function addStream($stream, $events, $callback, $args = null) {
        $key = (int)$stream;

        $this->removeStream($stream, $events);

        $event = event_new();
        event_set($event, $stream, $events, $callback, $args);
        event_base_set($event, $this->base);
        event_add($event);

        $this->events[$key][$events] = $event;
    }

    private function removeStream($stream, $events) {
        $key = (int)$stream;

        $events |= EV_PERSIST;

        if(isset($this->events[$key][$events])) {
            event_del($this->events[$key][$events]);
        }
    }

    public function addReadStream($stream, $callback, $args = null, $once = false) {
        $key = (int)$stream;

        $events = EV_READ;
        if(!$once) {
            $events |= EV_PERSIST;
        }

        $this->addStream($stream, $events, $callback, $args);
    }

    public function addWriteStream($stream, $callback, $args = null, $once = false) {
        $key = (int)$stream;

        $events = EV_WRITE;
        if(!$once) {
            $events |= EV_PERSIST;
        }

        $this->addStream($stream, $events, $callback, $args);
    }

    public function addTimer($timeout, $callback, $args = null) {
    }

    public function removeReadStream($stream) {
        $this->removeStream($stream, EV_READ);
    }

    public function removeWriteStream($stream) {
        $this->removeStream($stream, EV_WRITE);
    }

    public function run() {
        $this->running = true;

        while(1) {
            if(!$this->running) {
                break;
            }

            event_base_loop($this->base, EVLOOP_ONCE);
        }
    }

    public function stop() {
        $this->running = false;
    }
}