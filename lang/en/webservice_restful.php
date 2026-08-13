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
 * Strings for component 'webservice_restful', language 'en'
 *
 * @package    webservice_restful
 * @category   string
 * @copyright  Matt Porritt <mattp@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['defaultacceptheader'] = 'Default Accept header';
$string['defaultacceptheaderdesc'] = 'The response format to use when the request does not contain an Accept header.';
$string['invalidrequestbody'] = 'The request body sent to Moodle could not be parsed ({$a})';
$string['noacceptheader'] = 'No Accept header found in request sent to Moodle';
$string['noauthheader'] = 'No Authorization header found in request sent to Moodle';
$string['notypeheader'] = 'No Content Type header found in request sent to Moodle';
$string['nowsfunction'] = 'No webservice function found in URL sent to Moodle';
$string['pluginname'] = 'RESTful protocol';
$string['privacy:metadata'] = 'The RESTful protocol plugin does not store any personal data.';
$string['restful:use'] = 'Use RESTful protocol';
$string['supportdefaultacceptheader'] = 'Support default Accept header';
$string['supportdefaultacceptheaderdesc'] = 'If enabled, the RESTful protocol will support the default Accept header if it is not present in the request.';
$string['unsupportedacceptheader'] = 'The Accept header sent to Moodle requests no format this server can return ({$a})';
$string['unsupportedtypeheader'] = 'The Content Type sent to Moodle is not supported by this server ({$a})';
