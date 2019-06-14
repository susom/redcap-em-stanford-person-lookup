<?php
namespace Stanford\SPL;

include_once "classes/SPLUtils.php";

include_once "emLoggerTrait.php";

use DateTime;
use Exception;


/**
 * Class SPL
 *
 * https://uit.stanford.edu/developers/apis/person
 *
 * @package Stanford\SPL
 */
class SPL extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    // static $api_person_url  =
    static $api_person_url; // = "https://registry.stanford.edu/doc/person/" or "https://registry-uat.stanford.edu/doc/person/";
    static $cert;           // = "text from uat-server.cert";
    static $key;            // = "text from mais.key";

    static $cache_dir = APP_PATH_TEMP;
    static $cache_table = "stanford_person_lookup_cache";
    static $cache_expiry = 86400;   //seconds in one day
    static $cache_method;   //   = 'db'; // 'db' or 'file';
    static $config;         // Holds the API token settings

    public $token_params;
    public $person = array();   //$first_name, $last_name, $email, $affiliation, $department, $description, $relationship;

    public $cache_result;   // Used to store results of cache lookup

    public $errors = array();

    public function __construct()
    {
        parent::__construct();
    }


    /**
     * Method to be called when accessing SPL from another EM
     * @param $id
     * @return array|mixed
     */
    public function personLookup($id) {

        $this->setup();
        $result = self::doLookup($id);
        return $result;
    }


    /**
     * Public Lookup Function by Token
     *
     * @param $token
     * @param $id
     * @return array|bool Data or false
     */
    public function tokenLookup($token, $id) {
        global $module;

        // Set up module
        $this->setup();

        // Validate Token
        $token_params = self::validateToken($token);
        $result = array();
        if ($token_params === false) {
            // Token is invalid
            $module->emError("Token $token Invalid");
            $result['success'] = false;
            $result['msg'] = "Invalid Token";
        } else {
            // Token is valid

            // Update expiry
            $expiry = empty($token_params['override_cache_expiry_in_sec']) ? self::$cache_expiry : intval($token_params['override_cache_expiry_in_sec']);

            // Do lookup
            $lookup = self::doLookup($id, $expiry);
            if ($lookup["success"] === false) {
                // unable to find
                $result = $lookup;
           } else {
                // filter returned attributes
                $valid_attributes = array_flip($token_params['attributes']);
                $data = array_intersect_key(
                    $lookup["user"],
                    $valid_attributes
                );
                $result['success'] = true;
                $result['user'] = $data;
            }
        }
       return $result;
    }


    /**
     * Initialize the object - taken out of the constructor to reduce overhead
     */
    private function setup() {
        // Set up object
        self::$config = $this->buildConfigFromSettings();
        self::$api_person_url = $this->getSystemSetting('api_person_url');
        self::$cert = $this->getSystemSetting('mais_certificate');
        self::$key = $this->getSystemSetting('mais_key');
        self::$cache_method = $this->getSystemSetting('cache_method');
        self::$cache_expiry = $this->getSystemSetting('cache_expiry');
    }

    /**
     * Check to verify the cache table exists
     * @return bool
     */
    public static function cacheTableExists() {
        $result = db_result(db_query("SELECT 1 FROM " . self::$cache_table . " LIMIT 1"),0);
        return (bool) $result;
    }


    /**
     * Add some context to the config page to help the user-interface
     * @param null $project_id
     * @return bool
     */
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


    /**
     * Load the repeating token data into an array of the format originally used by the ACE editor version
     * @return array
     */
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


    /**
     * Validate that the provided token is valid
     * @param $token
     * @return bool
     */
    private static function validateToken($token) {
        global $module;
        // Verify token is valid
        $config = self::$config;

        if (!isset($config['tokens'][$token])) {
            // Invalid token
            $module->emError("Invalid token: $token", "ERROR");
            return false;
        } else {
            // Valid token
            /*
                "application": "stanford_profile",
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
                $module->emError("Lookup does not match IP filter");
                return false;
            }
            $module->emLog("Token validated for " . $token_params['application']);
            return $token_params;
        }
    }


    /**
     * Internal lookup function
     * @param      $id
     * @param null $override_expiry
     * @param bool $debug
     * @return array|mixed
     * @throws Exception
     */
    private static function doLookup($id, $override_expiry = null, $debug = false) {
        global $module;
        $time_start = microtime(true);
        $expiry = is_null($override_expiry) ? self::$cache_expiry : intval($override_expiry);

        $id = strtolower($id);

        // Try loading from cache
        $results = self::loadFromCache($id, $expiry);
        if ($results === false) {
            // Try loading from MAIS
            $results = self::loadFromMais($id);
            if ($results === false) {
                $module->emError("doLookup is negative for $id");
                $src = "Not Found";
                $return = array("success" => false,
                                "msg" => "doLookup is negative for $id  - $src");
            } else {
                $src = "MAIS API";
                $return = array("success" => true,
                                "user" => $results);
            }
        } else {
            $src = self::$cache_method . " cache";
            $return = array("success" => true,
                            "user" => $results);
        }

        $run_ts = round((microtime(true) - $time_start) * 1000, 3);
        $module->emLog( "[$id]\t$src\t$run_ts ms", "INFO");
        return $return;
    }


    /**
     * Load the person by way of MAIS person API
     * @param       $id
     * @param bool  $debug
     * @param array $tags (as specified by MAIS api)
     * @return mixed false or array of data
     * @throws Exception
     */
    private static function loadFromMais($id, $debug = false, $tags = array('name','email','affiliation','telephone')) {
        global $module;

        // Build url for this service
        $url = self::$api_person_url . $id;

        // Add tags to query if specified
        if (!empty($tags)) $url .= "?tags=" . implode(",", $tags);

        // Get the XML object
        $xml = simplexml_load_string( self::curlWithCert($url) );
        if ($xml === false) {
            // Error finding person
            $module->emError("Unable to find $id in MAIS");
            return false;
        }

        // Get the Attributes
        $data = array(
            'sunet'        => (string) $xml['sunetid'],
            'first_name'   => (string) $xml->name[0]->first[0],
            'last_name'    => (string) $xml->name[0]->last[0],
            'email'        => (string) $xml->email[0],
            'telephone'    => (string) $xml->telephone[0],
            'affiliation'  => (string) $xml->affiliation[0],
            'department'   => (string) $xml->affiliation[0]->department[0],
            'description'  => (string) $xml->affiliation[0]->description[0],
            'relationship' => (string) $xml['relationship']
        );

        // Cache the person
        if (self::cachePerson($id, $data) === false) $module->emError("Error caching $id");

        return $data;
    }


    /**
     * Store the contents to a temp file timestamped for 1 hour refresh
     * @param $contents
     * @return string'
     */
    private static function verifyTempFile($contents) {
        global $module;

        $hash = sha1($contents);
        $temp_file = APP_PATH_TEMP . date('YmdH') . "0000_SPL_" . $hash;
        if  (!file_exists($temp_file)) {
            // Make the temp file
            file_put_contents($temp_file, $contents);
        }

        $module->emLog("Temp File: " . $temp_file);
        return  $temp_file;
    }


    /**
     * Make API Call with certs.  If the certs are not cached, then cache them to temp file from em settings
     * @param       $url
     * @return bool|mixed
     */
    private static function curlWithCert($url) {
        global $module;

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
            $module->emError($ch_error, "Curl in " . __METHOD__ . " failed", "ERROR");
            return false;
        }
        return $ch_result;
    }



    /**
     * Try to load the person from cache
     * @param $id
     * @param $expiry
     * @return array
     * @throws Exception
     */
    private static function loadFromCache($id, $expiry) {
        global $module;

        if (self::$cache_method == 'db') {
            return self::loadFromDbCache($id, $expiry);
        } elseif (self::$cache_method == 'file') {
            return self::loadFromFileCache($id, $expiry);
        } else {
            $module->emError("Invalid cache method!");
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
        global $module;

        $file = self::$cache_dir . "spl_" . $id . ".json";

        if (file_exists($file)) {
            $data = json_decode( file_get_contents($file), true);

            if (!empty($data['cache_ts'])) {
                // Determine age
                $delta = strtotime("NOW") - strtotime($data['cache_ts']);

                // Check if valid
                if ($delta < $expiry) {
                    $module->emLog("Using fileCache: $delta / $expiry seconds old");
                    return $data;
                } else {
                    $module->emLog("fileCache expired: $delta / $expiry seconds old");
                }
            } else {
                $module->emError("Unable to determine cache_ts from data in $file", $data);
            }
        }
        return false;
    }


    /** Try to load the person from the db cache */
    private static function loadFromDbCache($id, $expiry) {
        global $module;

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
            $module->emError("Missing or expired db cache");
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
        global $module;

        // Write the file to disk
        $file = self::$cache_dir . "spl_" . $id . ".json";
        if (!file_put_contents($file, json_encode($data))) {
            $module->emError("Error caching to $file", "ERROR");
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
        global $module;

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
            $module->emError("Error writing $sql",$result, "ERROR");
        }
        return true;
    }

}