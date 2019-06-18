<?php

class Worker {

    public static $logFile    = '';//日志文件
    public static $pidFile    = '';//保存master进程id
    public static $statusFile = '';//保存worker进程状态
    public static $daemonize  = false;//是否以守护进程方式运行 [default] false
    public static $masterPid  = 0;//master进程id
    public static $stdoutFile = '/dev/null';//标准输出重定向路径 -d参数存在时生效
    public static $workers    = [];//保存fork的所有workers
    public static $instance   = null;//worker的实例
    public $count             = 2;//默认worker数 4
    public $onWorkerStart     = null;//worker启动时的回调函数 
    public static $status     = 0;//当前进程状态

    const STATUS_RUNNING      = 1;//运行中
    const STATUS_SHUTDOWN     = 2;//停止

    /**
     * 启动全部步骤
     */
    public static function runAll() {
        static::checkEnviorment();//环境检测
        static::init();//初始化操作
        static::parseCommand();//解析运行命令
        static::daemonize();//守护进程方式运行
        static::saveMasterPid();//保存master进程id到文件中
        static::registerSignal();//注册信号处理函数，以响应stop，status动作
        static::resetStdOut();//-d参数存在时重定向标准输出
        static::forkWorkers();//fork子进程workers
        static::monitorWorkers();//监控子进程
    }

    public function __construct() {
        static::$instance = $this;
    }

    /**
     * pcntl && posix 扩展 + 运行模式 检测
     */
    public static function checkEnviorment() {
        if(!function_exists('pcntl_fork')) {
            exit('please install pcntl extend'."\r\n");
        }
        if(!function_exists('posix_kill')) {
            exit('please install posix extend'."\r\n");
        }
        if(php_sapi_name() !== 'cli') {
            exit('please run by cli mod!');
        }
    }

    /**
     * 日志路径等初始化操作
     */
    public static function init() {
        if(empty(self::$logFile)) {
            static::$logFile = 'worker.log';
        }
        if(empty(self::$pidFile)) {
            static::$pidFile = 'master.pid';
        }
        if(empty(self::$statusFile)) {
            static::$statusFile = 'statusFile.status';
        }
        static::log('init success!');
    }

    /**
     * 记录日志操作
     */
    public static function log($info) {
        $info = '['.date("Y-m-d H:i:s",time()).']'.' '.$info."\r\n";
        file_put_contents(self::$logFile,$info,FILE_APPEND | LOCK_EX);
    }

    /**
     * 启动进程的命令参数解析
     */
    public static function parseCommand() {
        global $argv;
        if(!isset($argv[1]) || !in_array($argv[1],['start','stop','status'])) {
            exit('usage: php file.php start | stop | status'.PHP_EOL);
        }
        if(!file_exists(static::$pidFile)) {
            try {
                $handle = fopen(static::$pidFile, "w");
                fclose($handle);
            } catch(Exception $e) {
                static::log($e -> getMessage());
            } catch(Error $e) {
                static::log($e -> getMessage());
            }
        }
        $masterId = file_get_contents(static::$pidFile);//从文件获取master进程id
        $masterAlive = $masterId && posix_kill($masterId,0);//判断master进程是否存活
        if($masterAlive) {//若存在，则无法重复启动
            if($argv[1] == 'start' && posix_getpid() !== $masterId) {
                exit('worker is already running!'.PHP_EOL);
            }
        }else {//未启动则只接收start参数
            if($argv[1] !== 'start') {
                exit('worker is not running at all!'.PHP_EOL);
            }
        }
        switch($argv[1]) {
            case 'start'://启动命令
                if(isset($argv[2])) {
                    if($argv[2] == '-d') {//将以守护进程方式运行
                        static::$daemonize = true;
                    }
                }
                break;
            case 'stop'://停止命令
                $masterId && posix_kill($masterId,SIGINT);
                while($masterId && posix_kill($masterId,0)) {//存在就一直杀
                    usleep(300000);
                }
                exit(0);
                break;
            case 'status'://查看状态命令
                if(file_exists(static::$statusFile)) {
                    unlink(static::$statusFile);//删除旧的状态文件
                }
                posix_kill($masterId,SIGUSR2);//向master发送信号
                usleep(300000);//等待worker进程往文件写入状态
                readfile(static::$statusFile);
                exit(0);
                break;
            default:
                exit('usage: php file.php start | stop | status'.PHP_EOL);
                break;
        }
    }

    /**
     * 守护进程运行
     */
    public static function daemonize() {
        if(self::$daemonize == false) {//不带 -d参数 debug方式运行
            return;
        }
        umask(0);
        $pid = pcntl_fork();
        if($pid > 0) {
            exit(0);
        }elseif($pid == 0) {
            if(posix_setsid() === -1) {
                throw new Exception('setsid fail');
            }
            static::setProcessTitle('alex-Worker:master');
        }else {
            throw new Exception('fork process fail');
        }
    }

