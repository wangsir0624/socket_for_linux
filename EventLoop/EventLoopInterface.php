<?php
namespace EventLoop;

interface EventLoopInterface {
    /**
     * Read event flag
     * @const int
     */
    const EV_READ = 1;

    /**
     * Write event flag
     * @const int
     */
    const EV_WRITE = 2;

    /**
     * Exception event flag
     * @const int
     */
    const EV_EXCEPTION = 4;

    /**
     * Signal event flag
     * @const int
     */
    const EV_SIGNAL = 8;

    /**
     * Timer event flag
     * @const int
     */
    const EV_TIMER = 16;

    /**
     * Once timer event flag
     * @const int
     */
    const EV_TIMER_ONCE = 32;

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
    function add($fd, $flag, $callback, $args = null);

    /**
     * cancle monitoring the event
     * @param $fd  the file descriptor. Notice: when the flag is EV_TIMER or EV_TIMER_ONCE, the $fd parameter means the id of the timed task to be deleted
     * @param $flag  the event flag
     * @return bool
     */
    function delete($fd, $flag);

    /**
     * clear all timed tasks
     */
    function clearAllTimer();

    /**
     * run the event loop
     */
    function run();

    /**
     * stop the event loop
     */
    function stop();
}