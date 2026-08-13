# Changelog

All notable changes to this plugin are documented here.

## [Unreleased] — MOODLE_501_STABLE

First release of the branch targeting Moodle 5.1 and 5.2. Branched from
`MOODLE_402_STABLE` (Moodle 4.2–4.5).

### Compatibility

- `$plugin->supported` is now `[501, 502]` and `$plugin->requires` is `2025100600`
  (Moodle 5.1). Verified installing and running on 5.1.6 and 5.2.2, PHP 8.4.
- Confirmed the plugin's slash-argument URL form
  (`/webservice/restful/server.php/<function>`) still resolves on 5.1+ with the
  Routing Engine enabled (`$CFG->routerconfigured = true`), and under the 5.x
  `public/` split document root.
- CI now calls the moodle-an-hochschulen reusable workflow, one job per supported
  branch. The previous Catalyst workflow gated every job behind
  `github.ref_protected`, so on an unprotected branch it ran nothing on push and
  still reported success — a green check meant only that no job had run.

### Fixed

- **XML responses were always empty.** `xmlize_result()` tested `instanceof`
  against `external_value`, `external_single_structure` and
  `external_multiple_structure` in the global namespace, but the file imported
  only `external_api` and `external_settings`. Those global names exist solely as
  `class_alias()` calls in `lib/externallib.php`, which nothing loads during a web
  service request, so every test was false and every XML body came back as an
  empty `<RESPONSE>` with HTTP 200. The three classes are now imported from
  `core_external`, and an unrenderable description throws instead of returning
  null. Affected 4.5 as well as 5.x.
- **Content negotiation ignored real Accept headers.** `ltrim($header,
  'application/')` treats its second argument as a character mask, not a prefix,
  so `application/json, text/plain, */*` (sent by axios and most HTTP clients)
  and `*/*` (curl's default) both fell through to the XML branch — and therefore
  returned the empty document above. Accept and Content-Type are now parsed as
  media types, with q-value ranking.
- **The documented `?file=` URL form could never dispatch.** Every `$_GET` key was
  merged into the web service parameters, and `validate_parameters()` rejects
  unexpected keys, so the call failed with `Unexpected keys (file) detected in
  parameter array`. The routing variable is now excluded from the merge.
- **An unrecognised Content-Type silently discarded the request body**, falling
  through to `$_POST` (empty for a JSON body) and executing the function with no
  parameters — turning a filtered query into an unfiltered one, with HTTP 200.
  Unsupported media types now return 415.
- **An empty `Authorization` or `Content-Type` value aborted with HTTP 200 and a
  zero-length body**, because `parse_request()` read a falsy return as "an error
  was already reported". Errors are now tracked explicitly, so the most common
  credential mistake returns 401 with an error document.
- **A malformed or scalar request body was treated as "no parameters"** and the
  call succeeded. It now returns 400.
- `generate_error()` read `$ex->errorcode` unguarded in the XML branch, raising a
  PHP warning and passing null to `htmlspecialchars()` for any exception without
  one. The JSON branch already guarded it.
- Error responses could ship a JSON body under `Content-Type: application/xml`;
  body format and declared content type now key off the same value.
- The `Bearer` prefix was stripped with an unanchored, case-sensitive
  `str_replace`, so a lowercase scheme was passed through verbatim and a token
  containing the word anywhere was mangled. It is now anchored and
  case-insensitive per RFC 6750, and the value is trimmed.
- The `REQUEST_URI` fallback in `get_wsfunction()` carried the query string into
  the function name.
- `run()` dropped the base class's `moodlewssettingtimezone` handling, so the
  per-request timezone was parsed and then ignored.
- `settings.php` replaced the settings page core had already built, under a
  section name core does not link to, so the **Settings** link on *Manage
  protocols* threw `sectionerror`. The two settings were only reachable by
  hand-typing the URL.
- `defaultacceptheader` was free text used verbatim: typing the media type
  `application/json` into it made every default-Accept request return an empty XML
  document. It is now a select.
- `server.php` did not define `raise_early_ws_exception()`, so any bootstrap-time
  exception reached the client as a zero-length `text/html` 500 instead of a
  parseable error document.

### Changed

- `x-www-form-urlencoded` bodies are now parsed on PUT/PATCH/DELETE, where PHP
  does not populate `$_POST`.
- Removed the `legacy_polyfill` trait from the privacy provider; it was a
  Moodle 3.3-era shim whose `get_reason()` the class already overrode.
- Added `lang/pt_br`, kept in lockstep with `lang/en`.

### Tests

- 9 tests / 21 assertions grew to 45 / 82, with regression coverage for each bug
  above: Accept and Content-Type negotiation tables, the `Bearer` variants,
  `xmlize_result()` against a real core return description, the `?file=`
  exclusion, and the errorcode-less XML error path. The four pre-existing
  error-path tests now also assert the return value, without which a method could
  report an error and still hand a usable value back to `parse_request()`.
