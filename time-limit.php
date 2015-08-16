#!/usr/bin/env php
<?php

date_default_timezone_set('Australia/Sydney');

interface ILogger
{
    public function log($msg);

    public function debug($msg);

    public function info($msg);

    public function warn($msg);

    public function error($msg);
}

class Logger implements ILogger
{
    private $_log;
    private $_stderr;
    private $_errlog;

    private $name;
    private $file;

    public $verbose = false;
    public $debug   = false;

    public function __construct($name, $file)
    {
        $this->name = $name;
        $this->file = $file;
    }

    public function log($msg)
    {
        if ($this->_log === null) {
            $this->cleanLogs();
            $file = $this->getLogFile();
            $exists = is_file($file);
            $this->_log = fopen($file, 'a');
            if (!$exists)
                fwrite($this->_log, sprintf("Logging start: %s\n", date("r")));
        }
        fwrite($this->_log, sprintf("[%s] %s\n", $this->name, trim($msg)));
        if ($this->verbose)
            echo trim($msg) . "\n";
        return 0;
    }

    protected function cleanLogs($age = '-30 days')
    {
        $pattern = sprintf('%s-*.log', $this->file);
        foreach (glob($pattern) as $logfile) {
            if (filemtime($logfile) < strtotime($age))
                unlink($logfile);
        }
    }

    public function info($msg)
    {
        $this->log($msg);
    }

    public function warn($msg)
    {
        $verbose = $this->verbose;
        $this->verbose = false;
        if ($verbose) {
            echo $this->color("WARN: ", array(31)) . $this->color(sprintf("%s\n", $msg), array(33));
        }
        $this->log($msg);
        $this->verbose = $verbose;
    }

    public function debug($msg)
    {
        $verbose = $this->verbose;
        $this->verbose = false;
        if ($this->debug) {
            echo $this->color(sprintf("%s\n", $msg), array(34));
        }
        $this->log($msg);
        $this->verbose = $verbose;
    }

    public function error($msg)
    {
        if (is_array($msg)) {
            foreach ($msg as $str) {
                $this->error($str);
            }
            return 1;
        }
        if ($this->_errlog === null) {
            $file = $this->getErrorLogFile();
            $exists = is_file($file);
            $this->_errlog = fopen($file, 'a');
            if (!$exists)
                fwrite($this->_errlog, sprintf("Logging start: %s\n", date("r")));
        }

        $err = sprintf("ERROR: %s - %s\n", date('Y-m-d H:i:s'), trim($msg));
        if ($this->_stderr === null)
            $this->_stderr = fopen('php://stderr', 'w');
        fwrite($this->_stderr, $this->color(sprintf('[%s] %s', $this->name, $err), array(31)));
        fwrite($this->_errlog, sprintf('[%s] %s', $this->name, $err));
        $this->log(trim($err));
        return 1;
    }

    public function color($text, $codes = array())
    {
        return sprintf("\033[%sm%s\033[0m", implode(';', $codes), $text);
    }

    protected function getLogFile()
    {
        return sprintf('%s/%s-%s.log', __DIR__, $this->file, date('Ymd'));
    }

    protected function getErrorLogFile()
    {
        return sprintf('%s/%s-error-%s.log', __DIR__, $this->file, date('Ymd'));
    }
}

class NullLogger implements ILogger
{
    public function log($msg)
    {

    }

    public function debug($msg)
    {

    }

    public function info($msg)
    {

    }

    public function warn($msg)
    {

    }

    public function error($msg)
    {

    }
}

class SimpleDB
{
    private $db;

    /** @var ILogger */
    private $log;
    private $dsn;
    private $username;
    private $password;
    private $options;

    public function __construct($dsn, $username = null, $password = null, $options = array())
    {
        $this->log = new NullLogger();
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
    }

    public function setLogger(ILogger $log)
    {
        $this->log = $log;
    }

    public function getDb()
    {
        if ($this->db === null) {
            try {
                $this->db = new PDO($this->dsn, $this->username, $this->password, $this->options);
                $this->log->debug("Opening {$this->dsn}");
                $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                $this->log->error($e->getMessage());
            }
        }
        return $this->db;
    }

    protected function createCommand($sql)
    {
        return $this->getDb()->prepare($sql);
    }

    public function execute($sql, $params = array())
    {
        $this->debugQuery($sql, $params);
        $command = $this->createCommand($sql);
        return $command->execute($params);
    }

    public function queryAll($sql, $params = array())
    {
        $this->debugQuery($sql, $params);
        $command = $this->createCommand($sql);
        $command->execute($params);
        return $command->fetchAll(PDO::FETCH_ASSOC);
    }

    public function query($sql, $params = array())
    {
        $this->debugQuery($sql, $params);
        $command = $this->createCommand($sql);
        $command->execute($params);
        return $command->fetch(PDO::FETCH_ASSOC);
    }

    public function queryScalar($sql, $params = array())
    {
        $row = $this->query($sql, $params);
        if (count($row) > 0) {
            return current($row);
        }
        return false;
    }

    protected function debugQuery($sql, $params)
    {
        $keys = array_keys($params);
        $values = array_map(array($this->getDb(), 'quote'), array_values($params));
        $this->log->debug(str_replace($keys, $values, $sql));
    }
}

class TimeLimitDB
{
    /** @var SimpleDB */
    private $db;

    /** @var ILogger */
    private $log;

    public function __construct(SimpleDB $db)
    {
        $this->db = $db;
        $this->log = new NullLogger();
    }

    public function setLogger(ILogger $log)
    {
        $this->log = $log;
    }

