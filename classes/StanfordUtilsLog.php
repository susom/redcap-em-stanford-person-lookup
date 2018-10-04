<?php
namespace Stanford\Utils;

// error_reporting(E_ALL);

/**
 * Class Log
 * A shared logging class for Stanford External Modules.  If you have a server config setting of
 * 'external_module_log_path', it will try to write the log there
 * @package Stanford\Utils
 */
class Log
{
    static $log_session_first = false;  // Adds a delimiter between each new logging session/page hit

    /**
     * A generic logging function
     */
    /*
    public static function log() {
        // Get the log path if configured
        if (!empty($GLOBALS['external_module_log_path'])) {
            $em_log_file = $GLOBALS['external_module_log_path'];
        }

        $args = func_get_args();
        $arg_count = count($args);
        // \Plugin::log($args, "$arg_count ARGS");
        $last_arg = strtoupper($args[$arg_count-1]);
        // \Plugin::log($last_arg, "LAST ARG");

        if(in_array($last_arg, array('INFO','DEBUG','ERROR'))) {
            $type = $last_arg;
            array_pop($args);
        } else {
            $type = "";
        }


        // Only log if we are in dev mode
        if(self::isDev() || $type == "ERROR" || $type == "INFO") {
            // Log it
            // ADD TRACE FOR DEBUG
            if ($type == "DEBUG") {
                $trace = self::generateCallTrace();
                $trace = "\n\t\tTRACE:\n\t\t" . implode("\n\t\t", $trace);
            } else {
                $trace = "";
            }

            // DEBUG OTHER ARGUMENTS AS VARIABLES
            $vars = array();
            foreach ($args as $i => $arg) {
                $vars[] = self::generateVariableDebug($arg);
            }

            // ADD A DELIMITER BETWEEN EACH SESSION
            if (self::$log_session_first === false) {
                self::$log_session_first = true;
                global $project_id, $record;
                $header = "-------- " . date( 'Y-m-d H:i:s' ) . " --------";
                if (!empty($project_id)) $header .= " [PID:" . $project_id . "]";
                if (!empty($record)) $header .= " [RECORD:" . $record . "]";
                $header .= "\n";
                if (!empty($_GET['prefix'])) $header .= "\t[MOD]:\t" . $_GET['prefix'] . "\n";

            } else {
                $header = "";
            }

            // Output to plugin log if defined, else use error_log
            if (!empty($em_log_file)) {

                $result = file_put_contents(
                    $em_log_file,
                    $header .
                    date( 'Y-m-d H:i:s' ) . "\t" .
                    (empty($type) ? '' : "[" . $type . "]") . "\t" . implode("\n\t", $vars) .
                    $trace . "\n"
                    ,FILE_APPEND
                );

                if ($result === false) {
                    // Output to error log since writing to the defined log file failed
                    error_log("Error writing to log file: $em_log_file");
                    error_log("\t" . implode("\n\t", $vars) . "\t" . $trace );
                }
            } else {
                error_log("\t" . implode("\n\t", $vars) . "\t" . $trace );
            }
        } else {
            // Skip Logging
        }
    }
    */

    private static function generateCallTrace()
    {
        $e = new \Exception();
        $trace = explode("\n", $e->getTraceAsString());
        // Take only the last three entries...
        // $trace = array_slice($trace,1,3);

        $length = count($trace);
        $result = array();
        for ($i = 0; $i < $length; $i++)
        {
            $result[] = ($i + 1)  . ')' . substr($trace[$i], strpos($trace[$i], ' ')); // replace '#someNum' with '$i)', set the right ordering
        }
        return $result;
    }

    private static function generateVariableDebug($obj) {
        $parsed = print_r($obj,true);
        $parsed = str_replace("\n", "\n\t\t\t", trim($parsed));
        if (is_string($obj)) {
            $msg = "[str]:\t" . $obj;
        } elseif (is_array($obj)) {
            // $msg = "[arr]:\t" . print_r($obj,true);
            $msg = "[arr]:\t" . $parsed;
        } elseif (is_object($obj)) {
            // $msg = "[obj]:\t" . print_r($obj,true);
            $msg = "[obj]:\t" . $parsed;
        } elseif (is_numeric($obj)) {
            $msg = "[num]:\t" . $obj;
        } elseif (is_bool($obj)) {
            $msg = "[bool]:\t" . ($obj ? "true" : "false");
        } else {
            $msg = "[unk]:\t" . print_r($obj,true);
        }
        return $msg;
    }

    // Checks for dev-mode EM setting
    public static function isDevMode() {
        if (!empty($_GET['id'])) {
            $prefix = \ExternalModules\ExternalModules::getPrefixForID($_GET['id']);
            $dev_mode = \ExternalModules\ExternalModules::getSystemSetting($prefix, "dev-mode");
        } else {
            $dev_mode = false;
        }
        return $dev_mode;
    }

    // defines criteria to judge someone is on a development box or not
    private static function isDev()
    {
        $is_localhost  = ( @$_SERVER['HTTP_HOST'] == 'localhost' );
        $is_dev_server = ( isset($GLOBALS['is_development_server']) && $GLOBALS['is_development_server'] == '1' );
        $is_dev = ( $is_localhost || $is_dev_server || self::isDevMode() ) ? 1 : 0;
        return $is_dev;
    }

}