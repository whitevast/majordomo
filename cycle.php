<?php
/**
 * Timer Cycle script
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <jey@tut.by> http://smartliving.ru/
 * @version 1.4
 */

chdir(dirname(__FILE__));

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");
include_once("./load_settings.php");

resetRebootRequired();

set_time_limit(0);

$db_filename = ROOT . 'database_backup/db.sql';
if (file_exists($db_filename) && filesize($db_filename) == 0 && file_exists($db_filename . '.prev') && filesize($db_filename . '.prev') > 0) {
    DebMes("Main backup file is empty, restoring from previous: " . $db_filename . '.prev', 'boot');
    $db_filename = $db_filename . '.prev';
}

$db_history_filename = ROOT . 'database_backup/db_history.sql';
if (file_exists($db_history_filename) && filesize($db_history_filename) == 0 && file_exists($db_history_filename . '.prev') && filesize($db_history_filename . '.prev') > 0) {
    DebMes("Main history backup file is empty, restoring from previous: " . $db_history_filename . '.prev', 'boot');
    $db_history_filename = $db_history_filename . '.prev';
}

$connected = false;
$total_restarts = 0;
while (!$connected) {
    echo "Connecting to database..." . PHP_EOL;
    $connected = $db->Connect();
    if (!$connected) {
        if (file_exists($db_filename) && !IsWindowsOS() && $total_restarts < 3) {
            echo "Restarting mysql service..." . PHP_EOL;
            DebMes('Restarting mysql service...', 'boot');
            exec("sudo service mysql restart"); // trying to restart mysql
            $total_restarts++;
            sleep(10);
        } else {
            sleep(5);
        }
    }
}


echo "CONNECTED TO DB" . PHP_EOL;

//если есть "поломанные" таблицы, попытаться их "вылечить"
echo "CHECK/REPAIR TABLES\n";
$tables = SQLSelect("select TABLE_NAME Tbl from information_schema.tables where TABLE_SCHEMA='" . DB_NAME . "' AND ENGINE !='MEMORY';");
$total = count($tables);
$checked = 0;
$broken = 0;
$repaired = 0;
$fatal = 0;
for ($i = 0; $i < $total; $i++) {
    $table = $tables[$i]['Tbl'];

    //echo 'Checking table [' . $table . '] ...';
    $result = SQLSelectOne("CHECK TABLE " . $table . ";");
    if ($result['Msg_text'] == 'OK') {
        //echo "OK\n";
        $checked = $checked + 1;
    } else {
        echo "Checking table [" . $table . "]... broken ... try to repair ...";
        DebMes("Checking table [" . $table . "]... broken ... try to repair ...", 'boot');
        $broken = $broken + 1;
        SQLExec("REPAIR TABLE " . $table . ";");
        sleep(10);
        $result = SQLSelectOne("CHECK TABLE " . $table . ";");
        if ($result['Msg_text'] == 'OK') {
            echo "OK\n";
            $repaired = $repaired + 1;
        } else {
            echo "try to repair extended...";
            SQLExec("REPAIR TABLE " . $table . " EXTENDED;");
            sleep(10);
            $result = SQLSelectOne("CHECK TABLE " . $table . ";");
            if ($result['Msg_text'] == 'OK') {
                echo "OK\n";
                $repaired = $repaired + 1;
            } else {
                echo "try to repair use_frm...";
                SQLExec("REPAIR TABLE " . $table . " USE_FRM;");
                sleep(10);
                $result = SQLSelectOne("CHECK TABLE " . $table . ";");
                if ($result['Msg_text'] == 'OK') {
                    echo "OK\n";
                    $repaired = $repaired + 1;
                } else {
                    echo "NO RESULT(...try restore from backup\n";
                    $fatal = $fatal + 1;
                }
            }
        }
    }
}
echo "CHECK/REPAIR TABLES RESULT -> Total: " . $total . ", checked Ok: " . $checked . ", broken: " . $broken . ", repaired: " . $repaired . ", FATAL Errors : " . $fatal;
echo "\n";

