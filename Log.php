<?php

class Log {
    public static $LEVEL = 0;
    private static $instance;
    private $dir;
    private $filename;

    const LEVEL_INFO = 0;
    const LEVEL_DEBUG = 1;
    const LEVEL_WARN = 2;
    const LEVEL_ERR = 3;
    const LOG_LEVEL = [
        'info' => self::LEVEL_INFO,
        'debug' => self::LEVEL_DEBUG,
        'warn' => self::LEVEL_WARN,
        'err' => self::LEVEL_ERR,
    ];

    public function __construct($filename){
        $this->dir = __DIR__.'/log/';
        if(!file_exists($this->dir)){
            mkdir($this->dir);
        }
        $this->filename = $filename;
    }

    public static function getInstance($filename){
        if(!isset(self::$instance[$filename])){
            self::$instance[$filename] = new Log($filename);
        }
        return self::$instance[$filename];
    }

    public function write($message, $level){
        if(!isset(self::LOG_LEVEL[$level])){
            return;
        }
        $levelint = self::LOG_LEVEL[$level];
        if($levelint < self::$LEVEL){
            return;
        }
        $filename = $this->dir.$this->filename.'-'.date('YmdH').'.log';
        return file_put_contents($filename, date('Y-m-d H:i:s')."\t".$level."\t".$message.PHP_EOL, LOCK_EX|FILE_APPEND);
    }

}
