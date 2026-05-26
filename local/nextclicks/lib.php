<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Injects resource dwell tracker JS on file resource pages.
 *
 * @return string
 */
function local_nextclicks_before_standard_top_of_body_html(): string {
    global $PAGE;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    if (empty($PAGE->cm) || empty($PAGE->cm->modname)) {
        return '';
    }

    if ($PAGE->cm->modname !== 'resource') {
        return '';
    }

    $PAGE->requires->js_call_amd('local_nextclicks/dwelltracker', 'init', [(int)$PAGE->cm->id]);
    return '';
}
