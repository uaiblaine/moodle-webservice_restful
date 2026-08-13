<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * RESTful web service implementation classes and methods.
 *
 * @package    webservice_restful
 * @copyright  Matt Porritt <mattp@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_settings;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->dirroot/webservice/lib.php");

/**
 * REST service server implementation.
 *
 * @package    webservice_restful
 * @copyright  Matt Porritt <mattp@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservice_restful_server extends webservice_base_server {
    /**
     * Media types this server can emit, mapped to the internal format name.
     * The bare 'json'/'xml' spellings are accepted because that is what the
     * defaultacceptheader admin setting has always stored.
     */
    private const RESPONSE_FORMATS = [
        '*/*' => 'json',
        'application/*' => 'json',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'json' => 'json',
        'text/json' => 'json',
        'text/xml' => 'xml',
        'xml' => 'xml',
    ];

    /**
     * Media types this server can consume, mapped to the internal format name.
     */
    private const REQUEST_FORMATS = [
        'application/json' => 'json',
        'application/x-www-form-urlencoded' => 'urlencode',
        'application/xml' => 'xml',
        'multipart/form-data' => 'urlencode',
        'text/json' => 'json',
        'text/xml' => 'xml',
    ];

    /** @var string return method ('xml' or 'json') */
    protected $responseformat;

    /** @var string request method ('xml', 'json', or 'urlencode') */
    protected $requestformat;

    /** @var bool True once an error document has been written to the client. */
    protected $errorsent = false;

    /**
     * Contructor
     *
     * @param string $authmethod authentication method of the web service (WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN, ...)
     */
    public function __construct($authmethod) {
        parent::__construct($authmethod);
        $this->wsname = 'restful';
        $this->responseformat = 'json'; // Default to json.
        $this->requestformat = 'json'; // Default to json.
    }

    /**
     * Get headers from Apache websever.
     *
     * @return array $returnheaders The headers from Apache.
     */
    private function get_apache_headers() {
        $capitalizearray = [
            'content-type',
            'accept',
            'authorization',
            'content-length',
            'user-agent',
            'host',
        ];
        $headers = apache_request_headers();
        $returnheaders = [];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $capitalizearray)) {
                $header = 'HTTP_' . strtoupper($key);
                $header = str_replace('-', '_', $header);
                $returnheaders[$header] = $value;
            }
        }

        return $returnheaders;
    }

    /**
     * Extract the HTTP headers out of the request.
     *
     * @param array $headers Optional array of headers, to assist with testing.
     * @return array $headers HTTP headers.
     */
    private function get_headers($headers = null) {
        $returnheaders = [];

        if (!$headers) {
            if (function_exists('apache_request_headers')) {  // Apache websever.
                $headers = $this->get_apache_headers();
            } else {  // Nginx webserver.
                $headers = $_SERVER;
            }
        }

        foreach ($headers as $key => $value) {
            if (substr($key, 0, 5) == 'HTTP_') {
                $returnheaders[$key] = $value;
            }
        }

        return $returnheaders;
    }

    /**
     * Get the webservice authorization token from the request.
     * Throws error and notifies caller on failure.
     *
     * @param array $headers The extracted HTTP headers.
     * @return string $wstoken The extracted webservice authorization token.
     */
    private function get_wstoken($headers) {
        if (!isset($headers['HTTP_AUTHORIZATION'])) {
            // Raise an error if auth header not supplied.
            $ex = new \moodle_exception('noauthheader', 'webservice_restful', '');
            $this->send_error($ex, 401);
            return '';
        }

        /*
         * RFC 6750 makes the "Bearer" scheme case-insensitive and allows any amount of
         * whitespace after it. Anchoring the strip also stops a token that happens to
         * contain the word from being mangled, which a bare str_replace would do.
         */
        $wstoken = trim(preg_replace('/^\s*bearer\s+/i', '', $headers['HTTP_AUTHORIZATION']));

        if ($wstoken === '') {
            // An empty credential is a missing credential; do not fall through to the DB lookup.
            $ex = new \moodle_exception('noauthheader', 'webservice_restful', '');
            $this->send_error($ex, 401);
        }

        return $wstoken;
    }

    /**
     * Extract the web service funtion to use from the request URL.
     * Throws error and notifies caller on failure.
     *
     * @param array $getvars Optional get variables, used for testing.
     * @return string $wsfunction The webservice function to call.
     */
    private function get_wsfunction($getvars = null) {
        $wsfunction = '';

        // Testing has found that there is varying methods across webservers,
        // so we try a few ways.

        if ($getvars && isset($getvars['file'])) { // Check to see if we are passing the function explictly.
            $wsfunction = ltrim($getvars['file'], '/');
        } else if (isset($_GET['file'])) { // Try get variables.
            $wsfunction = ltrim($_GET['file'], '/');
        } else if (isset($_SERVER['PATH_INFO'])) { // Try path info from server super global.
            $wsfunction = ltrim($_SERVER['PATH_INFO'], '/');
        } else if (isset($_SERVER['REQUEST_URI'])) { // Try request URI from server super global.
            /*
             * Only the path may be inspected: a bare substr of REQUEST_URI carries the query
             * string into the function name, so /server.php?foo=1 becomes the function
             * "server.php?foo=1" and the lookup fails with a DML error instead of ours.
             */
            $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $wsfunction = substr($path, strrpos($path, '/') + 1);
            if ($wsfunction === 'server.php') {
                // This fallback is only reached when no slash argument was supplied at all.
                $wsfunction = '';
            }
        }

        if ($wsfunction == '') {
            // Raise an error if function not supplied.
            $ex = new \moodle_exception('nowsfunction', 'webservice_restful', '');
            $this->send_error($ex, 400);
        }

        return $wsfunction;
    }

    /**
     * Reduce a media type list to one of the formats this server understands.
     *
     * Media ranges are ranked by their q value, highest first, with the order the client
     * listed them in breaking ties, as described by RFC 9110 section 12.5.1.
     *
     * @param string $accept The raw Accept header value, or a bare format name.
     * @param array $supported Map of media type to internal format name.
     * @return string The internal format name, or an empty string when nothing matches.
     */
    private static function negotiate_format($accept, $supported) {
        $candidates = [];

        foreach (explode(',', (string) $accept) as $index => $mediarange) {
            $parameters = explode(';', $mediarange);
            $mediatype = strtolower(trim((string) array_shift($parameters)));

            if ($mediatype === '') {
                continue;
            }

            $quality = 1.0;
            foreach ($parameters as $parameter) {
                $parameter = strtolower(trim($parameter));
                if (strpos($parameter, 'q=') === 0) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            $candidates[] = ['type' => $mediatype, 'quality' => $quality, 'index' => $index];
        }

        usort($candidates, static function ($a, $b) {
            return [$b['quality'], $a['index']] <=> [$a['quality'], $b['index']];
        });

        foreach ($candidates as $candidate) {
            if ($candidate['quality'] > 0 && isset($supported[$candidate['type']])) {
                return $supported[$candidate['type']];
            }
        }

        return '';
    }

    /**
     * Get the format to use for the client response.
     * Throws error and notifies caller on failure.
     *
     * @param array $headers The HTTP headers.
     * @return string $responseformat The format of the client response.
     */
    private function get_responseformat($headers) {
        $accept = '';

        if (isset($headers['HTTP_ACCEPT'])) {
            $accept = $headers['HTTP_ACCEPT'];
        } else if (get_config('webservice_restful', 'supportdefaultacceptheader')) {
            $accept = get_config('webservice_restful', 'defaultacceptheader');
        }

        if (trim((string) $accept) === '') {
            // Raise an error if accept header not supplied.
            $ex = new \moodle_exception('noacceptheader', 'webservice_restful', '');
            $this->send_error($ex, 400);
            return '';
        }

        $responseformat = self::negotiate_format($accept, self::RESPONSE_FORMATS);

        if ($responseformat === '') {
            $ex = new \moodle_exception('unsupportedacceptheader', 'webservice_restful', '', $accept);
            $this->send_error($ex, 406);
        }

        return $responseformat;
    }

    /**
     * Get the format of the client request.
     * Throws error and notifies caller on failure.
     *
     * @param array $headers The HTTP headers.
     * @return string $requestformat The format of the client request.
     */
    private function get_requestformat($headers) {
        if (!isset($headers['HTTP_CONTENT_TYPE'])) {
            // Raise an error if content header not supplied.
            $ex = new \moodle_exception('notypeheader', 'webservice_restful', '');
            $this->send_error($ex, 400);
            return '';
        }

        $mediatype = strtolower(trim(explode(';', $headers['HTTP_CONTENT_TYPE'])[0]));
        $requestformat = isset(self::REQUEST_FORMATS[$mediatype]) ? self::REQUEST_FORMATS[$mediatype] : '';

        if ($requestformat === '') {
            /*
             * Refusing an unknown type matters more than it looks: the old code fell through
             * to $_POST, which is empty for a JSON body, so a filtered query silently became
             * an unfiltered one and still returned 200.
             */
            $ex = new \moodle_exception('unsupportedtypeheader', 'webservice_restful', '', $mediatype);
            $this->send_error($ex, 415);
        }

        return $requestformat;
    }

    /**
     * Get the parameters to pass to the webservice function
     *
     * @param string $content the content to parse.
     * @return array $parameters The parameters to use with the webservice.
     */
    private function get_parameters($content = '') {
        if (!$content) {
            $content = file_get_contents('php://input');
        }

        $parameters = [];

        if ($this->requestformat == 'json') {
            if (trim($content) !== '') {
                $parameters = json_decode($content, true); // Convert JSON into array.
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $ex = new \moodle_exception('invalidrequestbody', 'webservice_restful', '', json_last_error_msg());
                    $this->send_error($ex, 400);
                    return [];
                }
            }
        } else if ($this->requestformat == 'xml') {
            if (trim($content) !== '') {
                $parametersxml = simplexml_load_string($content);
                if ($parametersxml === false) {
                    $ex = new \moodle_exception('invalidrequestbody', 'webservice_restful', '', 'XML');
                    $this->send_error($ex, 400);
                    return [];
                }
                // Dirty XML to JSON to PHP array conversion.
                $parameters = json_decode(json_encode($parametersxml), true);
            }
        } else {  // Data provided in as URL encoded.
            $parameters = $_POST;
            if (empty($parameters) && trim($content) !== '') {
                // PHP only fills $_POST for a POST; PUT/PATCH/DELETE bodies have to be parsed here.
                parse_str($content, $parameters);
            }
        }

        if (!is_array($parameters)) {
            // A scalar or null body is not a parameter list, and passing it on raises a TypeError.
            $ex = new \moodle_exception('invalidrequestbody', 'webservice_restful', '', gettype($parameters));
            $this->send_error($ex, 400);
            return [];
        }

        // Process GET variables if they exist.
        if ($_GET) {
            foreach ($_GET as $key => $value) {
                if ($key === 'file') {
                    /*
                     * 'file' carries the function name in the query-parameter routing mode. It is
                     * not a web service parameter, and external_api::validate_parameters() rejects
                     * the whole call when it sees a key the function did not declare.
                     */
                    continue;
                }
                if (!isset($parameters[$key])) {
                    $parameters[$key] = $value;
                }
            }
        }

        return $parameters;
    }

    /**
     * This method parses the request sent to Moodle
     * and extracts and validates the supplied data.
     *
     * @return bool True when the request is usable, false when an error was already sent.
     */
    protected function parse_request() {

        // Retrieve and clean the POST/GET parameters from the parameters specific to the server.
        parent::set_web_service_call_settings();

        // Get the HTTP Headers.
        $headers = $this->get_headers();

        /*
         * Each step below reports its own error through send_error(). Stopping at the first
         * failure keeps the response to exactly one error document, and testing the flag
         * rather than the returned value means an empty-but-error-free result (an empty
         * token, say) can no longer abort the request with a bare HTTP 200 and no body.
         */

        // Response format comes first so every later error is rendered in the format asked for.
        $this->responseformat = $this->get_responseformat($headers);
        if ($this->errorsent) {
            return false;
        }

        $this->token = $this->get_wstoken($headers);
        if ($this->errorsent) {
            return false;
        }

        $this->requestformat = $this->get_requestformat($headers);
        if ($this->errorsent) {
            return false;
        }

        $this->functionname = $this->get_wsfunction();
        if ($this->errorsent) {
            return false;
        }

        $this->parameters = $this->get_parameters();
        if ($this->errorsent) {
            return false;
        }

        return true;
    }

    /**
     * Process request from client.
     *
     * @uses die
     */
    public function run() {
        global $CFG, $USER, $SESSION;

        // We will probably need a lot of memory in some functions.
        raise_memory_limit(MEMORY_EXTRA);

        // Set some longer timeout, this script is not sending any output,
        // this means we need to manually extend the timeout operations
        // that need longer time to finish.
        external_api::set_timeout();

        // Set up exception handler first, we want to sent them back in correct format that
        // the other system understands.
        // We do not need to call the original default handler because this ws handler does everything.
        set_exception_handler([$this, 'exception_handler']);

        // Init all properties from the request data.
        if (!$this->parse_request()) {
            die;
        }

        // Authenticate user, this has to be done after the request parsing
        // this also sets up $USER and $SESSION.
        $this->authenticate_user();

        // Find all needed function info and make sure user may actually execute the function.
        $this->load_function_info();

        // Log the web service request.
        $params = [
            'other' => [
                'function' => $this->functionname,
            ],
        ];
        $event = \core\event\webservice_function_called::create($params);
        $event->trigger();

        // Do additional setup stuff.
        $settings = external_settings::get_instance();

        $sessionlang = $settings->get_lang();
        if (!empty($sessionlang)) {
            $SESSION->lang = $sessionlang;
        }

        setup_lang_from_browser();

        if (empty($CFG->lang)) {
            if (empty($SESSION->lang)) {
                $CFG->lang = 'en';
            } else {
                $CFG->lang = $SESSION->lang;
            }
        }

        // Change timezone only in sites where it isn't forced, as webservice_base_server::run() does.
        $newtimezone = $settings->get_timezone();
        if (!empty($newtimezone) && (!isset($CFG->forcetimezone) || $CFG->forcetimezone == 99)) {
            $USER->timezone = $newtimezone;
        }

        // Finally, execute the function - any errors are catched by the default exception handler.
        $this->execute();

        // Send the results back in correct format.
        $this->send_response();

        // Session cleanup.
        $this->session_cleanup();

        die;
    }

    /**
     * Send the result of function call to the WS client.
     *
     * @return void
     */
    protected function send_response() {
        $exception = null;

        // Check that the returned values are valid.
        try {
            if ($this->function->returns_desc != null) {
                $validatedvalues = external_api::clean_returnvalue($this->function->returns_desc, $this->returns);
            } else {
                $validatedvalues = null;
            }
        } catch (Exception $ex) {
            $exception = $ex;
        }

        if (!empty($exception)) {
            $response = $this->generate_error($exception);
        } else {
            // We can now convert the response to the requested REST format.
            if ($this->responseformat === 'xml') {
                $response = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
                $response .= '<RESPONSE>' . "\n";
                $response .= self::xmlize_result($validatedvalues, $this->function->returns_desc);
                $response .= '</RESPONSE>' . "\n";
            } else {
                $response = json_encode($validatedvalues);
            }
        }

        $this->send_headers();
        echo $response;
    }

    /**
     * Send the error information to the WS client
     * formatted as XML document.
     * Note: the exception is never passed as null,
     *       it only matches the abstract function declaration.
     *
     * @param exception $ex the exception that we are sending.
     * @param integer $code The HTTP response code to return.
     */
    protected function send_error($ex = null, $code = null) {
        if ($code === null) {
            /*
             * webservice_base_server::exception_handler() calls this with the exception only, so
             * without this every post-parse failure — an invalid token included — reached the
             * client as a flat 400.
             */
            $code = self::status_for_exception($ex);
        }
        $this->errorsent = true;
        // Sniffing for unit tests running alwasys feels like a hack.
        // We need to do this otherwise it will conflict with the headers
        // sent by PHPUNIT.
        if (!PHPUNIT_TEST) {
            http_response_code($code);
            $this->send_headers($code);
        }
        echo $this->generate_error($ex);
    }

    /**
     * Choose the HTTP status that describes a failure.
     *
     * The error codes below are the ones webservice_server::authenticate_user() and
     * webservice_base_server::load_function_info() raise; anything else is a bad request.
     *
     * @param mixed $ex The exception being reported.
     * @return int The HTTP status code to send.
     */
    private static function status_for_exception($ex) {
        if ($ex instanceof \webservice_access_exception) {
            // Authenticated, but not allowed to call this function.
            return 403;
        }

        $unauthorised = [
            'invalidtoken',
            'usernotconfirmed',
            'wsaccessuserdeleted',
            'wsaccessuserexpired',
            'wsaccessusernologin',
            'wsaccessusersuspended',
            'wsaccessuserunconfirmed',
        ];

        if (isset($ex->errorcode) && in_array($ex->errorcode, $unauthorised, true)) {
            return 401;
        }

        if (isset($ex->errorcode) && $ex->errorcode === 'sitemaintenance') {
            return 503;
        }

        return 400;
    }

    /**
     * Build the error information matching the REST returned value format (JSON or XML)
     * @param exception $ex the exception we are converting in the server rest format
     * @return string the error in the requested REST format
     */
    protected function generate_error($ex) {
        if ($this->responseformat === 'xml') {
            $error = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
            $error .= '<EXCEPTION class="' . get_class($ex) . '">' . "\n";
            if (isset($ex->errorcode)) {
                // Not every exception reaching this point is a moodle_exception.
                $error .= '<ERRORCODE>' . htmlspecialchars($ex->errorcode, ENT_COMPAT, 'UTF-8')
                        . '</ERRORCODE>' . "\n";
            }
            $error .= '<MESSAGE>' . htmlspecialchars($ex->getMessage(), ENT_COMPAT, 'UTF-8') . '</MESSAGE>' . "\n";
            if (debugging() && isset($ex->debuginfo)) {
                $error .= '<DEBUGINFO>' . htmlspecialchars($ex->debuginfo, ENT_COMPAT, 'UTF-8') . '</DEBUGINFO>' . "\n";
            }
            $error .= '</EXCEPTION>' . "\n";
        } else {
            $errorobject = new stdClass();
            $errorobject->exception = get_class($ex);
            if (isset($ex->errorcode)) {
                $errorobject->errorcode = $ex->errorcode;
            }
            $errorobject->message = $ex->getMessage();
            if (debugging() && isset($ex->debuginfo)) {
                $errorobject->debuginfo = $ex->debuginfo;
            }
            $error = json_encode($errorobject);
        }
        return $error;
    }

    /**
     * Internal implementation - sending of page headers.
     *
     * @param integer $code The HTTP response code to return.
     */
    protected function send_headers($code = 200) {
        if ($this->responseformat === 'xml') {
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: inline; filename="response.xml"');
        } else {
            header('Content-type: application/json');
        }
        header('X-PHP-Response-Code: ' . $code, true, $code);
        header('Cache-Control: private, must-revalidate, pre-check=0, post-check=0, max-age=0');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
        header('Pragma: no-cache');
        header('Accept-Ranges: none');
        // Allow cross-origin requests only for Web Services.
        // This allow to receive requests done by Web Workers or webapps in different domains.
        header('Access-Control-Allow-Origin: *');
    }

    /**
     * Internal implementation - recursive function producing XML markup.
     *
     * @param mixed $returns the returned values
     * @param external_description $desc The external description of the returned values.
     * @return string
     * @throws coding_exception When the description is of a type this server cannot render.
     */
    protected static function xmlize_result($returns, $desc) {
        if ($desc === null) {
            return '';
        } else if ($desc instanceof external_value) {
            if (is_bool($returns)) {
                // We want 1/0 instead of true/false here.
                $returns = (int)$returns;
            }
            if (is_null($returns)) {
                return '<VALUE null="null"/>' . "\n";
            } else {
                return '<VALUE>' . htmlspecialchars($returns, ENT_COMPAT, 'UTF-8') . '</VALUE>' . "\n";
            }
        } else if ($desc instanceof external_multiple_structure) {
            $mult = '<MULTIPLE>' . "\n";
            if (!empty($returns)) {
                foreach ($returns as $val) {
                    $mult .= self::xmlize_result($val, $desc->content);
                }
            }
            $mult .= '</MULTIPLE>' . "\n";
            return $mult;
        } else if ($desc instanceof external_single_structure) {
            $single = '<SINGLE>' . "\n";
            foreach ($desc->keys as $key => $subdesc) {
                $value = isset($returns[$key]) ? $returns[$key] : null;
                $single .= '<KEY name="' . $key . '">' . self::xmlize_result($value, $subdesc) . '</KEY>' . "\n";
            }
            $single .= '</SINGLE>' . "\n";
            return $single;
        }

        /*
         * Falling through used to return null, which concatenated into an empty <RESPONSE>
         * and reported success. An unrenderable description is a bug in this server, so say so.
         */
        throw new coding_exception('Unrenderable external description: ' . get_class($desc));
    }
}
