<?php
namespace Server;

use RuntimeException;
use SharedMemory\SharedMemory;
use Exception;

class WorkerServer extends Worker {
    /**
     * woker进程数
     * @var int
     */
    public $wokers = 1;

    /**
     * 是否以守护进程方式运行
     * @var bool
     */
    public $deamon = true;

    /**
     * woker进程ID数组
     * @var array
     */
    protected $pids = array();

    /**
     * 共享内存
     * @var SharedMemory
     */
    public $sm;

    /**
     * 运行服务器
     */
    public function runAll() {
        //检查环境
        self::checkEnvironment();

        //创建一块共享内存区域
        $this->createSharedMemory();

        //解析命令
        $this->parseCommand();
    }

    /**
     * 解析命令
     */
    public function parseCommand() {
        //命令参数过少，退出运行，并显示正确用法
        if($_SERVER['argc'] < 2) {
            self::showHelp();
        }

        switch ($_SERVER['argv'][1]) {
            case 'start':
                if($this->isRunning()) {
                    exit("The server is already running\r\n");
                }
                $this->startServer();
                break;
            case 'stop':
                if(!$this->isRunning()) {
                    exit("The server is not running\r\n");
                }

                posix_kill($this->sm->get('pid'), SIGINT);
                break;
            case 'restart':
                if($this->isRunning()) {
                    posix_kill($this->sm->get('pid'), SIGINT);

                    $this->createSharedMemory();
                    while(1) {
                        try {
                            $this->startServer();
                        } catch(Exception $e) {
                            $this->stopServer();
                            continue;
                        }

                        break;
                    }
                } else {
                    $this->startServer();
                }
                break;
            case 'status':
                if(!$this->isRunning()) {
                    exit("The server is not running\r\n");
                }

                $this->showServerStatus();
                break;
            default:
                echo "the parameters are incorrect\r\n";
                self::showHelp();
        }
    }

    /**
     * 开启服务器
     */
    public function startServer() {
        //初始化运行参数
        $this->initRuntimeVars();

        //创建服务器套接字
        $this->createSocket();

        //开启所有的woker进程
        $this->startAllWorkers();
    }

    /**
     * 关闭服务器
     */
    public function stopServer() {
        //关闭所有worker进程
        $this->stopAllWorkers();

        //关闭服务器套接字
        $this->closeSocket();

        //清空服务器运行时参数
        $this->emptyRuntimeVars();
    }

    /**
     * 打印服务器运行状态
     */
    public function showServerStatus() {
        $tpl = "PID: %d\r\nWorkers: %d\r\nCurrent Connections: %d\r\nFailed Connections: %d\r\nTotal Connections %d\r\n";

        printf($tpl, $this->sm->get('pid'), $this->sm->get('workers'), $this->sm->get('current_connections'), $this->sm->get('failed_connections'), $this->sm->get('total_connections'));
    }

    /**
     * 开启所有woker进程
     */
    public function startAllWorkers() {
        while(count($this->pids) < $this->wokers) {
            $pid = pcntl_fork();

            if($pid == -1) {
                throw new RuntimeException('pcntl_fork() failed');
            } else if($pid == 0) {
                //woker进程用来监听客户端请求
                $this->listen();
            } else {
                $this->pids[] = $pid;

                $this->sm->increment('workers');
            }
        }

        //master进程用来处理信号，以及监视woker进程的运行情况
        if($this->deamon) {
            $this->deamon(array($this, "handleSignals"));
        } else {
            $this->handleSignals();
        }
    }

    /**
     * 安装信号处理器
     */
    public function initSignals() {
        pcntl_signal(SIGINT, array($this, 'signalHandler'));
        pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
    }

    /**
     * 创建一块共享内存区域，用来存储服务器运行参数
     */
    public function createSharedMemory() {
        $key = ftok(__FILE__, substr(__FILE__, 0, 1));

        $this->sm = SharedMemory::getInstance($key);
    }

    /**
     * 释放共享内存
     */
    public function destroySharedMemory() {
        $this->sm->remove();
    }

    /**
     * 初始化服务器运行参数
     */
    public function initRuntimeVars() {
        //服务器master进程ID
        $this->sm->set('pid', 0);

        //服务器worker进程数
        $this->sm->set('workers', 0);

        //服务器目前连接数
        $this->sm->set('current_connections', 0);

        //服务器连接失败次数
        $this->sm->set('failed_connections', 0);

        //服务器处理的所有连接数，包括正在进行的，已关闭的，错误的
        $this->sm->set('total_connections', 0);
    }

    /**
     * 清空服务器运行参数
     */
    public function emptyRuntimeVars() {
        $this->sm->delete('pid');
        $this->sm->delete('workers');
        $this->sm->delete('current_connections');
        $this->sm->delete('failed_connections');
        $this->sm->delete('total_connections');
    }

    /**
     * wait for the signals
     */
    public function handleSignals() {
        //安装信号处理器
        $this->initSignals();

        //将服务器master进程ID存入到共享内存中
        $this->sm->set('pid', posix_getpid());

        //信号监听
        while(1) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * 信号处理器
     * @param int $signal  接收到的信号
     */
    public function signalHandler($signal) {
        switch($signal) {
            case SIGINT:
                $this->stopServer();
                $this->destroySharedMemory();
                exit;
                break;
            case SIGCHLD:
                $pid = pcntl_wait($status, WNOHANG);

                if($pid > 0) {
                    foreach($this->pids as $key => $value) {
                        if($value == $pid) {
                            unset($this->pid[$key]);
                        }
                    }
                }

                $this->sm->decrement('workers');

                break;
        }
    }

    /**
     * 停止所有woker进程
     */
    public function stopAllWorkers() {
        foreach($this->pids as $pid) {
            exec("kill -9 $pid");
        }
    }

    /**
     * 将master进程变成守护进程
     * @param $callback  守护进程下执行的任务
     * @param array $params  callback的参数
     */
    public function deamon($callback, $params = array()) {
        $pid = pcntl_fork();

        if($pid < 0) {
            exit("pcntl_fork() failed\r\n");
        } else if($pid > 0) {
            exit(0);
        } else {
            $sid = posix_setsid();

            if($sid < 0) {
                exit("deamon failed\r\n");
            }

            umask(0);

            call_user_func_array($callback, $params);
        }
    }

    /**
     * 检查环境是否符合运行要求
     */
    protected static function checkEnvironment() {
        if(strtolower(substr(php_sapi_name(), 0, 3)) != 'cli') {
            exit("only runs in command line mode\r\n");
        }

        if(PATH_SEPARATOR != ':') {
            exit("only runs in linux. please download the version for windows.\r\n");
        }
    }

    /**
     * 显示命令使用帮助
     */
    protected static function showHelp() {
        $help = "Usage: php scriptname start|stop|restart|status\r\n";
        exit($help);
    }

    /**
     * 检查服务是否在运行
     * @return bool
     */
    protected function isRunning() {
        $pid = $this->sm->get('pid');

        if($pid > 0) {
            return true;
        } else {
            return false;
        }
    }
}