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
 * Plugin settings.
 *
 * @package    webservice_restful
 * @copyright   (c) 2024, Enovation Solutions
 * @author     Lai Wei <lai.wei@enovation.ie>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    /*
     * $settings already exists: core\plugininfo\webservice::load_settings() builds the page as
     * 'webservicesetting' . $name before including this file, and Manage protocols links to that
     * section name. Creating a new admin_settingpage here replaced it with one core never links
     * to, so the "Settings" link threw sectionerror.
     */

    // Support default Accept header.
    $settings->add(new admin_setting_configcheckbox(
        'webservice_restful/supportdefaultacceptheader',
        get_string('supportdefaultacceptheader', 'webservice_restful'),
        get_string('supportdefaultacceptheaderdesc', 'webservice_restful'),
        0
    ));

    /*
     * A select rather than free text: the value is compared against the format names, so an
     * admin typing the media type 'application/json' used to turn every default-Accept request
     * into an empty XML document with no warning anywhere.
     */
    $settings->add(new admin_setting_configselect(
        'webservice_restful/defaultacceptheader',
        get_string('defaultacceptheader', 'webservice_restful'),
        get_string('defaultacceptheaderdesc', 'webservice_restful'),
        'json',
        [
            'json' => 'application/json',
            'xml' => 'application/xml',
        ]
    ));
}