    public function init()
    {
        $this->createTable();
    }

    protected function createTable()
    {
        $row = $this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='usage'");
        if ($row === false || count($row) === 0) {
            $sql[] = "CREATE TABLE usage(id PRIMARY KEY, user VARCHAR(20), day DATE, timestamp DATETIME)";
            $sql[] = "CREATE INDEX 'user_index' ON usage(user)";
            $sql[] = "CREATE INDEX 'day_index' ON usage(day)";
            foreach ($sql as $create) {
                $this->log->debug($create);
                $this->db->execute($create);
            }
        } else {
            $this->log->debug("Table usage exists");
        }
    }

    public function addTime($user)
    {
        $sql = 'INSERT INTO usage(user, day, timestamp) VALUES (:user, date("now", "localtime"), datetime("now", "localtime"))';
        $params = array(
            ':user' => $user,
        );
        if (!$this->db->execute($sql, $params)) {
            $this->log->error("Unable to update time for $user");
            return false;
        }
        return true;
    }

    public function getUsage($user)
    {
        $sql = 'SELECT COUNT(*) FROM usage WHERE user = :user AND day = date("now", "localtime")';
        $params = array(
            ':user' => $user,
        );
        return intval($this->db->queryScalar($sql, $params));
    }
}

class MACCommands
{
    /** @var ILogger */
    private $log;

    public function __construct()
    {
        $this->log = new NullLogger();
    }

    public function setLogger(ILogger $log)
    {
        $this->log = $log;
    }

    public function showAlert($title, $msg)
    {
        $cmd = sprintf('osascript -e \'tell app "System Events" to display dialog "%s" with title "%s" with icon note buttons {"OK"} cancel button "OK"\' &> /dev/null &', $msg, $title);
        shell_exec($cmd);
    }

    public function sleep()
    {
        $cmd = 'osascript -e \'tell app "System Events" to sleep\' &> /dev/null &';
        shell_exec($cmd);
    }

    public function logout()
    {
        $cmd = 'osascript -e \'tell application "loginwindow" to  «event aevtrlgo»\' &> /dev/null &';
        shell_exec($cmd);
    }

    public function currentUser()
    {
        $cmd = 'ls -l /dev/console';
        $result = trim(shell_exec($cmd));
        $data = explode(' ', $result);
        $this->log->debug("Found '{$data[3]}' from '$result");
        return $data[3];
    }
}

class TimeLimits
{
    /** @var TimeLimitDB */
    private $time;

    /** @var ILogger */
    private $log;

    /** @var MACCommands */
    private $system;

    private $limits = array();

    public function __construct($limits)
    {
        $this->log = new Logger('limit', 'time-limit');
        $this->log->debug = true;
        $this->log->verbose = true;

        $file = sprintf('sqlite:%s/time-limit.db', __DIR__);
        $sqlite = new SimpleDB($file);
        $sqlite->setLogger($this->log);

        $this->time = new TimeLimitDB($sqlite);
        $this->time->setLogger($this->log);
        $this->time->init();

        $this->system = new MACCommands();
        $this->system->setLogger($this->log);

        $this->limits = $limits;
    }

    protected function getLimit($user)
    {
        if (isset($this->limits[$user])) {
            $day = date('D');
            foreach ($this->limits[$user] as $limit) {
                if ($limit[0] === $day) {
                    return $limit;
                }
            }
        }
        return false;
    }

    public function update()
    {
        $user = $this->system->currentUser();
        if (($limit = $this->getLimit($user)) !== false) {
            $this->log->info("User $user has limits: " . implode(', ', $limit));
            $this->time->addTime($user);
            $usage = $this->time->getUsage($user);
            $this->checkTimeLimit($limit, $usage);
        }
    }

    protected function checkTimeLimit($limit, $usage)
    {
        list($day, $maxDuration, $expireTime) = $limit;
        $maxMinutes = round((strtotime($maxDuration) - time()) / 60);
        $expire = round((strtotime($expireTime) - time()) / 60);
        $this->showWarning($expire, $maxMinutes, $usage);
        $msg = '';
        $sleep = false;
        if ($expire <= 0) {
            $msg = "Today’s limit of $expireTime has ended. System will logout in 30 seconds.";
            $sleep = true;
            $this->log->warn($msg);
        } else if ($usage >= $maxMinutes) {
            $msg = "Today’s usage limit of $maxDuration has expired. System will logout in 30 seconds.";
            $sleep = true;
            $this->log->warn($msg);
        }
        if ($sleep) {
            $this->system->showAlert("System Usage Time Limit", $msg);
            sleep(30);
            $this->system->logout();
        }
    }

    protected function showWarning($expire, $maxMinutes, $usage)
    {
        $title = 'System Usage Time Limit';
        $minutesDurationRemain = $maxMinutes - $usage;
        $this->log->debug("Duration remaining: $minutesDurationRemain minutes. Time expires in $expire minutes.");
        $remain = min($minutesDurationRemain, $expire);
        if ($remain <= 30 && $remain > 0 && $remain % 5 === 0) {
            $this->system->showAlert($title, "{$remain} minutes remaining.");
        }
    }
}

$limits['wei'] = array(
    array('Mon', '5 hours', '9:30 pm'),
    array('Tue', '5 hours', '9:30 pm'),
    array('Wed', '5 hours', '9:30 pm'),
    array('Thu', '5 hours', '9:30 pm'),
    array('Fri', '5 hours', '9:30 pm'),
    array('Sat', '3 hours', '9:30 pm'),
    array('Sun', '3 hours', '9:30 pm'),
);

$time = new TimeLimits($limits);
$time->update();