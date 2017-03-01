<?php
namespace EventLoop;

class LibEventLoop implements EventLoopInterface {
    /**
     * whether the loop is running
     * @var bool
     */
    private $running;

    /**
     * the event base
     * @var resource
     */
    private $base;

    /**
     * read/write events
     * @var array
     */
    private $events;

    /**
     * signal events
     * @var array
     */
    private $signals;

    /**
     * timer events
     * @var array
     */
    private $timers;

    public function __construct() {
        $this->base = event_base_new();
    }

    /**
     * add the event to the event loop
     * @param $fd  the file descriptor. Notice: when the $flag parameter is EV_TIMER or EV_TIMER_ONCE, this paramemter means the timed task interval in seconds
     * @param $flag  the monitored event flag. the available values are listed below
     * EV_READ
     * EV_WRITE
     * EV_SIGNAL
     * EV_TIMER
     * EV_TIMER_ONCE
     * @param $callback  the callback triggered when the event occurs. this callback has three parameters.
     * $fd
     * $events
     * $args
     * @param $mixed $args  the third argument of the callback
     * @return bool
     */
    public function add($fd, $flag, $callback, $args = null) {
        switch($flag) {
            case self::EV_SIGNAL:
                $key = (int)$fd;

                $event = event_new();
                if(!event_set($event, $fd, EV_SIGNAL | EV_PERSIST, $callback, $args)) {
                    return false;
                }
                if(!event_base_set($event, $this->base)) {
                    return false;
                }
                if(!event_add($event)) {
                    return false;
                }

                $this->signals[$key] = $event;

                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $event = event_new();
                $key = (int)$event;

                if(!event_timer_set($event, array($this, 'timerCallback'), $key)) {
                    return false;
                }
                if(!event_base_set($event, $this->base)) {
                    return false;
                }
                if(!event_add($event, $fd * 1000000)) {
                    return false;
                }

                $this->timers[$key] = array($event, $fd, $flag, $callback, $args);
                break;
            case self::EV_READ:
            case self::EV_WRITE:
                $real_flag = ($flag === self::EV_READ ? EV_READ : EV_WRITE) | EV_PERSIST;

                $key = (int)$fd;

                $event = event_new();
                if(!event_set($event, $fd, $real_flag, $callback, $args)) {
                    return false;
                }
                if(!event_base_set($event, $this->base)) {
                    return false;
                }
                if(!event_add($event)) {
                    return false;
                }

                $this->events[$key][$flag] = $event;
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * cancle monitoring the event
     * @param $fd  the file descriptor. Notice: when the flag is EV_TIMER or EV_TIMER_ONCE, the $fd parameter means the event id of the timed task to be deleted
     * @param $flag  the event flag
     * @return bool
     */
    public function delete($fd, $flag) {
        switch($flag) {
            case self::EV_SIGNAL:
                $key = (int)$fd;
                if(!empty($this->signals[$key])) {
                    event_del($this->signals[$key]);
                    unset($this->signals[$key]);
                }

                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $key = (int)$fd;
                if(!empty($this->timers[$key])) {
                    event_del($this->timers[$key][0]);
                    unset($this->timers[$key]);
                }

                break;
            case self::EV_READ:
            case self::EV_WRITE:
                $key = (int)$fd;
                if(!empty($this->events[$key][$flag])) {
                    event_del($this->events[$key][$flag]);
                    unset($this->events[$key][$flag]);
                }

                if(empty($this->events[$key])) {
                    unset($this->events[$key]);
                }
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * clear all timed tasks
     */
    public function clearAllTimer() {
        foreach($this->timers as $timer) {
            event_del($timer[0]);
        }

        $this->timers = array();
    }

    /**
     * run the timer callback. when the event flag is TIMER, re-add the timer event to the event loop
     * @param $fd
     * @param $events
     * @param $key
     */
    private function timerCallback($fd, $events, $key) {
        call_user_func($this->timers[$key][3], $this->timers[$key][1], EV_TIMEOUT, $this->timers[$key][4]);

        if(!empty($this->timers[$key])) {
            if ($this->timers[$key][2] == self::EV_TIMER) {
                event_add($this->timers[$key][0], $this->timers[$key][1] * 1000000);
            } else {
                $this->delete($key, self::EV_TIMER_ONCE);
            }
        }
    }

    /**
     * run the event loop
     */
    public function run() {
        $this->running = true;

        while(1) {
            if(!$this->running) {
                break;
            }

            event_base_loop($this->base, EVLOOP_ONCE);
        }
    }

    /**
     * stop the event loop
     */
    public function stop() {
        $this->running = false;
    }
}