// создаем табличку cyclesRun, если её нет
SQLExec('CREATE TABLE IF NOT EXISTS `cached_cycles` (`TITLE` char(100) NOT NULL,`VALUE` char(255) NOT NULL,PRIMARY KEY (`TITLE`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
SQLExec('DROP TABLE IF EXISTS cyclesRun;');


$old_mask = umask(0);
if (is_dir(ROOT . 'cached')) {
    DebMes("Removing cache from " . ROOT . 'cached', 'boot');
    removeTree(ROOT . 'cached');
}
if (is_dir(ROOT . 'cms/cached')) {
    DebMes("Removing cache from " . ROOT . 'cms/cached', 'boot');
    removeTree(ROOT . 'cms/cached');
}

// moving some folders to ./cms/
$move_folders = array(
    'debmes',
    'saverestore',
    'sounds',
    'texts');
foreach ($move_folders as $folder) {
    if (is_dir(ROOT . $folder)) {
        echo "Moving " . ROOT . $folder . ' to ' . ROOT . 'cms/' . $folder . "\n";
        DebMes('Moving ' . ROOT . $folder . ' to ' . ROOT . 'cms/' . $folder, 'boot');
        copyTree(ROOT . $folder, ROOT . 'cms/' . $folder);
        removeTree(ROOT . $folder);
    }
}

// removing some 3rd-party directories
$check_folders = array(
    'blockly' => '3rdparty/blockly',
    'bootstrap' => '3rdparty/bootstrap',
    'js/codemirror' => '3rdparty/codemirror',
    'freeboard' => '3rdparty/freeboard',
    'jquerymobile' => '3rdparty/jquerymobile',
    'jpgraph' => '3rdparty/jpgraph',
    'js/threejs' => '3rdparty/threejs',
    'pdw' => '3rdparty',
    '3rdparty/pdw' => '3rdparty',
);
foreach ($check_folders as $k => $v) {
    if (is_dir(ROOT . $v) && is_dir(ROOT . $k)) {
        echo "Removing " . ROOT . $k . "\n";
        DebMes('Removing ' . ROOT . $k, 'boot');
        removeTree(ROOT . $k);
    }
}


// check/recreate folders
$dirs_to_check = array(
    ROOT . 'backup',
    ROOT . 'cms/debmes',
    ROOT . 'cms/cached',
    ROOT . 'cms/cached/voice',
    ROOT . 'cms/cached/urls',
    ROOT . 'cms/cached/templates_c',
);

if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
    $path = SETTINGS_SYSTEM_DEBMES_PATH;
} elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
    $path = LOG_DIRECTORY;
} else {
    $path = ROOT . 'cms/debmes';
}
$dirs_to_check[] = $path;

if (defined('SETTINGS_BACKUP_PATH') && SETTINGS_BACKUP_PATH != '') {
    $dirs_to_check[] = SETTINGS_BACKUP_PATH;
}


foreach ($dirs_to_check as $d) {
    if (!is_dir($d)) {
        DebMes("Creating dir " . $d, 'boot');
        mkdir($d, 0777);
    } else {
        chmod($d, 0777);
    }
}


//restoring database backup (if was saving periodically)
if (file_exists($db_filename) && filesize($db_filename) > 0) {
    echo "Running: mysql main db restore from file: " . $db_filename . PHP_EOL;
    DebMes("Running: mysql main db restore from file: " . $db_filename, 'boot');
    $mysql_path = (substr(php_uname(), 0, 7) == "Windows") ? SERVER_ROOT . "/server/mysql/bin/mysql" : 'mysql';
    $mysqlParam = " -h " . DB_HOST;
    $mysqlParam .= " -u " . DB_USER;
    if (DB_PASSWORD != '') $mysqlParam .= " -p'" . DB_PASSWORD . "'";
    $mysqlParam .= " " . DB_NAME . " <" . $db_filename;
    exec($mysql_path . $mysqlParam);

    if (file_exists($db_history_filename) && filesize($db_history_filename) > 0) {
        echo "Running: mysql history db restore from file: " . $db_history_filename . PHP_EOL;
        DebMes("Running: mysql history db restore from file: " . $db_history_filename, 'boot');
        $mysql_path = (substr(php_uname(), 0, 7) == "Windows") ? SERVER_ROOT . "/server/mysql/bin/mysql" : 'mysql';
        $mysqlParam = " -h " . DB_HOST;
        $mysqlParam .= " -u " . DB_USER;
        if (DB_PASSWORD != '') $mysqlParam .= " -p'" . DB_PASSWORD . "'";
        $mysqlParam .= " " . DB_NAME . " <" . $db_history_filename;
        exec($mysql_path . $mysqlParam);
    } else {
        echo "History backup file not found or invalid: " . $db_history_filename . PHP_EOL;
        DebMes("History backup file not found or invalid: " . $db_history_filename, 'boot');
    }
} else {
    echo "Backup file not found or invalid: " . $db_filename . PHP_EOL;
    DebMes("Backup file not found or invalid: " . $db_filename, 'boot');
}


