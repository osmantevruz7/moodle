<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Post-install hook for local_nextclicks.
 */
function xmldb_local_nextclicks_install(): void {
    \local_nextclicks\setup::ensure_webservice_token();
}
