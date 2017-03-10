<?php
namespace Server;

use RuntimeException;
use SharedMemory\SharedMemory;
use Exception;

class WorkerServer extends Worker {
    /**
     * the worker process count
     * @var int
     */
    public $wokers = 1;

    /**
     * whether run as a deamon
     * @var bool
     */
    public $deamon = true;

    /**
     * whether reborn the died worker process
     * @var bool
     */
    public $worker_reborn = true;

    /**
     * when the server runs as a deamon, the standard output will redirect into this file
     * @var string
     */
    public $std_output;

    /**
     * timezone
     * @var string
     */
    public $timezone = 'Asia/Chongqing';

    /**
     * the worker proccess ID array
     * @var array
     */
    protected $pids = array();

    /**
     * the shared memory segment
     * @var SharedMemory
     */
    public $sm;

    /**
     * the server script path
     * @var string
     */
    protected $server_script;

    /**
     * run the server
     */
    public function runAll() {
        //check the environment
        self::checkEnvironment();

        //set the timezone
        $this->setTimezone();

        //set the server script path
        $this->setScriptPath();

        //create a shared memory segment
        $this->createSharedMemory();

        //parse the command
        $this->parseCommand();
    }

    /**
     * parse the command
     */
    public function parseCommand() {
        //the arguments is too less, show the usage of the command
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
                    posix_kill($this->sm->get('pid'), SIGUSR1);

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
     * start the server
     */
    public function startServer() {
        //create the server socket stream
        $this->createSocket();

        //initialize the server runtime variables
        $this->initRuntimeVars();

        if($this->deamon) {
            $this->deamonize();
        }

        //start all workers
        $this->startAllWorkers();
    }

    /**
     * stop the server
     */
    public function stopServer() {
        //stop all workers
        $this->stopAllWorkers();

        //empty the server runtime variables
        $this->emptyRuntimeVars();

        //close the socket
        $this->closeSocket();
    }

    /**
     * print the server runtime status
     */
    public function showServerStatus() {
        $tpl = "PID: %d\r\nRuntime: %d\r\nWorkers: %d\r\nCurrent Connections: %d\r\nFailed Connections: %d\r\nTotal Connections %d\r\n";

        printf($tpl, $this->sm->get('pid'), time() - $this->sm->get('start_at'), $this->sm->get('workers'), $this->sm->get('current_connections'), $this->sm->get('failed_connections'), $this->sm->get('total_connections'));
    }

    /**
     * start all workers
     */
    public function startAllWorkers() {
        //fork all the workers
        $this->forkWorkers();

        //the master process handles the incomming signals and monitor the worker processes
        $this->handleSignals();
    }

    /**
     * fork worker processes
     */
    public function forkWorkers() {
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
    }

    /**
     * set the signal handler
     */
    public function initSignals() {
        pcntl_signal(SIGINT, array($this, 'signalHandler'));
        pcntl_signal(SIGHUP, array($this, 'signalHandler'));
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'));
        pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
    }

    /**
     * set the script path
     */
    public function setScriptPath() {
        //set the server script path
        $this->server_script = $_SERVER[argv][0];
        if(substr($this->server_script, 0, 1) != '/') {
            $filename = realpath(posix_getcwd() . '/' . $this->server_script);
        }
    }

    /**
     * set the timezone
     */
    public function setTimezone() {
        date_default_timezone_set($this->timezone);
    }

    /**
     * create a shared memory segment
     */
    public function createSharedMemory() {
        $key = ftok($this->server_script, substr($this->server_script, strlen($this->server_script)-1));

        $this->sm = new SharedMemory($key);
    }

    /**
     * release the shared memory
     */
    public function destroySharedMemory() {
        $this->sm->remove();
    }

    /**
     * initialize the server runtime variables
     */
    public function initRuntimeVars() {
        //the server master process ID
        $this->sm->set('pid', 0);

        //the server starting time
        $this->sm->set('start_at', time());

        //the worker process count
        $this->sm->set('workers', 0);

        //alive connection count
        $this->sm->set('current_connections', 0);

        //failed connection count
        $this->sm->set('failed_connections', 0);

        //the totol connection count, including the alive, the failed and the closed
        $this->sm->set('total_connections', 0);
    }

    /**
     * empty the server runtime variables
     */
    public function emptyRuntimeVars() {
        $this->sm->delete('pid');
        $this->sm->delete('start_at');
        $this->sm->delete('workers');
        $this->sm->delete('current_connections');
        $this->sm->delete('failed_connections');
        $this->sm->delete('total_connections');
    }

    /**
     * wait for the signals
     */
    public function handleSignals() {
        $this->initSignals();

        //store the master process ID in the shared memory
        $this->sm->set('pid', posix_getpid());

        //dispatch the signals
        while(1) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * signal handler
     * @param int $signal  the received signal
     */
    public function signalHandler($signal) {
        switch($signal) {
            case SIGINT:
            case SIGHUP:
                $this->stopServer();
                $this->destroySharedMemory();
                exit;
                break;
            case SIGUSR1:
                $this->stopServer();
                exit;
                break;
            case SIGCHLD:
                $pid = pcntl_wait($status, WNOHANG);

                if($pid > 0) {
                    foreach($this->pids as $key => $value) {
                        if($value == $pid) {
                            unset($this->pids[$key]);
                        }
                    }
                }

                $this->sm->decrement('workers');

                //reborn the died workers
                if($this->worker_reborn) {
                    $this->forkWorkers();
                }

                break;
        }
    }

    /**
     * stop all workers
     */
    public function stopAllWorkers() {
        foreach($this->pids as $pid) {
            echo $pid;
            exec("kill -9 $pid");
        }
    }

    /**
     * turn the master process into a deamon
     */
    public function deamonize() {
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

            $pid = pcntl_fork();

            if($pid < 0) {
                exit("pcntl_fork() failed\r\n");
            } else if($pid > 0) {
                exit(0);
            }

            //redirect the standard output to a file instead of the console
            $this->resetStd();
        }
    }

    /**
     * Redirect standard input and output
     * @throws Exception
     */
    protected function resetStd()
    {
        global $STDOUT, $STDERR;

        if($this->std_output) {
            $output = str_replace(array('{YY}', '{MM}', '{DD}'), array(date('Y'), date('m'), date('d')), $this->std_output);
        } else {
            $output = dirname($this->server_script).'/'.'output_'.date('Ymd').'.txt';
        }

        $handle = fopen($output, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen($output, "a");
            $STDERR = fopen($output, "a");
        } else {
            throw new Exception('can not open stdOutput file '.$output);
        }
    }

    /**
     * check the environment
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
     * show the usage of the server
     */
    protected static function showHelp() {
        $help = "Usage: php scriptname start|stop|restart|status\r\n";
        exit($help);
    }

    /**
     * check whether the server is running
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