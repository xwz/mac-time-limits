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
        fwrite($this->_log, sprintf("%s [%s] %s\n", date('Y-m-d H:i:s'), $this->name, trim($msg)));
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
        if ($this->debug) {
            $verbose = $this->verbose;
            $this->verbose = false;
            echo $this->color(sprintf("%s\n", $msg), array(34));
            $this->log($msg);
            $this->verbose = $verbose;
        }
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

    public function getDailyUsage($user)
    {
        $sql = 'SELECT COUNT(*) AS minutes, day FROM usage WHERE user = :user GROUP BY day ORDER BY day ASC';
        $params = array(
            ':user' => $user,
        );
        $data = array();
        $first = null;
        $last = null;
        foreach ($this->db->queryAll($sql, $params) as $row) {
            $day = $row['day'];
            if ($first === null) {
                $first = strtotime($day);
            }
            $last = strtotime($day);
            $data[$day] = intval($row['minutes']);
        }
        $results = $this->generateDates($first, $last);
        foreach (array_keys($results) as $day) {
            if (isset($data[$day])) {
                $results[$day] = $data[$day];
            }
        }
        return $results;
    }

    protected function generateDates($begin, $end, $incr = 86400, $format = 'Y-m-d')
    {
        $dates = array();
        for ($time = $begin; $time <= $end; $time += $incr) {
            $dates[date($format, $time)] = null;
        }
        return $dates;
    }
}

class MACCommands
{
    /** @var ILogger */
    private $log;
    private $user;

    public function __construct()
    {
        $this->log = new NullLogger();
    }

    public function setLogger(ILogger $log)
    {
        $this->log = $log;
    }

    public function setUser($user)
    {
        $this->user = $user;
    }

    public function showAlert($title, $msg)
    {
        $this->showNotification($title, $msg);
        $this->say($msg);
        $this->log->warn("Show Alert: $title: $msg");
        $cmd = sprintf('osascript -e \'tell app "System Events" to display dialog "%s" with title "%s" with icon note buttons {"OK"} cancel button "OK" giving up after 5\' 2>&1', $msg, $title);
        $this->cmd($cmd);
    }

    public function showNotification($title, $msg)
    {
        $this->log->info("Show Notification: $title: $msg");
        $cmd = sprintf('osascript -e \'display notification "%s" with title "%s" sound name "Pop"\' 2>&1', $msg, $title);
        $this->cmd($cmd);
    }

    public function sleep()
    {
        $this->log->warn("Putting system to sleep.");
        $cmd = 'osascript -e \'tell app "System Events" to sleep\' 2>&1';
        $this->cmd($cmd);

    }

    public function logout()
    {
        $this->log->warn("Logout current user.");
        if ($this->user) {
            $kill = sprintf('ps aux | grep Application | grep \'^%s\' | awk \'{print $2}\' | xargs sudo -u %s kill', $this->user, $this->user);
            $result = trim(shell_exec($kill));
            $this->log->debug("Kill process $result");
        }
        $cmd = 'osascript -e \'tell application "loginwindow" to  «event aevtrlgo»\' 2>&1';
        $this->cmd($cmd);
    }

    public function say($msg)
    {
        $cmd = sprintf('say "%s"', $msg);
        $this->cmd($cmd);
    }

    public function currentUser()
    {
        $cmd = 'ls -l /dev/console';
        $result = trim(shell_exec($cmd));
        $data = explode(' ', $result);
        $this->log->debug("Found '{$data[3]}' from '$result");
        return $data[3];
    }

    public function writeStatus($msg)
    {
        if ($this->user) {
            $cmd = "echo $msg | sudo -u {$this->user} tee /Users/{$this->user}/Documents/time-limit.log";
            $result = trim(shell_exec($cmd));
        }
    }

