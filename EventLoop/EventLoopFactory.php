<?php
namespace EventLoop;

class EventLoopFactory {
    public static function createLoop() {
        if(function_exists('event_base_new')) {
            return new LibEventLoop();
        }

        return new StreamSelectLoop();
    }
}