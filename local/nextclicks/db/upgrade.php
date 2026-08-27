<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_nextclicks_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026022600) {
        // Add individual trajectory events table.
        // Unlike local_nextclicks_trans (which only stores aggregated counts),
        // this table stores one row per navigation event per user, with a
        // timestamp — giving external EDM tools a full, ordered trajectory.
        $table = new \xmldb_table('local_nextclicks_events');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemtype',    XMLDB_TYPE_CHAR,    '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('itemid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026022600, 'local', 'nextclicks');
    }

    if ($oldversion < 2026032100) {
        // Add dwell-time ping table for accurate file engagement tracking.
        $table = new \xmldb_table('local_nextclicks_dwell');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',    XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('cmid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('seconds',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('course_cmid_user', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'cmid', 'userid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026032100, 'local', 'nextclicks');
    }

    if ($oldversion < 2026052500) {
        \local_nextclicks\setup::ensure_webservice_token();

        upgrade_plugin_savepoint(true, 2026052500, 'local', 'nextclicks');
    }

    if ($oldversion < 2026052601) {
        // Re-run token setup so existing installs also get web services auto-enabled
        // and the token is stored using the new direct-insert approach.
        \local_nextclicks\setup::ensure_webservice_token();

        upgrade_plugin_savepoint(true, 2026052601, 'local', 'nextclicks');
    }

    if ($oldversion < 2026060500) {
        // Add xAPI statement table to capture H5P interactions in real time.
        // Moodle's H5P module fires \mod_h5pactivity\event\statement_received for every
        // learner interaction; our observer stores each statement here so external EDM
        // tools can access the full interaction sequence, not just the final result.
        $table = new \xmldb_table('local_nextclicks_xapi');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id',               XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('cmid',             XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('verb',             XMLDB_TYPE_CHAR,    '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('objectid',         XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('completion',       XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, 0);
            $table->add_field('success',          XMLDB_TYPE_INTEGER, '1',   null, XMLDB_NOTNULL, null, 0);
            $table->add_field('score_raw',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, 0);
            $table->add_field('score_min',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, 0);
            $table->add_field('score_max',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, 0);
            $table->add_field('duration_seconds', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, 0);
            $table->add_field('timecreated',      XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060500, 'local', 'nextclicks');
    }

    if ($oldversion < 2026082700) {
        // Add missing lookup indexes to improve query performance on every page navigation.

        // local_nextclicks_last: queried by (userid, courseid) on every page view.
        $table = new \xmldb_table('local_nextclicks_last');
        $index = new \xmldb_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // local_nextclicks_trans: queried by (courseid, source, target) on every transition upsert.
        $table = new \xmldb_table('local_nextclicks_trans');
        $index = new \xmldb_index('courseid_source_target', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'source', 'target']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026082700, 'local', 'nextclicks');
    }

    return true;
}