include_once("./load_settings.php"); //

echo "Checking modules.\n";

//force check installed data
$source = ROOT . 'modules';
if ($dir = @opendir($source)) {
    while (($file = readdir($dir)) !== false) {
        $installed_file = ROOT . "cms/modules_installed/" . $file . ".installed";
        if (Is_Dir($source . "/" . $file) && ($file != '.') && ($file != '..') && is_file($installed_file)) {
            DebMes("Re-installing module: " . $file, 'boot');
            unlink($installed_file);
        }
    }
}

if (is_file(ROOT . "cms/modules_installed/control_modules.installed")) {
    DebMes("Re-installing control_modules: " . $file, 'boot');
    unlink(ROOT . "cms/modules_installed/control_modules.installed");
}

// continue startup
include_once(DIR_MODULES . "control_modules/control_modules.class.php");
$ctl = new control_modules();


//removing cached data
echo "Clearing the cache.\n";
DebMes("Clearing the cache.", 'boot');
clearCacheData();

if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
    // split data into multiple tables
    $phistory_values = SQLSelect("SELECT VALUE_ID, COUNT(*) AS TOTAL FROM phistory GROUP BY VALUE_ID");
    $total = count($phistory_values);
    for ($i = 0; $i < $total; $i++) {
        $value_id = $phistory_values[$i]['VALUE_ID'];
        $total_data = $phistory_values[$i]['TOTAL'];
        DebMes("Processing data for value $value_id ($total_data) ... ", 'boot');
        echo "Processing data for value $value_id ($total_data) ... ";
        $table_name = createHistoryTable($value_id);
        moveDataFromMainHistoryToTable($value_id);
        DebMes("Processing of $value_id finished.", 'boot');
        echo "OK\n";
    }
} else {
    //combine data into single table
    $data = SQLSelect("SHOW TABLES;");
    $tables = array();
    foreach ($data as $v) {
        foreach ($v as $k => $v2) {
            $tables[] = $v2;
        }
    }
    foreach ($tables as $table) {
        if (preg_match('/phistory_value_(\d+)/', $table, $m)) {
            $value_id = $m[1];
            echo "Processing table: $table ($value_id) ...\n";
            DebMes("Processing data for value $value_id ($table) ... ", 'boot');
            moveDataFromTableToMainHistory($value_id);
            DebMes("Processing of $value_id finished.", 'boot');
            echo "OK\n";
        }
    }
}

// Removing cycles properties
$qry = "1 AND (TITLE LIKE 'cycle%Run' OR TITLE LIKE 'cycle%Control' OR TITLE LIKE 'cycle%Disabled' OR TITLE LIKE 'cycle%AutoRestart')";
$thisCompObject = getObject('ThisComputer');
$cycles_records = SQLSelect("SELECT properties.* FROM properties WHERE $qry ORDER BY TITLE");
SQLExec("DELETE FROM cached_cycles");

$total = count($cycles_records);
for ($i = 0; $i < $total; $i++) {
    $property = $cycles_records[$i]['TITLE'];
    echo "Removing ThisComputer.$property (object " . $thisCompObject->id . ")";
    DebMes("Removing property ThisComputer.$property (object " . $thisCompObject->id . ")", 'boot');
    $property_id = $thisCompObject->getPropertyByName($property, $thisCompObject->class_id, $thisCompObject->id);
    if ($property_id) {
        $sqlQuery = "SELECT ID FROM pvalues WHERE PROPERTY_ID = " . (int)$property_id;
        $pvalue = SQLSelectOne($sqlQuery);
        if ($pvalue['ID']) {
            DebMes("Deleting Pvalue: " . $pvalue['ID'], 'boot');
            cleanUpValueHistory($pvalue['ID'], 0);
            SQLExec("DELETE FROM pvalues WHERE ID=" . $pvalue['ID']);
        } else {
            DebMes("NO Pvalue for " . $property_id, 'boot');
        }
        SQLExec("DELETE FROM properties WHERE ID=" . $property_id);
        DebMes("REMOVED $property_id", 'boot');
        echo " REMOVED $property_id\n";
    } else {
        DebMes("No property record found for $property", 'boot');
        echo " FAILED\n";
    }
}
clearCacheData();

