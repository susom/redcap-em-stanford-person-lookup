<?php
namespace Stanford\SPL;

include_once "classes/SPLUtils.php";

use DateTime;
use Exception;

/*
 *
 *  THIS MODULE USES A DB TABLE TO STORE THE CACHED RESULTS
 *

CREATE TABLE stanford_person_lookup_cache
(
id VARCHAR(100) PRIMARY KEY,
result TEXT DEFAULT NULL,
date_cached DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE redcap_stanford_person_lookup COMMENT = 'A cache for the Stanford Person Lookup';

*/



/**
 * Class SPL
 *
 * https://uit.stanford.edu/developers/apis/person
 *
 * @package Stanford\SPL
 */
class SPL extends \ExternalModules\AbstractExternalModule
{
    // static $api_person_url = "https://registry-uat.stanford.edu/doc/person/";
    static $api_person_url; // = "https://registry.stanford.edu/doc/person/";
    // static $cert_name;      //      = "uat-server.cert";
    // static $key_name;       //      = "mais.key";
    static $cert;      //      = "uat-server.cert";
    static $key;       //      = "mais.key";


    static $cache_dir = APP_PATH_TEMP;
    static $cache_table = "stanford_person_lookup_cache";
    static $cache_expiry;   //   = 86400; //seconds in one day
    static $cache_method;   //   = 'db'; // 'db' or 'file';

    static $config;     // Holds the ace editor settings for tokens

    // public $config;
    public $token_params;
    public $person = array();   //$first_name, $last_name, $email, $affiliation, $department, $description, $relationship;

    public $cache_result;       // Used to store results of cache lookup
    private $time_start;    // Used for logging duration of query

    public $errors = array();

    public function __construct()
    {
        parent::__construct();

        // self::log(__METHOD__);
        // Set up object
        // self::$config = json_decode($this->getConfigAsString(), true);
        self::$config = $this->buildConfigFromSettings();
        self::$api_person_url = $this->getSystemSetting('api_person_url');
        self::$cert = $this->getSystemSetting('mais_certificate');
        self::$key = $this->getSystemSetting('mais_key');
        self::$cache_method = $this->getSystemSetting('cache_method');
        self::$cache_expiry = $this->getSystemSetting('cache_expiry');

        self::log("Cache table exists?", self::cacheTableExists());
    }

    public static function cacheTableExists() {
        $result = db_result(db_query("SELECT 1 FROM " . self::$cache_table . " LIMIT 1"),0);
        return (bool) $result;
    }


    function redcap_module_configure_button_display($project_id = null) {
        ?>
            <script type="text/javascript">
                var SPL = SPL || {};
                SPL.apiUrl = <?php echo json_encode($this->getUrl('lookup.php', true, true)) ?>;
                SPL.dbTableExists = <?php echo json_encode(self::cacheTableExists()) ?>;
            </script>
            <style>
                pre {font-size: 11px;}
            </style>
        <?php
        return true;
    }

    public function redcap_module_system_enable($version) {
        self::log("Enabled at $version");
    }

    public function buildConfigFromSettings() {
        $s = $this->getSystemSettings();
        $tokens = array();
        foreach ($s['token']['value'] as $i => $token) {
            $application = $s['application']['value'][$i];
            $ip_cidr = $s['ip_cidr']['value'][$i];
            $attributes = array_map('trim', explode(",", $s['attributes']['value'][$i]));
            $override_cache_expiry_in_sec = $s['override_cache_expiry_in_sec']['value'][$i];

            $tokens[$token] = array(
                'application' => $application,
                'ip_cidr' => $ip_cidr,
                'attributes' => $attributes,
                'override_cache_expiry_in_sec' => $override_cache_expiry_in_sec
            );
        }
        return array('tokens' => $tokens);
    }






    // Validate token and write token_params
    private static function validateToken($token) {
        // Verify token is valid
        $config = self::$config;    //$this->config

        if (!isset($config['tokens'][$token])) {
            // Invalid token
            self::log("Invalid token: $token", "ERROR");
            return false;
        } else {
            // Valid token
            /*
                "application": "stanford_profile",
                "purpose": "Used by xxx for yyy",
                "ip_cidr": "127.0.0.1/32",
                "attributes": [
                    "first_name","last_name","email","affiliation",
                    "department","description","relationship"
                ],
                "override_cache_expiry_in_sec": "60"
            */
            $token_params = $config['tokens'][$token];

            // Validate IP if specified
            if (
                !empty($token_params['ip_cidr']) &&
                (SPLUtils::ipCIDRCheck($token_params['ip_cidr']) === false)
            ) {
                // Failed CIDR IP CHECK
                self::log("Lookup does not match IP filter");
                return false;
            }
            self::log("Token validated for " . $token_params['application']);
            return $token_params;
        }
    }


