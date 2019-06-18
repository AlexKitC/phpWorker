<?php
/**
 * fork 子进程
 */
// $pid = pcntl_fork();
// echo "parent and children do...";
// if($pid > 0) {
//     echo "I'm parent".PHP_EOL;
// }elseif($pid == 0) {
//     echo "I'm child".PHP_EOL;
// }else {
//     echo 'fork fail'.PHP_EOL;
// }


/**
 * 安装信号
 */
// pcntl_signal(SIGINT,'signalHandler',false);
// pcntl_signal(SIGUSR2,'signalHandler',false);
// function signalHandler($signal) {
//     echo "收到信号:".$signal;
//     if($signal == SIGINT) {
//         echo "操作2"."\r\n";
//     }elseif($signal == SIGUSR2) {
//         echo "操作12"."\r\n";
//     }
// }
// while(1) {
//     sleep(1);
//     pcntl_signal_dispatch();
// }

/**
 * 进程守护
 */
// umask(0);
// $pid = pcntl_fork();
// if($pid > 0) {
//     exit(0);
// }elseif($pid == 0) {
//     if(posix_setsid() === -1) {
//         throw new Exception('setsid fail');
//     }
//     while(1) {
//         sleep(1);
//         // pcntl_signal_dispatch();
//     }
// }else {

// }

require __dir__.DIRECTORY_SEPARATOR.'Worker.php';
$worker = new Worker();
$worker -> onWorkerStart = function($worker) {
    echo 'onWorkerStart'.PHP_EOL;
};
Worker::runAll();