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
 * Dataflow settings.
 *
 * @package    tool_dataflows
 * @author     Kevin Pham <kevinpham@catalyst-au.net>
 * @copyright  Catalyst IT, 2022
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_dataflows\dataflow;
use tool_dataflows\dataflow_form;

require_once(dirname(__FILE__) . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

defined('MOODLE_INTERNAL') || die();

// The dataflow id, if not provided, it is as if the user is creating a new dataflow.
$id = optional_param('id', 0, PARAM_INT);

require_login();

$overviewurl = new moodle_url("/admin/tool/dataflows/overview.php");
$url = new moodle_url("/admin/tool/dataflows/edit.php", ['id' => $id]);
$context = context_system::instance();

// Check capabilities and setup page.
require_capability('tool/dataflows:managedataflows', $context);

// Set the PAGE URL (and mandatory context). Note the ID being recorded, this is important.
$PAGE->set_context($context);
$PAGE->set_url($url);

$persistent = null;
if (!empty($id)) {
    $persistent = new dataflow($id);
}

// Render the specific dataflow form.
$customdata = [
    'persistent' => $persistent ?? null, // An instance, or null.
    'userid' => $USER->id // For the hidden userid field.
];
$form = new dataflow_form($PAGE->url->out(false), $customdata);


if (($data = $form->get_data())) {

    try {
        if (empty($data->id)) {
            // If we don't have an ID, we know that we must create a new record.
            // Call your API to create a new persistent from this data.
            // Or, do the following if you don't want capability checks (discouraged).
            $persistent = new dataflow(0, $data);
            $persistent->create();
        } else {
            // We had an ID, this means that we are going to update a record.
            // Call your API to update the persistent from the data.
            // Or, do the following if you don't want capability checks (discouraged).
            $persistent->from_record($data);
            $persistent->update();
        }
        \core\notification::success(get_string('changessaved'));
    } catch (Exception $e) {
        \core\notification::error($e->getMessage());
    }

    // We are done, so let's redirect somewhere.
    redirect($overviewurl);
}


// Display the mandatory header and footer.
echo $OUTPUT->header();

// Output headings.
if (isset($persistent)) {
    echo $OUTPUT->heading(get_string('update_dataflow', 'tool_dataflows'));
} else {
    echo $OUTPUT->heading(get_string('new_dataflow', 'tool_dataflows'));
}

// And display the form, and its validation errors if there are any.
$form->display();

// Display footer.
echo $OUTPUT->footer();