    /**
     * Public Lookup Function by Token
     *
     * @param $token
     * @param $id
     * @return array|bool Data or false
     */
    public function tokenLookup($token, $id) {
        // Validate Token
        $token_params = self::validateToken($token);
        $result = array();
        if ($token_params === false) {
            // Token is invalid
            self::log("Token $token Invalid");
            $result['success'] = false;
            $result['msg'] = "Invalid Token";
        } else {
            // Token is valid

            // Update expiry
            $expiry = empty($token_params['override_cache_expiry_in_sec']) ? self::$cache_expiry : intval($token_params['override_cache_expiry_in_sec']);

            // Do lookup
            $lookup = self::doLookup($id, $expiry);
            if ($lookup === false) {
                // unable to find
                $result['msg'] = "$id not found";
                $result['success'] = false;
            } else {
                // filter returned attributes
                $valid_attributes = array_flip($token_params['attributes']);
                $data = array_intersect_key(
                    $lookup,
                    $valid_attributes
                );
                $result['success'] = true;
                $result['user'] = $data;
            }
        }
        return $result;
    }


    /**
     * Method to be called when accessing SPL from another EM
     * @param $id
     * @return array|mixed
     */
    public function PersonLookup($id) {
        $result = self::doLookup($id);
        return $result;
    }


    /**
     * Internal lookup function
     * @param      $id
     * @param null $override_expiry
     * @param bool $debug
     * @return array|mixed
     */
    private static function doLookup($id, $override_expiry = null, $debug = false) {
        $time_start = microtime(true);
        $expiry = is_null($override_expiry) ? self::$cache_expiry : intval($override_expiry);

        $id = strtolower($id);

        // Try loading from cache
        $results = self::loadFromCache($id, $expiry);
        if ($results === false) {
            // Try loading from MAIS
            $results = self::loadFromMais($id);
            if ($results === false) {
                self::log("doLookup is negative for $id");
                $src = "Not Found";
            } else {
                $src = "MAIS API";
            }
        } else {
            $src = self::$cache_method . " cache";
        }

        $run_ts = round((microtime(true) - $time_start) * 1000, 3);
        self::log( "[$id]\t$src\t$run_ts ms", "INFO");
        return $results;
    }


    /**
     * Load the person by way of MAIS person API
     *
     * @param       $id
     * @param bool  $debug
     * @param array $tags (as specified by MAIS api)
     * @return mixed false or array of data
     */
    private static function loadFromMais($id, $debug = false, $tags = array('name','email','affiliation')) {
        // Build url for this service
        $url = self::$api_person_url . $id;

        // Add tags to query if specified
        if (!empty($tags)) $url .= "?tags=" . implode(",", $tags);

        // Get the XML object
        $xml = simplexml_load_string( self::curlWithCert($url) );
        if ($xml === false) {
            // Error finding person
            self::log("Unable to find $id in MAIS");
            return false;
        }

        // Get the Attributes
        $data = array(
            'sunet'        => (string) $xml['sunetid'],
            'first_name'   => (string) $xml->name[0]->first[0],
            'last_name'    => (string) $xml->name[0]->last[0],
            'email'        => (string) $xml->email[0],
            'affiliation'  => (string) $xml->affiliation[0],
            'department'   => (string) $xml->affiliation[0]->department[0],
            'description'  => (string) $xml->affiliation[0]->description[0],
            'relationship' => (string) $xml['relationship']
        );

        // Cache the person
        if (self::cachePerson($id, $data) === false) self::log("Error caching $id");

        return $data;
    }


    /**
     * Store the contents to a temp file timestamped for 1 hour refresh
     * @param $contents
     * @return string'
     */
    private static function verifyTempFile($contents) {
        $hash = sha1($contents);
        $temp_file = APP_PATH_TEMP . date('YmdH') . "0000_SPL_" . $hash;
        if  (!file_exists($temp_file)) {
            // Make the temp file
            file_put_contents($temp_file, $contents);
        }

        self::log("Temp File: " . $temp_file);
        return  $temp_file;
    }


