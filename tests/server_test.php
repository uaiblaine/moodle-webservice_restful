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

namespace webservice_restful;

use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use webservice_restful_server;

defined('MOODLE_INTERNAL') || die();
global $CFG;

require_once($CFG->dirroot . '/webservice/restful/locallib.php');

/**
 * Restful server testcase.
 *
 * @package    webservice_restful
 * @copyright  Matt Porritt <mattp@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\webservice_restful_server::class)]
final class server_test extends \advanced_testcase {
    /**
     * Invoke one of the server's non-public methods.
     *
     * @param string $method The method name.
     * @param array $args Positional arguments for the method.
     * @param webservice_restful_server|null $server Server to invoke against; a fresh one by default.
     * @return mixed Whatever the method returned.
     */
    private function invoke(string $method, array $args = [], ?webservice_restful_server $server = null) {
        if ($server === null) {
            $server = new webservice_restful_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        }

        $reflection = new ReflectionMethod(webservice_restful_server::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($server, $args);
    }

    /**
     * Invoke a method that is expected to fail, and return the decoded error document it emitted.
     *
     * Asserting on the emitted document alone is not enough: send_error() does not stop
     * execution, so a method could report an error and still hand a usable value back to
     * parse_request(). The returned value is therefore checked by the callers too.
     *
     * @param string $method The method name.
     * @param array $args Positional arguments for the method.
     * @return array The decoded JSON error document, plus 'returned' holding the method's return value.
     */
    private function invoke_expecting_error(string $method, array $args = []): array {
        $server = new webservice_restful_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        ob_start();
        $returned = $this->invoke($method, $args, $server);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'The error path should emit a JSON document, got: ' . $output);
        $decoded['returned'] = $returned;

        return $decoded;
    }

    /**
     * Test get header method extracts HTTP headers.
     */
    public function test_get_headers(): void {
        $headers = [
            'USER' => 'www-data',
            'HOME' => '/var/www',
            'HTTP_CONTENT_LENGTH' => '17',
            'HTTP_AUTHORIZATION' => 'e71561c88ca7f0f0c94fee66ca07247b',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_USER_AGENT' => 'curl/7.47.0',
            'HTTP_HOST' => 'moodle.local',
            'REDIRECT_STATUS' => '200',
            'SERVER_NAME' => 'moodle.local',
            'SERVER_PORT' => '80',
            'SERVER_ADDR' => '192.168.56.103',
            'REMOTE_PORT' => '39402',
            'REMOTE_ADDR' => '192.168.56.1',
        ];
        $expected = [
            'HTTP_CONTENT_LENGTH' => '17',
            'HTTP_AUTHORIZATION' => 'e71561c88ca7f0f0c94fee66ca07247b',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_USER_AGENT' => 'curl/7.47.0',
            'HTTP_HOST' => 'moodle.local',
        ];

        $this->assertEquals($expected, $this->invoke('get_headers', [$headers]));
    }

    /**
     * Test get wstoken method extracts token.
     */
    public function test_get_wstoken(): void {
        $headers = [
            'HTTP_AUTHORIZATION' => 'e71561c88ca7f0f0c94fee66ca07247b',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];

        $this->assertEquals('e71561c88ca7f0f0c94fee66ca07247b', $this->invoke('get_wstoken', [$headers]));
    }

    /**
     * The Bearer scheme is case-insensitive per RFC 6750, and must only be stripped from the front.
     *
     * @param string $header The raw Authorization header value.
     * @param string $expected The token the server should extract.
     */
    #[DataProvider('bearer_provider')]
    public function test_get_wstoken_bearer(string $header, string $expected): void {
        $this->assertEquals($expected, $this->invoke('get_wstoken', [['HTTP_AUTHORIZATION' => $header]]));
    }

    /**
     * Authorization header values and the token each should yield.
     *
     * @return array
     */
    public static function bearer_provider(): array {
        return [
            'bare token' => ['e71561c88ca7f0f0c94fee66ca07247b', 'e71561c88ca7f0f0c94fee66ca07247b'],
            'Bearer prefix' => ['Bearer e71561c88ca7f0f0c94fee66ca07247b', 'e71561c88ca7f0f0c94fee66ca07247b'],
            'lowercase scheme' => ['bearer e71561c88ca7f0f0c94fee66ca07247b', 'e71561c88ca7f0f0c94fee66ca07247b'],
            'uppercase scheme' => ['BEARER e71561c88ca7f0f0c94fee66ca07247b', 'e71561c88ca7f0f0c94fee66ca07247b'],
            'surrounding whitespace' => ['  Bearer   abc123  ', 'abc123'],
            // A global str_replace turned this into 'abcdef'; only a leading scheme may be stripped.
            'scheme-like text inside the token' => ['abcBearer def', 'abcBearer def'],
        ];
    }

    /**
     * Test get wstoken method correctly errors.
     */
    public function test_get_wstoken_error(): void {
        $decoded = $this->invoke_expecting_error('get_wstoken', [[]]);

        $this->assertStringEndsWith('moodle_exception', $decoded['exception']);
        $this->assertEquals('noauthheader', $decoded['errorcode']);
        $this->assertEquals('No Authorization header found in request sent to Moodle', $decoded['message']);
        $this->assertSame('', $decoded['returned']);
    }

    /**
     * An empty Authorization value is a missing credential, not a token of length zero.
     */
    public function test_get_wstoken_empty_value_errors(): void {
        $decoded = $this->invoke_expecting_error('get_wstoken', [['HTTP_AUTHORIZATION' => '']]);

        $this->assertEquals('noauthheader', $decoded['errorcode']);
        $this->assertSame('', $decoded['returned']);
    }

    /**
     * Test get wsfunction method extracts function.
     */
    public function test_get_wsfunction(): void {
        $getvars = ['file' => '/core_course_get_courses'];

        $this->assertEquals('core_course_get_courses', $this->invoke('get_wsfunction', [$getvars]));
    }

    /**
     * Test get wsfunction method correctly errors.
     */
    public function test_get_wsfunction_error(): void {
        $decoded = $this->invoke_expecting_error('get_wsfunction', [[]]);

        $this->assertStringEndsWith('moodle_exception', $decoded['exception']);
        $this->assertEquals('nowsfunction', $decoded['errorcode']);
        $this->assertEquals('No webservice function found in URL sent to Moodle', $decoded['message']);
        $this->assertSame('', $decoded['returned']);
    }

    /**
     * Test get response format method extracts response format.
     */
    public function test_get_responseformat(): void {
        $headers = [
            'HTTP_AUTHORIZATION' => 'e71561c88ca7f0f0c94fee66ca07247b',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/xml',
        ];

        $this->assertEquals('json', $this->invoke('get_responseformat', [$headers]));
    }

    /**
     * Real-world Accept headers must resolve to the format the client actually asked for.
     *
     * Every one of these used to fall through to the XML branch, because ltrim()'s second
     * argument is a character mask rather than a prefix.
     *
     * @param string $accept The raw Accept header value.
     * @param string $expected The format the server should choose.
     */
    #[DataProvider('accept_provider')]
    public function test_get_responseformat_negotiation(string $accept, string $expected): void {
        $this->assertEquals($expected, $this->invoke('get_responseformat', [['HTTP_ACCEPT' => $accept]]));
    }

    /**
     * Accept header values and the response format each should select.
     *
     * @return array
     */
    public static function accept_provider(): array {
        return [
            'plain json' => ['application/json', 'json'],
            'plain xml' => ['application/xml', 'xml'],
            'text xml' => ['text/xml', 'xml'],
            'json with charset' => ['application/json; charset=utf-8', 'json'],
            'axios default' => ['application/json, text/plain, */*', 'json'],
            'curl default' => ['*/*', 'json'],
            'uppercase' => ['APPLICATION/JSON', 'json'],
            'q values pick the best' => ['application/xml;q=0.9, application/json;q=1.0', 'json'],
            'q values prefer xml' => ['application/json;q=0.2, application/xml;q=0.8', 'xml'],
            'browser style' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'xml'],
            'zero q is not acceptable' => ['application/xml;q=0, application/json', 'json'],
        ];
    }

    /**
     * Test get response format method correctly errors.
     */
    public function test_get_responseformat_error(): void {
        $decoded = $this->invoke_expecting_error('get_responseformat', [[]]);

        $this->assertStringEndsWith('moodle_exception', $decoded['exception']);
        $this->assertEquals('noacceptheader', $decoded['errorcode']);
        $this->assertEquals('No Accept header found in request sent to Moodle', $decoded['message']);
        $this->assertSame('', $decoded['returned']);
    }

    /**
     * An Accept header naming only formats this server cannot produce is refused, not guessed at.
     */
    public function test_get_responseformat_unacceptable(): void {
        $decoded = $this->invoke_expecting_error('get_responseformat', [['HTTP_ACCEPT' => 'text/html']]);

        $this->assertEquals('unsupportedacceptheader', $decoded['errorcode']);
        $this->assertSame('', $decoded['returned']);
    }

    /**
     * The default Accept setting is only consulted when the request carries no Accept header.
     */
    public function test_get_responseformat_default_setting(): void {
        $this->resetAfterTest();

        // Control: with the setting off, no Accept header is an error.
        set_config('supportdefaultacceptheader', 0, 'webservice_restful');
        $decoded = $this->invoke_expecting_error('get_responseformat', [[]]);
        $this->assertEquals('noacceptheader', $decoded['errorcode']);

        set_config('supportdefaultacceptheader', 1, 'webservice_restful');
        set_config('defaultacceptheader', 'xml', 'webservice_restful');
        $this->assertEquals('xml', $this->invoke('get_responseformat', [[]]));

        // A live Accept header still wins over the default.
        $this->assertEquals('json', $this->invoke('get_responseformat', [['HTTP_ACCEPT' => 'application/json']]));
    }

    /**
     * Test get request format method extracts request format.
     */
    public function test_get_requestformat(): void {
        $headers = [
            'HTTP_AUTHORIZATION' => 'e71561c88ca7f0f0c94fee66ca07247b',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/xml',
        ];

        $this->assertEquals('xml', $this->invoke('get_requestformat', [$headers]));
    }

    /**
     * Content types map to the parser that can read them.
     *
     * @param string $contenttype The raw Content-Type header value.
     * @param string $expected The request format the server should choose.
     */
    #[DataProvider('contenttype_provider')]
    public function test_get_requestformat_types(string $contenttype, string $expected): void {
        $this->assertEquals($expected, $this->invoke('get_requestformat', [['HTTP_CONTENT_TYPE' => $contenttype]]));
    }

    /**
     * Content-Type header values and the request format each should select.
     *
     * @return array
     */
    public static function contenttype_provider(): array {
        return [
            'json' => ['application/json', 'json'],
            'json with charset' => ['application/json; charset=utf-8', 'json'],
            'xml' => ['application/xml', 'xml'],
            'text xml' => ['text/xml', 'xml'],
            'form encoded' => ['application/x-www-form-urlencoded', 'urlencode'],
            'multipart' => ['multipart/form-data; boundary=xyz', 'urlencode'],
            'uppercase' => ['Application/JSON', 'json'],
        ];
    }

    /**
     * Test get request format method correctly errors.
     */
    public function test_get_requestformat_error(): void {
        $decoded = $this->invoke_expecting_error('get_requestformat', [[]]);

        $this->assertStringEndsWith('moodle_exception', $decoded['exception']);
        $this->assertEquals('notypeheader', $decoded['errorcode']);
        $this->assertEquals('No Content Type header found in request sent to Moodle', $decoded['message']);
        $this->assertSame('', $decoded['returned']);
    }

    /**
     * An unreadable Content-Type is refused rather than silently parsed as an empty form post.
     *
     * Falling through to $_POST turned a filtered query into an unfiltered one and still
     * answered 200, so this is the security-relevant half of the media type handling.
     */
    public function test_get_requestformat_unsupported(): void {
        $decoded = $this->invoke_expecting_error('get_requestformat', [['HTTP_CONTENT_TYPE' => 'text/json-ish']]);

        $this->assertEquals('unsupportedtypeheader', $decoded['errorcode']);
        $this->assertSame('', $decoded['returned']);
    }

    /**
     * A JSON body is decoded into the parameter array.
     */
    public function test_get_parameters_json(): void {
        $server = new webservice_restful_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $parameters = $this->invoke('get_parameters', ['{"options":{"ids":[6]}}'], $server);

        $this->assertEquals(['options' => ['ids' => [6]]], $parameters);
    }

    /**
     * A malformed body is an error, not an empty parameter list.
     *
     * Treating it as "no parameters" let a truncated request execute the function with
     * whatever its defaults were, and report success.
     */
    public function test_get_parameters_malformed_json(): void {
        $decoded = $this->invoke_expecting_error('get_parameters', ['{"broken']);

        $this->assertEquals('invalidrequestbody', $decoded['errorcode']);
        $this->assertSame([], $decoded['returned']);
    }

    /**
     * A JSON scalar body is not a parameter list.
     */
    public function test_get_parameters_scalar_json(): void {
        $decoded = $this->invoke_expecting_error('get_parameters', ['"a string"']);

        $this->assertEquals('invalidrequestbody', $decoded['errorcode']);
        $this->assertSame([], $decoded['returned']);
    }

    /**
     * The query variable naming the function must not reach the function as a parameter.
     *
     * external_api::validate_parameters() rejects the entire call when it meets a key the
     * function did not declare, so merging 'file' made the documented ?file= URL form
     * impossible to use.
     */
    public function test_get_parameters_excludes_file_getvar(): void {
        $this->resetAfterTest();

        $_GET['file'] = 'core_course_get_courses';
        $_GET['other'] = 'kept';

        try {
            $parameters = $this->invoke('get_parameters', ['{"options":{"ids":[6]}}']);
        } finally {
            unset($_GET['file'], $_GET['other']);
        }

        $this->assertArrayNotHasKey('file', $parameters);
        // Control: an unrelated query variable is still merged, so the exclusion is targeted.
        $this->assertEquals('kept', $parameters['other']);
        $this->assertEquals(['ids' => [6]], $parameters['options']);
    }

    /**
     * xmlize_result renders a real external description rather than an empty document.
     *
     * The instanceof tests used to name classes that do not exist in the global namespace,
     * so every branch was false and every XML response body came back empty with HTTP 200.
     */
    public function test_xmlize_result(): void {
        $desc = new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course id'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
            ])
        );
        $returns = [
            ['id' => 6, 'shortname' => 'search test'],
        ];

        $xml = $this->invoke('xmlize_result', [$returns, $desc]);

        $this->assertStringContainsString('<MULTIPLE>', $xml);
        $this->assertStringContainsString('<SINGLE>', $xml);
        $this->assertStringContainsString('<KEY name="id"><VALUE>6</VALUE>', $xml);
        $this->assertStringContainsString('<KEY name="shortname"><VALUE>search test</VALUE>', $xml);
    }

    /**
     * A null description renders nothing, which is what a void function returns.
     */
    public function test_xmlize_result_null_description(): void {
        $this->assertSame('', $this->invoke('xmlize_result', [null, null]));
    }

    /**
     * Every real external function's return description can be rendered.
     *
     * This is the assertion that would have caught the empty-response bug: it uses the
     * description core actually builds, not one assembled by the test.
     */
    public function test_xmlize_result_with_core_function(): void {
        $function = external_api::external_function_info('core_webservice_get_site_info');

        $xml = $this->invoke('xmlize_result', [['sitename' => 'Test site'], $function->returns_desc]);

        $this->assertStringContainsString('<SINGLE>', $xml);
        $this->assertStringContainsString('<KEY name="sitename"><VALUE>Test site</VALUE>', $xml);
    }

    /**
     * Failures map to the HTTP status that describes them, not a flat 400.
     *
     * @param \Throwable $ex The exception being reported.
     * @param int $expected The status the server should send.
     */
    #[DataProvider('status_provider')]
    public function test_status_for_exception(\Throwable $ex, int $expected): void {
        $reflection = new ReflectionMethod(webservice_restful_server::class, 'status_for_exception');
        $reflection->setAccessible(true);

        $this->assertSame($expected, $reflection->invoke(null, $ex));
    }

    /**
     * Exceptions and the HTTP status each should produce.
     *
     * @return array
     */
    public static function status_provider(): array {
        return [
            'invalid token' => [new \moodle_exception('invalidtoken', 'webservice'), 401],
            'suspended user' => [new \moodle_exception('wsaccessusersuspended', 'webservice', '', 'someone'), 401],
            'function not allowed' => [new \webservice_access_exception('nope'), 403],
            'site in maintenance' => [new \moodle_exception('sitemaintenance', 'admin'), 503],
            'anything else' => [new \moodle_exception('invalidparameter', 'debug'), 400],
            'plain exception' => [new \RuntimeException('boom'), 400],
        ];
    }

    /**
     * The XML error branch must survive an exception that carries no errorcode.
     */
    public function test_generate_error_xml_without_errorcode(): void {
        $server = new webservice_restful_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $format = new \ReflectionProperty(webservice_restful_server::class, 'responseformat');
        $format->setAccessible(true);
        $format->setValue($server, 'xml');

        $reflection = new ReflectionMethod(webservice_restful_server::class, 'generate_error');
        $reflection->setAccessible(true);
        $error = $reflection->invoke($server, new \RuntimeException('plain failure'));

        $this->assertStringContainsString('<MESSAGE>plain failure</MESSAGE>', $error);
        $this->assertStringNotContainsString('<ERRORCODE>', $error);
        // Control: an exception that does carry one still reports it.
        $error = $reflection->invoke($server, new \moodle_exception('noauthheader', 'webservice_restful', ''));
        $this->assertStringContainsString('<ERRORCODE>noauthheader</ERRORCODE>', $error);
    }
}
