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

    return true;
}
