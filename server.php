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
 * RESTful web service entry point. The authentication is done via header tokens.
 *
 * @package    webservice_restful
 * @copyright  Matt Porritt <mattp@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * NO_DEBUG_DISPLAY - disable moodle specific debug messages and any errors in output
 */
define('NO_DEBUG_DISPLAY', true);
define('WS_SERVER', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Web service entry point: authentication is by token, in webservice_restful_server::run().
require('../../config.php');
require_once("$CFG->dirroot/webservice/restful/locallib.php");

if (!webservice_protocol_is_enabled('restful')) {
    header("HTTP/1.0 403 Forbidden");
    debugging(
        'The server died because the web services or the REST protocol are not enable',
        DEBUG_DEVELOPER
    );
    die;
}

$server = new webservice_restful_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
$server->run();
die;

/**
 * Format an exception raised before the server object exists.
 *
 * lib/setup.php installs early_ws_exception_handler() for every WS_SERVER request, and that
 * handler rethrows — fatally, from inside an exception handler — unless the protocol defines
 * this function. Without it a bootstrap failure reaches the client as a zero-length text/html
 * 500 rather than a parseable error document. Core's own protocols carry the same function.
 *
 * @param Exception $ex The exception raised during bootstrap.
 * @return void
 */
function raise_early_ws_exception(Exception $ex): void {
    global $CFG;

    require_once("$CFG->dirroot/webservice/restful/locallib.php");

    $server = new webservice_restful_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
    $server->exception_handler($ex);
}