    protected function cmd($cmd)
    {
        if ($this->user) {
            $cmd = sprintf('sudo -u %s %s', $this->user, $cmd);
        }
        $this->log->debug($cmd);
        $result = shell_exec($cmd);
        $this->log->debug($result);
        return $result;
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

    private $user;

    public function __construct($limits)
    {
        $this->limits = $limits;

        $this->log = new Logger('limit', 'time-limit');
        $this->log->debug = true;
        $this->log->verbose = true;

        $this->system = new MACCommands();
        $this->system->setLogger($this->log);
        $this->user = $this->system->currentUser();
        $this->system->setUser($this->user);

        if ($this->getLimit($this->user) !== false) {
            $file = sprintf('sqlite:%s/time-limit.db', __DIR__);
            $sqlite = new SimpleDB($file);
            $sqlite->setLogger($this->log);

            $this->time = new TimeLimitDB($sqlite);
            $this->time->setLogger($this->log);
            $this->time->init();
        }
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
        if (($limit = $this->getLimit($this->user)) !== false) {
            $this->log->info("User {$this->user} has limits: " . implode(', ', $limit));
            $this->time->addTime($this->user);
            $usage = $this->time->getUsage($this->user);
            $this->checkTimeLimit($limit, $usage);
        }
    }

    public function plot($user)
    {
        if ($this->time != null) {
            $graph = new ChartPlotter($this->time, $user);
            $graph->render();
        } else {
            $this->log->error("{$this->user} has no limits to show");
        }
    }

    protected function checkTimeLimit($limit, $usage)
    {
        list($day, $maxDuration, $expireTime) = $limit;
        $maxMinutes = round((strtotime($maxDuration) - time()) / 60);
        $expire = round((strtotime($expireTime) - time()) / 60);
        $this->showWarning($expire, $maxMinutes, $usage);
        $msg = false;
        if ($expire <= 0) {
            $msg = "Today’s limit of $expireTime has ended. System will logout in 30 seconds.";
        } else if ($usage >= $maxMinutes) {
            $msg = "Today’s usage limit of $maxDuration has expired. System will logout in 30 seconds.";
        }
        if ($msg) {
            $title = 'System Usage Time Limit';
            $this->system->showAlert($title, $msg);
            sleep(25);
            $this->system->logout();
        }
    }

    protected function showWarning($expire, $maxMinutes, $usage)
    {
        $title = 'System Usage Time Limit';
        $minutesDurationRemain = $maxMinutes - $usage;
        $msg = "Duration remaining: $minutesDurationRemain minutes. Time expires in $expire minutes.";
        $this->log->info($msg);
        $this->system->writeStatus($msg);
        $remain = min($minutesDurationRemain, $expire);
        if (($remain <= 30 && $remain > 0 && $remain % 5 == 0) || $remain == 1) {
            $this->system->showAlert($title, "{$remain} minutes remaining.");
        }
    }
}

class ChartPlotter
{
    /** @var TimeLimitDB */
    private $time;
    private $user;

    public function __construct($db, $user)
    {
        $this->time = $db;
        $this->user = $user;
    }

    protected function header()
    {
        return <<<HTML
<html>
<head>
<style>
    html, body {
        font-family: "Lucida Grande", "Lucida Sans Unicode", Arial, Helvetica, sans-serif;
    }
</style>
<script type='text/javascript' src='http://code.jquery.com/jquery-1.9.1.js'></script>
<script src="http://code.highcharts.com/highcharts.js"></script>
</head>
<body>
HTML;
    }

    protected function footer()
    {
        return <<<HTML
</body>
</html>
HTML;
    }

    protected function dailyUsage()
    {
        $usage = $this->time->getDailyUsage($this->user);
        if (count($usage) === 0) {
            return '';
        }
        $date = getdate(strtotime(key($usage)));
        $year = $date['year'];
        $month = $date['mon'] - 1;
        $day = $date['mday'];
        $data = json_encode(array_values($usage));
        return <<<HTML
    <h1>Daily Usage for {$this->user}</h1>
    <div id="daily"></div>
    <script type="text/javascript">
var daily = {
    'chart': {
        'renderTo': 'daily',
        'type': 'column'
    },
    'credits': {
        'enabled': false
    },
    'title': {
        'text': null
    },
    'xAxis': {
        'type': 'datetime'
    },
    'yAxis': {
        'title': {
            'text': 'Minutes'
        },
        'min': 0
    },
    'series': [{
        'data': $data,
        'pointStart': Date.UTC({$year}, {$month}, {$day}),
        'pointInterval': 86400000,
        'name': 'Minutes',
        'step': 'right'
    }]
};
         new Highcharts.Chart(daily);
    </script>
HTML;
    }

    public function render()
    {
        $html = $this->header();
        $html .= $this->dailyUsage();
        $html .= $this->footer();
        $file = __DIR__ . '/time.html';
        file_put_contents($file, $html);
        shell_exec("open $file&");
    }
}

$file = __DIR__ . '/limits.php';
$limits = array();
if (is_file($file)) {
    $limits = include($file);
}
$time = new TimeLimits($limits);
if (isset($argv[1])) {
    $time->plot($argv[1]);
} else {
    $time->update();
}