    /**
     * Make API Call
     * @param       $sunet
     * @param array $tags
     * @return bool|mixed
     */
    private static function curlWithCert($url) {

        // Verify that cert and key files exist
        $cert_file = self::verifyTempFile(self::$cert);
        $key_file = self::verifyTempFile(self::$key);

        // Curl with SSL Certs
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_file);
        curl_setopt($ch, CURLOPT_SSLKEY, $key_file);
        curl_setopt($ch, CURLOPT_PROXY, PROXY_HOSTNAME);                    // If using a proxy
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, PROXY_USERNAME_PASSWORD);    // If using a proxy
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
        $ch_result = curl_exec($ch);
        $ch_error = curl_error($ch);
        curl_close($ch);

        if ($ch_error) {
            self::log($ch_error, "Curl in " . __METHOD__ . " failed", "ERROR");
            return false;
        }
        return $ch_result;
    }



    /**
     * Try to load the person from cache
     * @param $id
     * @param $expiry
     * @return array
     */
    private static function loadFromCache($id, $expiry) {
        if (self::$cache_method == 'db') {
            return self::loadFromDbCache($id, $expiry);
        } elseif (self::$cache_method == 'file') {
            return self::loadFromFileCache($id, $expiry);
        } else {
            self::log("Invalid cache method!");
            throw new \Exception("Invalid or missing cache method");
        }
    }


    /**
     * Try to load the person from file-based cache
     * @param $id
     * @param $expiry
     * @return mixed (false or array of data)
     */
    private static function loadFromFileCache($id, $expiry) {
        $file = self::$cache_dir . "spl_" . $id . ".json";

        if (file_exists($file)) {
            $data = json_decode( file_get_contents($file), true);

            if (!empty($data['cache_ts'])) {
                // Determine age
                $delta = strtotime("NOW") - strtotime($data['cache_ts']);

                // Check if valid
                if ($delta < $expiry) {
                    self::log("Using fileCache: $delta / $expiry seconds old");
                    return $data;
                } else {
                    self::log("fileCache expired: $delta / $expiry seconds old");
                }
            } else {
                self::log("Unable to determine cache_ts from data in $file", $data);
            }
        }
        return false;
    }


    /** Try to load the person from the db cache */
    private static function loadFromDbCache($id, $expiry) {
        $sql = sprintf(
            "select result from %s where id = '%s' AND (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(`date_cached`)) < %d;",
            self::$cache_table, db_real_escape_string($id), intval($expiry)
        );
        $result = db_result(db_query($sql),0);
        if ($result) {
            // Valid Cache Exists
            $data = json_decode($result, true);
            return $data;
        } else {
            self::log("Missing or expired db cache");
        }
        return false;
    }



    /**
     * Update the cache for this person
     * @param $id
     * @param $data
     * @return bool
     * @throws Exception
     */
    private static function cachePerson($id, $data) {
        $id = strtolower($id);

        // Add/update a cache timestamp
        $data['cache_ts'] = date("Y-m-d H:i:s");

        if (self::$cache_method == 'db') {
            return self::dbCachePerson($id, $data);
        } elseif (self::$cache_method == 'file') {
            return self::fileCachePerson($id, $data);
        } else {
            throw new \Exception("Invalid or missing cache method");
        }
    }


    /**
     * Cache to disk
     * @param $id
     * @param $data
     * @return bool
     */
    private static function fileCachePerson($id, $data) {
        // Write the file to disk
        $file = self::$cache_dir . "spl_" . $id . ".json";
        if (!file_put_contents($file, json_encode($data))) {
            self::log("Error caching to $file", "ERROR");
            return false;
        };
        return true;
    }

    /**
     * Cache to database
     * @param $id
     * @param $data
     * @return bool
     */
    private static function dbCachePerson($id, $data) {
        $date_cached =  $data['cache_ts'];
        $data = json_encode($data);
        $sql = sprintf(
            "INSERT INTO " . self::$cache_table . " (id, result, date_cached) VALUES ('%s', '%s', '%s') ".
            "ON DUPLICATE KEY UPDATE id='%s',result='%s',date_cached='%s'",
            $id, $data, $date_cached,
            $id, $data, $date_cached
        );
        $result = db_query($sql);
        if (!$result) {
            self::log("Error writing $sql",$result, "ERROR");
        }
        return true;
    }





    // CONFIG EDITOR START //
    /**
     * Read the current config from a single key-value pair in the external module settings table
     */
    function getConfigAsString() {
        global $project_id;
        if ($project_id) {
            $string_config = $this->getProjectSetting($this->PREFIX . '-config');
        } else {
            $string_config = $this->getSystemSetting($this->PREFIX . '-config');
        }
        // SurveyDashboard::log($string_config);
        return is_null($string_config) ? "" : $string_config;
    }

    /**
     * Set the current config to the redcap_exteral_modules_settings table as a single key-value pair
     * @param $string_config
     */
    function setConfigAsString($string_config) {
        global $project_id;
        if ($project_id) {
            $this->setProjectSetting($this->PREFIX . '-config', $string_config);
        } else {
            $this->setSystemSetting( $this->PREFIX . '-config', $string_config);
        }
    }

    /**
     * @return string
     */
    function getConfigDirections() {
        $msg = <<<EOT
Please enter your configuration as a valid json file.  Example syntax for each token is:
<pre>{
    "tokens": {
        "12345": {
            "application": "stanford_profile",
            "purpose": "Used by xxx for yyy",
            "ip_cidr": "127.0.0.1/32",
            "attributes": [
                "sunet","first_name","last_name","email","affiliation",
                "department","description","relationship"
            ],
            "override_cache_expiry_in_sec": "60"
        }
    }
}</pre>   
EOT;
        return $msg;
    }
    // CONFIG EDITOR END //





    // Log Wrapper
    public static function log() {
        if (!class_exists("Stanford\Utils\Log.php")) require_once "classes/StanfordUtilsLog.php";
        call_user_func_array("Stanford\Utils\Log::log", func_get_args());
    }

}