// getting list of /scripts/cycle_*.php files to run each in separate thread
$cycles = array();
$reboot_timer = 0;

if (is_dir("./scripts")) {

    if (file_exists('./scripts/cycle_periodical_db_save.php') && file_exists('./scripts/periodical_db_save.php')) {
        copyFile('./scripts/periodical_db_save.php', './scripts/cycle_periodical_db_save.php');
    }
    if (file_exists('./scripts/cycle_db_save.php') && file_exists('./scripts/periodical_db_save.php')) {
        copyFile('./scripts/periodical_db_save.php', './scripts/cycle_db_save.php');
    }

    if ($lib_dir = opendir("./scripts")) {
        while (($lib_file = readdir($lib_dir)) !== false) {
            if ((preg_match("/^cycle_.+?\.php$/", $lib_file)))
                $cycles[] = './scripts/' . $lib_file;
        }
        closedir($lib_dir);
    }
}

$threads = new Threads;

if (defined('PATH_TO_PHP'))
    $threads->phpPath = PATH_TO_PHP;
else
    $threads->phpPath = IsWindowsOS() ? '..\server\php\php.exe' : 'php';

foreach ($cycles as $path) {

    if (file_exists($path)) {

        if (preg_match('/(cycle_.+?)\.php/is', $path, $m)) {
            $title = $m[1];
            if (getGlobal($title . 'Disabled')) {
                DebMes("Cycle " . $title . " disabled. Skipping.", 'boot');
                continue;
            }
            if (getGlobal($title . 'Control') != '') {
                setGlobal($title . 'Control', '');
            }
        }


        DebMes("Starting " . $path . " ... ", 'boot');
        echo "Starting " . $path . " ... \n";

        if ((preg_match("/_X/", $path))) {
            if (!IsWindowsOS()) {
                $display = '101';
                if ((preg_match("/_X(.+)_/", $path, $displays))) {
                    if (count($displays) > 1) {
                        $display = $displays[1];
                    }
                }
                $pipe_id = $threads->newXThread($path, $display);
            }
        } else {
            $pipe_id = $threads->newThread($path);
        }
        $pipes[$pipe_id] = $path;
        echo "OK" . PHP_EOL;
    }
}

DebMes("ALL CYCLES STARTED", 'boot');
echo "ALL CYCLES STARTED" . PHP_EOL;

$last_restart = array();

$last_cycles_control_check = time();

$auto_restarts = array();
$to_start = array();
$to_stop = array();
$started_when = array();

$thisComputerObject = getObject('Computer.ThisComputer');