    /**
     * 设置进程名称
     * @param string $title 进程名
     */
    public static function setProcessTitle($title) {
        if(function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        }
    }

    /**
     * 保存master进程id到文件中
     */
    public static function saveMasterPid() {
        static::$masterPid = posix_getpid();//获取当前master进程id
        if(file_put_contents(self::$pidFile,self::$masterPid) === false) {
            throw new Exception('faild save master pid to file [master.pid] !');
        }
    }
    
    /**
     * 注册信号
     */
    public static function registerSignal() {
        pcntl_signal(SIGINT,array(__CLASS__, 'signalHandler'),false);//2
        pcntl_signal(SIGUSR2,array(__CLASS__, 'signalHandler'),false);//12
        pcntl_signal(SIGPIPE,SIG_IGN,false);//13 1 忽略信号不做任何处理 默认使进程退出
    }

    /**
     * 注册信号的响应动作
     * @param $sigbal 信号
     */
    public static function signalHandler($signal) {
        switch($signal) {
            case SIGINT://stop
                static::stopAll();
                break;
            case SIGUSR2://show status
                static::writeStatus();
                break;
        }
    }

    /**
     * 重定向标准输出
     */
    public static function resetStdOut() {
        if(self::$daemonize == false) {
            return;
        }
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile, "a");
        if($handle) {
            unset($handle);
            fclose(STDOUT);
            fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile, "a");
            $STDERR = fopen(self::$stdoutFile, "a");
        }else {
            throw new Exception('can\'t open stdoutFile '.self::$stdoutFile);
        }
    }

    /**
     * fork 子进程workers
     */
    public static function forkWorkers() {
        $workerCount = static::$instance -> count;
        while(count(static::$workers) < $workerCount) {
            static::forkWorker(static::$instance);
        }
    }

    /**
     * fork一个worker
     */
    public static function forkWorker($instance) {
        $pid = pcntl_fork();
        if($pid > 0) {//master进程
            static::$workers[$pid] = $pid;
        }elseif($pid == 0) {
            static::setProcessTitle('alex-worker:worker');
            $instance -> run();
        }else {//fork进程失败
            throw new Exception('fork worker fail');
        }
    } 

    /**
     * 监控子进程
     */
    public static function monitorWorkers() {
        static::$status = self::STATUS_RUNNING;//设置状态为运行中
        while(1) {
            pcntl_signal_dispatch();
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);//2 等待子进程退出 //子进程已经退出并且其状态未报告时返回。
            self::log("worker {$pid} exit with signal ".pcntl_wstopsig($status));
            pcntl_signal_dispatch();
            if($pid > 0) {
                if(static::$status !== static::STATUS_SHUTDOWN) {//意外退出重新fork
                    unset(static::$workers[$pid]);
                    static::forkWorker(static::$instance);
                }
            }
        }
    }

    /**
     * 写入状态
     */
    public static function writeStatus() {
        $pid = posix_getpid();
        if($pid == static::$masterPid) {//master进程
            $masterAlive = static::$masterPid && posix_kill(static::$masterPid,0);
            $masterAlive = $masterAlive ? 'is running' : 'die';
            $result = file_put_contents(static::$statusFile,'master['.static::$masterPid.']'.$masterAlive .PHP_EOL,FILE_APPEND | LOCK_EX);
            foreach(static::$workers as $pid) {//给worker进程发信号
                posix_kill($pid,SIGUSR2);
            }
        }else {//worker进程
            $name = 'worker['.$pid.']';
            $alive = $pid && posix_kill($pid,0);
            $alive = $alive ? 'is running' : 'die';
            file_put_contents(static::$statusFile,$name.' '.$alive.PHP_EOL,FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * 停止进程
     */
    public static function stopAll() {
        $pid = posix_getpid();
        if($pid == static::$masterPid) {//master进程
            static::$status = static::STATUS_SHUTDOWN;//设置当前状态为停止，防止master重新fork
            foreach(static::$workers as $pid) {
                posix_kill($pid,SIGINT);
            }
            unlink(static::$pidFile);//删除pid文件
            exit(0);
        }else {//worker进程
            static::log('worker['.$pid.'] stop');
            exit(0);
        }
    }

    /**
     * 启动
     */
    public function run() {
        if($this -> onWorkerStart) {
            try {
                call_user_func($this -> onWorkerStart, $this);
            }catch(\Exception $e) {
                static::log($e -> getMessage());
                sleep(1);
                exit(250); 
            }catch(\Error $e) {
                static::log($e -> getMessage());
                sleep(1);
                exit(250);
            }
        }
        while(1) {
            pcntl_signal_dispatch();//有信号调用信号处理函数即可
            sleep(1);
        }
    }

}