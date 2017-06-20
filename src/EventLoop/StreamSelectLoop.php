<?php
namespace Wangjian\Socket\EventLoop;

use SplPriorityQueue;

class StreamSelectLoop implements EventLoopInterface {
    /**
     * whether the loop is running
     * @var bool
     */
    private $running;

    /**
     * all events
     * @var array
     */
    private $all_events;

    /**
     * read fds
     * @var array
     */
    private $read_fds;

    /**
     * write fds
     * @var array
     */
    private $write_fds;

    /**
     * exception fds
     * @var array
     */
    private $exception_fds;

    /**
     * the time shedule
     * @var SplPriorityQueue
     */
    private $schedule;

    /**
     * the lasted timed task id
     * @var int
     */
    private $time_id = 0;

    /**
     * the timed task array
     * @var array
     */
    private $tasks;

    /**
     * the select loop timeout in microseconds
     * @var int
     */
    private $select_timeout = 1000000;

    public function __construct() {
        $this->schedule = new SplPriorityQueue();
        $this->schedule->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * add the event to the event loop
     * @param $fd  the file descriptor. Notice: when the $flag parameter is EV_TIMER or EV_TIMER_ONCE, this paramemter means the timed task interval in seconds
     * @param $flag  the monitored event flag. the available values are listed below
     * EV_READ
     * EV_WRITE
     * EV_EXCEPTION
     * EV_SIGNAL
     * EV_TIMER
     * EV_TIMER_ONCE
     * @param $callback  the callback triggered when the event occurs. this callback has two parameters.
     * $fd
     * $args
     * @param $mixed $args  the seconde argument of the callback
     * @return bool
     */
    public function add($fd, $flag, $callback, $args = null) {
        $key = (int)$fd;

        switch ($flag) {
            case self::EV_READ:
                $this->all_events[$key][$flag] = array($callback, $fd, $args);
                $this->read_fds[$key] = $fd;

                break;
            case self::EV_WRITE:
                $this->all_events[$key][$flag] = array($callback, $fd, $args);
                $this->write_fds[$key] = $fd;

                break;
            case self::EV_EXCEPTION:
                $this->all_events[$key][$flag] = array($callback, $fd, $args);
                $this->exception_fds[$key] = $fd;

                break;
            case self::EV_SIGNAL:
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE;
                //when the flag is EV_TIMER OR EV_TIMER_ONCE, the $fd parameter means the interval instead of file descriptor

                $run_time = microtime(true) + $fd;
                $time_id = ++$this->time_id;
                $this->schedule->insert($time_id, -$run_time);
                $this->tasks[$time_id] = array($callback, $fd, $args, $flag);

                $this->tick();
                break;
            default:
                return false;
        }

        return true;
    }

    /**
     * examine whether there are tasks timed out
     */
    public function tick() {
        while(!$this->schedule->isEmpty()) {
            $incoming = $this->schedule->top();
            $time_id = $incoming['data'];
            $running_time = -$incoming['priority'];
            $now_time = microtime(true);
            $this->select_timeout = ($running_time - $now_time) * 1000000;

            if ($this->select_timeout <= 0) {
                $this->schedule->extract();

                if(!isset($this->tasks[$time_id])) {
                    continue;
                }

                $task_data = $this->tasks[$time_id];
                call_user_func($task_data[0], $task_data[1], $task_data[2]);
                if($task_data[3] == self::EV_TIMER) {
                    $running_time = $now_time + $task_data[1];
                    $this->schedule->insert($time_id, -$running_time);
                } else if($task_data[3] == self::EV_TIMER_ONCE) {
                    $this->delete($time_id, self::EV_TIMER_ONCE);
                }

                continue;
            }

            return;
        }

        //if there is no timed tasks, wu could set the stream_select() timeout longer
        $this->select_timeout = 1000000000;
    }

    /**
     * cancle monitoring the event
     * @param $fd  the file descriptor. Notice: when the flag is EV_TIMER or EV_TIMER_ONCE, the $fd parameter means the id of the timed task to be deleted
     * @param $flag  the event flag
     * @return bool
     */
    public function delete($fd, $flag) {
        $key = (int)$fd;

        switch ($flag) {
            case self::EV_READ:
                unset($this->read_fds[$key], $this->all_events[$key][$flag]);
                if(empty($this->all_events[$key])) {
                    unset($this->all_events[$key]);
                }

                break;
            case self::EV_WRITE:
                unset($this->write_fds[$key], $this->all_events[$key][$flag]);
                if(empty($this->all_events[$key])) {
                    unset($this->all_events[$key]);
                }

                break;
            case self::EV_EXCEPTION:
                unset($this->exception_fds[$key], $this->all_events[$key][$flag]);
                if(empty($this->all_events[$key])) {
                    unset($this->all_events[$key]);
                }

                break;
            case self::EV_SIGNAL:
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                unset($this->tasks[$key]);

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
        $this->schedule = new SplPriorityQueue();
        $this->schedule->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->time_id = 0;
        $this->tasks = array();
        $this->select_timeout = 1000000000;
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

            $read_fds = $this->read_fds;
            $write_fds = $this->write_fds;
            $exception_fds = $this->exception_fds;

            @stream_select($read_fds, $write_fds, $exception_fds, 0, $this->select_timeout);

            if(!$this->schedule->isEmpty()) {
                $this->tick();
            }

            if(!empty($read_fds)) {
                foreach($read_fds as $fd) {
                    $event = $this->all_events[(int)$fd][self::EV_READ];

                    call_user_func($event[0], $event[1], $event[2]);
                }
            }

            if(!empty($write_fds)) {
                foreach($write_fds as $fd) {
                    $event = $this->all_events[(int)$fd][self::EV_WRITE];

                    call_user_func($event[0], $event[1], $event[2]);
                }
            }

            if(!empty($exception_fds)) {
                foreach($exception_fds as $fd) {
                    $event = $this->all_events[(int)$fd][self::EV_EXCEPTION];

                    call_user_func($event[0], $event[1], $event[2]);
                }
            }
        }
    }

    /**
     * stop the event loop
     */
    public function stop() {
        $this->running = false;
    }
}