while (false !== ($result = $threads->iteration())) {

    if ((time() - $last_cycles_control_check) >= 5 || !empty($result)) {

        $last_cycles_control_check = time();
        $cyclesControls = $cyclesTimestamps = array();
        $tmpcyclesTimestamps = SQLSelect("SELECT * FROM cached_cycles;");

        $total = count($tmpcyclesTimestamps);
        foreach ($tmpcyclesTimestamps as $k => $v) {
            if (strpos($v['TITLE'], 'Run') !== FALSE) {
                $cyclesTimestamps[$v['TITLE']] = $v['VALUE'];
            } else if (strpos($v['TITLE'], 'Control') !== FALSE) {
                $cyclesControls[$v['TITLE']] = $v['VALUE'];
            }
        }

        $seen = array();
        for ($i = 0; $i < $total; $i++) {
            $title = $tmpcyclesTimestamps[$i]['TITLE'];
            $title = preg_replace('/Run$/', '', $title);
            $title = preg_replace('/Control$/', '', $title);
            if (isset($seen[$title])) {
                continue;
            }
            $seen[$title] = 1;
            $control = '';

            if (isset($cyclesControls[$title . 'Control'])) $control = $cyclesControls[$title . 'Control'];
            if ($control != '') {
                DebMes("Got control command '$control' for " . $title, 'boot');
                if ($control == 'stop') {
                    $to_stop[$title] = time();
                } elseif ($control == 'restart' || $control == 'start') {
                    $to_stop[$title] = time();
                    $to_start[$title] = time() + 30;
                }
                setGlobal($title . 'Control', '');
            }

        }

        $is_running = array();

        foreach ($threads->commandLines as $id => $cmd) {
            if (preg_match('/(cycle_.+?)\.php/is', $cmd, $m)) {
                $title = $m[1];
                $is_running[$title] = $id;
                if (!isset($started_when[$title])) $started_when[$title] = time();
                if ((time() - $started_when[$title]) > 30 && !in_array($title, $auto_restarts)) {
                    DebMes("Adding $title to auto-recovery list", 'boot');
                    $auto_restarts[] = $title;
                }
                $cycle_updated_timestamp = $cyclesTimestamps[$title . 'Run'];

                if (!isset($to_start[$title]) && $cycle_updated_timestamp && in_array($title, $auto_restarts) && ((time() - $cycle_updated_timestamp) > 30 * 60)) { //
                    DebMes("Looks like $title is dead (updated: " . date('Y-m-d H:i:s', $cycle_updated_timestamp) . "). Need to recovery", 'boot');
                    registerError('cycle_hang', $title);
                    setGlobal($title . 'Control', 'restart');
                }
            }
        }
    }


    if (isRebootRequired()) {
        if (!$reboot_timer) {
            $reboot_timer = time();
        } elseif ((time() - $reboot_timer) > 10) {
            $reboot_timer = 0;
            //force close all running threads
            DebMes("Force closing all running services.", 'boot');
            $to_start = array();
            $restart_threads = array();
            foreach ($is_running as $k => $v) {
                $to_stop[$k] = time();
            }
        }
    }

    foreach ($to_stop as $title => $tm) {

        $key = array_search($title, $auto_restarts);
        if ($key !== false) {
            unset($auto_restarts[$key]);
            $auto_restarts = array_values($auto_restarts);
        }

        if ($tm <= time()) {
            if (isset($is_running[$title])) {
                $id = $is_running[$title];
                DebMes("Force closing service " . $title . " (id: " . $id . ")", 'boot');
                $threads->closeThread($id);
            }
            unset($to_stop[$title]);
        }
    }

    foreach ($to_start as $title => $tm) {
        if ($tm <= time()) {
            if (!isset($is_running[$title])) {
                $cmd = './scripts/' . $title . '.php';
                DebMes("Starting service " . $title . ' (' . $cmd . ')', 'boot');
                $pipe_id = $threads->newThread($cmd);
                $is_running[$title] = $pipe_id;
                $started_when[$title] = time();
            } else {
                DebMes("Got to_start command for " . $title . ' but looks like it is already running', 'boot');
            }
            unset($to_stop[$title]);
            unset($to_start[$title]);
        }
    }

    if (!empty($result)) {
        $closePattern = '/THREAD CLOSED:.+?(\.\/scripts\/cycle\_.+?\.php)/is';
        if (preg_match_all($closePattern, $result, $matches) && !isRebootRequired()) {
            $total_m = count($matches[1]);
            for ($im = 0; $im < $total_m; $im++) {
                $closed_thread = $matches[1][$im];
                $cycle_title = '';
                $need_restart = 0;
                if (preg_match('/(cycle_.+?)\.php/is', $closed_thread, $m)) {
                    $cycle_title = $m[1];
                    DebMes("Thread closed: " . $cycle_title, 'boot');
                    unset($to_stop[$cycle_title]);
                    setGlobal($cycle_title . 'Run', '');
                    $key = array_search($cycle_title, $auto_restarts);
                    if ($key !== false) {
                        unset($auto_restarts[$key]);
                        $auto_restarts = array_values($auto_restarts);
                        $need_restart = 1;
                    } elseif (isset($to_start[$cycle_title])) {
                        $need_restart = 1;
                    }
                }
                if ($need_restart && $cycle_title) {
                    if (!isset($to_start[$cycle_title])) {
                        DebMes("AUTO-RECOVERY: " . $closed_thread, 'boot');
                        if (!preg_match('/websockets/is', $closed_thread) && !preg_match('/connect/is', $closed_thread)) {
                            registerError('cycle_stop', $closed_thread . "\n" . $result);
                        }
                        $to_start[$cycle_title] = time() + 2;
                    }
                    $started_when[$cycle_title] = $to_start[$cycle_title];
                }
            }
        }
    }
}

resetRebootRequired();

