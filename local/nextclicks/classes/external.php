<?php
namespace local_nextclicks;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Web service: exposes learner trajectory events so external EDM tools can pull them.
 *
 * External tools authenticate with a Moodle token and call:
 *   GET /webservice/rest/server.php
 *       ?wstoken=TOKEN
 *       &wsfunction=local_nextclicks_get_trajectories
 *       &moodlewsrestformat=json
 *       &courseid=2          (optional)
 *       &userid=5            (optional)
 *       &since=1700000000    (optional Unix timestamp)
 */
class external extends external_api {

    public static function get_trajectories_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Filter by course ID — 0 means all courses', VALUE_DEFAULT, 0),
            'userid'   => new external_value(PARAM_INT, 'Filter by user ID — 0 means all users',   VALUE_DEFAULT, 0),
            'since'    => new external_value(PARAM_INT, 'Only return events after this Unix timestamp — 0 means all', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_trajectories(int $courseid = 0, int $userid = 0, int $since = 0): array {
        global $DB;

        $params = self::validate_parameters(self::get_trajectories_parameters(), [
            'courseid' => $courseid,
            'userid'   => $userid,
            'since'    => $since,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nextclicks:viewtrajectories', $context);

        $conditions = [];
        $sqlparams  = [];

        if ($params['courseid'] > 0) {
            $conditions[]          = 'courseid = :courseid';
            $sqlparams['courseid'] = (int)$params['courseid'];
        }
        if ($params['userid'] > 0) {
            $conditions[]        = 'userid = :userid';
            $sqlparams['userid'] = (int)$params['userid'];
        }
        if ($params['since'] > 0) {
            $conditions[]       = 'timecreated > :since';
            $sqlparams['since'] = (int)$params['since'];
        }

        $where   = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // LEAD() gives the next event's timestamp for the same user+course,
        // so we can compute how long they stayed on each item server-side.
        $sql     = "SELECT id, userid, courseid, itemtype, itemid, timecreated,
                           LEAD(timecreated) OVER (PARTITION BY userid, courseid ORDER BY timecreated) AS next_timecreated
                      FROM {local_nextclicks_events}
                    $where
                    ORDER BY userid ASC, timecreated ASC";

        $records = $DB->get_records_sql($sql, $sqlparams);

        $result = [];
        foreach ($records as $r) {
            // Cap at 1800 s (30 min) — a larger gap means the user left and
            // came back in a new session, so the true time on page is unknown.
            $gap       = !empty($r->next_timecreated) ? ((int)$r->next_timecreated - (int)$r->timecreated) : 0;
            $timespent = ($gap > 0 && $gap <= 1800) ? $gap : 0;

            $result[] = [
                'id'          => (int)$r->id,
                'userid'      => (int)$r->userid,
                'courseid'    => (int)$r->courseid,
                'itemtype'    => (string)$r->itemtype,
                'itemid'      => (int)$r->itemid,
                'timecreated' => (int)$r->timecreated,
                'timespent'   => $timespent,
            ];
        }

        return $result;
    }

    public static function get_trajectories_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'          => new external_value(PARAM_INT,   'Event record ID'),
                'userid'      => new external_value(PARAM_INT,   'Moodle user ID'),
                'courseid'    => new external_value(PARAM_INT,   'Course ID'),
                'itemtype'    => new external_value(PARAM_ALPHA, 'Item type: "course" or "cm"'),
                'itemid'      => new external_value(PARAM_INT,   'Activity instance (cmid) or course ID'),
                'timecreated' => new external_value(PARAM_INT,   'Unix timestamp of the navigation event'),
                'timespent'   => new external_value(PARAM_INT,   'Seconds spent on this item (0 = unknown or last event in session)'),
            ])
        );
    }

    public static function track_dwell_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID (resource)'),
            'seconds' => new external_value(PARAM_INT, 'Tracked active seconds since last ping'),
        ]);
    }

    public static function track_dwell(int $cmid, int $seconds): bool {
        global $DB, $USER;

        $params = self::validate_parameters(self::track_dwell_parameters(), [
            'cmid' => $cmid,
            'seconds' => $seconds,
        ]);

        // Avoid abuse and noisy writes.
        $seconds = max(1, min(120, (int)$params['seconds']));
        $cmid = (int)$params['cmid'];

        if (!isloggedin() || isguestuser()) {
            return false;
        }

        $cm = get_coursemodule_from_id(null, $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        // Track only file/resource modules for this telemetry.
        if ($cm->modname !== 'resource') {
            return false;
        }

        $DB->insert_record('local_nextclicks_dwell', (object)[
            'userid' => (int)$USER->id,
            'courseid' => (int)$cm->course,
            'cmid' => $cmid,
            'seconds' => $seconds,
            'timecreated' => time(),
        ]);

        return true;
    }

    public static function track_dwell_returns(): external_value {
        return new external_value(PARAM_BOOL, 'Whether the dwell ping was stored');
    }

    public static function get_file_dwell_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'cmid' => new external_value(PARAM_INT, 'Resource course module ID'),
            'userid' => new external_value(PARAM_INT, 'Optional filter by user ID (0 for all)', VALUE_DEFAULT, 0),
            'since' => new external_value(PARAM_INT, 'Optional lower timestamp bound (0 for all)', VALUE_DEFAULT, 0),
            'until' => new external_value(PARAM_INT, 'Optional upper timestamp bound (0 for all)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_file_dwell(int $courseid, int $cmid, int $userid = 0, int $since = 0, int $until = 0): array {
        global $DB;

        $params = self::validate_parameters(self::get_file_dwell_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
            'since' => $since,
            'until' => $until,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nextclicks:viewtrajectories', $context);

        $where = 'courseid = :courseid AND cmid = :cmid';
        $sqlparams = [
            'courseid' => (int)$params['courseid'],
            'cmid' => (int)$params['cmid'],
        ];

        if ((int)$params['userid'] > 0) {
            $where .= ' AND userid = :userid';
            $sqlparams['userid'] = (int)$params['userid'];
        }
        if ((int)$params['since'] > 0) {
            $where .= ' AND timecreated > :since';
            $sqlparams['since'] = (int)$params['since'];
        }
        if ((int)$params['until'] > 0) {
            $where .= ' AND timecreated <= :until';
            $sqlparams['until'] = (int)$params['until'];
        }

        $sql = "SELECT userid, SUM(seconds) AS dwellseconds
                  FROM {local_nextclicks_dwell}
                 WHERE $where
              GROUP BY userid
              ORDER BY userid ASC";
        $rows = $DB->get_records_sql($sql, $sqlparams);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'userid' => (int)$row->userid,
                'dwellseconds' => (int)$row->dwellseconds,
            ];
        }

        return $result;
    }

    public static function get_file_dwell_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
                'dwellseconds' => new external_value(PARAM_INT, 'Total tracked dwell seconds on the selected file'),
            ])
        );
    }

    public static function get_xapi_statements_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Filter by course ID — 0 means all courses', VALUE_DEFAULT, 0),
            'userid'   => new external_value(PARAM_INT, 'Filter by user ID — 0 means all users',    VALUE_DEFAULT, 0),
            'cmid'     => new external_value(PARAM_INT, 'Filter by H5P activity cmid — 0 means all', VALUE_DEFAULT, 0),
            'since'    => new external_value(PARAM_INT, 'Only return statements after this Unix timestamp — 0 means all', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_xapi_statements(int $courseid = 0, int $userid = 0, int $cmid = 0, int $since = 0): array {
        global $DB;

        $params = self::validate_parameters(self::get_xapi_statements_parameters(), [
            'courseid' => $courseid,
            'userid'   => $userid,
            'cmid'     => $cmid,
            'since'    => $since,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/nextclicks:viewtrajectories', $context);

        $conditions = [];
        $sqlparams  = [];

        if ($params['courseid'] > 0) {
            $conditions[]          = 'courseid = :courseid';
            $sqlparams['courseid'] = (int)$params['courseid'];
        }
        if ($params['userid'] > 0) {
            $conditions[]        = 'userid = :userid';
            $sqlparams['userid'] = (int)$params['userid'];
        }
        if ($params['cmid'] > 0) {
            $conditions[]      = 'cmid = :cmid';
            $sqlparams['cmid'] = (int)$params['cmid'];
        }
        if ($params['since'] > 0) {
            $conditions[]       = 'timecreated > :since';
            $sqlparams['since'] = (int)$params['since'];
        }

        $where   = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql     = "SELECT id, userid, courseid, cmid, verb, objectid,
                           completion, success, score_raw, score_min, score_max,
                           duration_seconds, timecreated
                      FROM {local_nextclicks_xapi}
                    $where
                    ORDER BY userid ASC, timecreated ASC";

        $records = $DB->get_records_sql($sql, $sqlparams);

        $result = [];
        foreach ($records as $r) {
            $result[] = [
                'id'               => (int)$r->id,
                'userid'           => (int)$r->userid,
                'courseid'         => (int)$r->courseid,
                'cmid'             => (int)$r->cmid,
                'verb'             => (string)$r->verb,
                'objectid'         => (string)$r->objectid,
                'completion'       => (int)$r->completion,
                'success'          => (int)$r->success,
                'score_raw'        => (int)$r->score_raw,
                'score_min'        => (int)$r->score_min,
                'score_max'        => (int)$r->score_max,
                'duration_seconds' => (int)$r->duration_seconds,
                'timecreated'      => (int)$r->timecreated,
            ];
        }

        return $result;
    }

    public static function get_xapi_statements_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'               => new external_value(PARAM_INT,    'Statement record ID'),
                'userid'           => new external_value(PARAM_INT,    'Moodle user ID'),
                'courseid'         => new external_value(PARAM_INT,    'Course ID'),
                'cmid'             => new external_value(PARAM_INT,    'H5P activity course module ID'),
                'verb'             => new external_value(PARAM_ALPHA,  'xAPI verb local name, e.g. answered, completed, progressed'),
                'objectid'         => new external_value(PARAM_RAW,   'xAPI object ID identifying sub-content within the H5P activity'),
                'completion'       => new external_value(PARAM_INT,    '1 if the learner completed this interaction'),
                'success'          => new external_value(PARAM_INT,    '1 if the learner succeeded'),
                'score_raw'        => new external_value(PARAM_INT,    'Raw score (0 if not reported)'),
                'score_min'        => new external_value(PARAM_INT,    'Minimum possible score (0 if not reported)'),
                'score_max'        => new external_value(PARAM_INT,    'Maximum possible score (0 if not reported)'),
                'duration_seconds' => new external_value(PARAM_INT,    'Interaction duration in seconds (0 if not reported)'),
                'timecreated'      => new external_value(PARAM_INT,    'Unix timestamp when the statement was received'),
            ])
        );
    }
}
