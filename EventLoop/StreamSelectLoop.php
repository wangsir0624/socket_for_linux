<?php
namespace EventLoop;

use RuntimeException;

class StreamSelectLoop implements EventLoopInterface {
    /*
     * const int
     * 每秒对应的毫秒数
     */
    const MILLISEC_PER_SEC = 1000;

    /**
     * const int
     * 每秒对应的微秒数
     */
    const MICROSEC_PER_SEC = 1000000;

    /**
     * @var bool
     * Event loop是否进行对IO缓冲区的监视
     */
    private $running;

    /**
     * @var array
     */
    private $readStreams = null;

    /**
     * @var array
     */
    private $writeStreams = null;

    /**
     * @var array
     */
    private $exceptionStreams = null;

    /**
     * @var array
     */
    private $timeout = array('sec' => null, 'micro_sec' => 0);  //单位为毫秒

    /**
     * @var array
     */
    private $readStreamCallbacks = array();

    /**
     * @var array
     */
    private $writeStreamCallbacks = array();

    /**
     * @var array
     */
    private $exceptionStreamCallbacks = array();

    /**
     * @var callable
     */
    private $timeoutCallback;

    /**
     * 添加一个流并监视其输入缓冲区
     * @param $stream  要监视的流
     * @param $callback  当输入缓冲区有数据时调用的回调函数
     * @param null $args  调用回调函数时，会传递两个参数，第一个为stream,第二个即为$args
     */
    public function addReadStream($stream, $callback, $args = null) {
        $key = (int)$stream;

        //添加要监视的流和回调函数
        $this->readStreams[$key] = $stream;
        $this->readStreamCallbacks[$key] = compact(array('callback', 'args'));
    }

    /**
     * 添加一个流并监视其输出缓冲区
     * @param $stream  要监视的流
     * @param $callback  当输出缓冲区有数据时调用的函数
     * @param null $args  调用回调函数时，会传递两个参数，第一个为stream,第二个即为$args
     */
    public function addWriteStream($stream, $callback, $args = null) {
        $key = (int)$stream;

        //添加要监视的流和回调函数
        $this->writeStreams[$key] = $stream;
        $this->writeStreamCallbacks[$key] = compact(array('callback', 'args'));
    }

    /**
     * 添加一个流并监视其输出缓冲区，如果缓冲区收到OOB数据，则触发回调函数
     * @param $stream  要监视的流
     * @param $callback  接收到OOB数据时调用的函数
     * @param null $args  调用回调函数时，会传递两个参数，第一个为stream,第二个即为$args
     */
    public function addExceptionStream($stream, $callback, $args = null) {
        $key = (int)$stream;

        //添加要监视的流和回调函数
        $this->exceptiontreams[$key] = $stream;
        $this->exceptionStreamCallbacks[$key] = compact(array('callback', 'args'));
    }

    /**
     * 设置stream_select最大时间，如果超过这个时间还没有事件发生，则会触发超时回调函数
     * @param $timeout  超时时间，单位为微秒
     * @param $callback  超时回调函数
     * @param $args  在超时回调函数调用时，此参数将作为回调函数的第一个参数
     */
    public function setTimeout($timeout, $callback = null, $args = null) {
        $this->timeout = $this->parseTimeout($timeout);

        $this->timeoutCallback = compact(array('callback', 'args'));
    }

    /**
     * 取消对流IO输入缓冲区的监视
     * @param resourse $stream
     */
    public function removeReadStream($stream) {
        $key = (int)$stream;

        if(isset($this->readStreams[$key])) {
            unset($this->readStreams[$key]);
            unset($this->readStreamCallbacks[$key]);
        }
    }

    /**
     * 取消对流IO输出缓冲区的监视
     * @param resource $stream
     */
    public function removeWriteStream($stream) {
        $key = (int)$stream;

        if(isset($this->writeStreams[$key])) {
            unset($this->writeStreams[$key]);
            unset($this->writeStreamCallbacks[$key]);
        }
    }

    /**
     * 取消对流接收OOB数据事件的监视
     * @param resource $stream
     */
    public function removeExceptionStream($stream) {
        $key = (int)$stream;

        if(isset($this->exceptionStreams[$key])) {
            unset($this->exceptionStreams[$key]);
            unset($this->exceptionStreamCallbacks[$key]);
        }
    }

    /**
     * 清除event loop的超时设置，event loop在没有接收到事件时，将会一直阻塞
     */
    public function clearTimeout() {
        $timeout = array('sec' => null, 'micro_sec' => 0);
        $this->timeoutCallback = null;
    }

    /**
     * 开始监视
     */
    public function run() {
        $this->running = true;

        while(1) {
            if(!$this->running) {
                break;
            }

            $readStreams = $this->readStreams;
            $writeStreams = $this->writeStreams;
            $exceptionStreams = $this->exceptionStreams;

            if(($num_stream_changed = stream_select($readStreams, $writeStreams, $exceptionStreams, $this->timeout['sec'], $this->timeout['micro_sec'])) === false) {
                throw new RuntimeException('stream_select() failed.');
            } else if($num_stream_changed > 0) {
                foreach((array)$readStreams as $readStream) {
                    $key = (int)$readStream;

                    call_user_func($this->readStreamCallbacks[$key]['callback'], $readStream, $this->readStreamCallbacks[$key]['args']);
                }

                foreach((array)$writeStreams as $writeStream) {
                    $key = (int)$writeStream;

                    call_user_func($this->writeStreamCallbacks[$key]['callback'], $writeStream, $this->writeStreamCallbacks[$key]['args']);
                }

                foreach((array)$exceptionStreams as $exceptionStream) {
                    $key = (int)$exceptionStream;

                    call_user_func($this->exceptionStreamCallbacks[$key]['callback'], $exceptionStream, $this->exceptionStreamCallbacks[$key]['args']);
                }
            } else {
                //超时
                if(is_callable($this->timeoutCallback['callback'])) {
                    call_user_func($this->timeoutCallback['callback'], $this->timeoutCallback['args']);
                }
            }
        }
    }

    /**
     * 停止监视
     */
    public function stop() {
        $this->running = false;
    }

    /**
     * 将毫秒单位的时间转换成需要的格式
     * @param int $timeout
     */
    private function parseTimeout($timeout) {
        $sec = ceil($timeout / self::MICROSEC_PER_SEC);
        $micro_sec = $timeout - $sec*self::MICROSEC_PER_SEC;

        return compact(array('sec', 'micro_sec'));
    }
}