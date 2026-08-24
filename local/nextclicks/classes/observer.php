<?php
namespace local_nextclicks;

defined('MOODLE_INTERNAL') || die();

class observer {
    /** @var int Skip duplicate transition events seen within this many seconds. */
    private const DUPLICATE_WINDOW_SECONDS = 8;

    private static function is_editing_noise(): bool {
        // When you are in edit mode, Moodle triggers many admin-like views.
        // We skip those to avoid polluting transitions.
        return !empty($_GET['edit']) || !empty($_POST['edit']);
    }

    private static function log_event(int $userid, int $courseid, string $itemtype, int $itemid): void {
        global $DB;
        $DB->insert_record('local_nextclicks_events', (object)[
            'userid'      => $userid,
            'courseid'    => $courseid,
            'itemtype'    => $itemtype,
            'itemid'      => $itemid,
            'timecreated' => time(),
        ]);
    }

    private static function upsert_last(int $userid, int $courseid, string $itemtype, int $itemid): void {
        global $DB;

        $DB->delete_records('local_nextclicks_last', [
            'userid'   => $userid,
            'courseid' => $courseid
        ]);

        $DB->insert_record('local_nextclicks_last', (object)[
            'userid'      => $userid,
            'courseid'    => $courseid,
            'itemtype'    => $itemtype,
            'itemid'      => $itemid,
            'timecreated' => time(),
        ]);
    }

    private static function should_record_transition(
        int $userid,
        int $courseid,
        string $sourcekey,
        string $targetkey
    ): bool {
        global $SESSION;

        if ($sourcekey === $targetkey) {
            return false;
        }

        if (!isset($SESSION->local_nextclicks_recent) || !is_array($SESSION->local_nextclicks_recent)) {
            $SESSION->local_nextclicks_recent = [];
        }

        $now = time();
        $key = $userid . ':' . $courseid . ':' . $sourcekey . '>' . $targetkey;
        $lasttime = $SESSION->local_nextclicks_recent[$key] ?? 0;
        if ($lasttime && ($now - (int)$lasttime) < self::DUPLICATE_WINDOW_SECONDS) {
            return false;
        }

        if (count($SESSION->local_nextclicks_recent) > 200) {
            $SESSION->local_nextclicks_recent = [];
        }
        $SESSION->local_nextclicks_recent[$key] = $now;
        return true;
    }

    private static function record_transition(int $userid, int $courseid, string $sourcekey, string $targetkey): void {
        global $DB;

        if (!self::should_record_transition($userid, $courseid, $sourcekey, $targetkey)) {
            return;
        }

        $now = time();

        $existing = $DB->get_record('local_nextclicks_trans', [
            'courseid' => $courseid,
            'source'   => $sourcekey,
            'target'   => $targetkey
        ]);

        if ($existing) {
            $existing->cnt = (int)$existing->cnt + 1;
            $existing->timemodified = $now;
            $DB->update_record('local_nextclicks_trans', $existing);
        } else {
            $DB->insert_record('local_nextclicks_trans', (object)[
                'courseid'     => $courseid,
                'source'       => $sourcekey,
                'target'       => $targetkey,
                'cnt'          => 1,
                'timemodified' => $now,
            ]);
        }
    }

    public static function handle_course_viewed(\core\event\course_viewed $event): void {
        global $DB;

        if (self::is_editing_noise()) {
            return;
        }

        $userid = (int)$event->userid;
        $courseid = (int)$event->courseid;

        if ($userid <= 0 || $courseid <= 0 || $courseid === (int)SITEID) {
            return;
        }

        $currentkey = 'course:' . $courseid;

        // Get last.
        $last = $DB->get_record('local_nextclicks_last', [
            'userid'   => $userid,
            'courseid' => $courseid
        ]);

        if ($last && (time() - (int)$last->timecreated) < 1800) {
            $sourcekey = $last->itemtype . ':' . (int)$last->itemid;
            self::record_transition($userid, $courseid, $sourcekey, $currentkey);
        }

        self::log_event($userid, $courseid, 'course', $courseid);
        self::upsert_last($userid, $courseid, 'course', $courseid);
    }

    public static function handle_h5p_statement(\mod_h5pactivity\event\statement_received $event): void {
        global $DB;

        $userid   = (int)$event->userid;
        $courseid = (int)$event->courseid;
        $cmid     = (int)$event->contextinstanceid;

        if ($userid <= 0 || $courseid <= 0 || $cmid <= 0) {
            return;
        }

        // Moodle stores the minified statement fields directly in other (verb, object, result, …).
        $stmt = $event->other;
        if (empty($stmt) || !is_array($stmt)) {
            return;
        }

        // xAPI verb IDs are full URIs — extract the local name after the last slash.
        $verburi = (string)($stmt['verb']['id'] ?? '');
        $parts   = explode('/', rtrim($verburi, '/'));
        $verb    = substr(end($parts) ?: 'unknown', 0, 100);

        // Object ID identifies which sub-content within the H5P activity was acted on.
        $objectid = substr((string)($stmt['object']['id'] ?? ''), 0, 255);

        $result          = is_array($stmt['result'] ?? null) ? $stmt['result'] : [];
        $completion      = isset($result['completion']) ? (int)(bool)$result['completion'] : 0;
        $success         = isset($result['success'])    ? (int)(bool)$result['success']    : 0;
        $score           = is_array($result['score'] ?? null) ? $result['score'] : [];
        $score_raw       = isset($score['raw']) ? (int)$score['raw'] : 0;
        $score_min       = isset($score['min']) ? (int)$score['min'] : 0;
        $score_max       = isset($score['max']) ? (int)$score['max'] : 0;

        // Duration is ISO 8601 (e.g. "PT30S", "PT1M5S") — parse to whole seconds.
        $duration_seconds = 0;
        if (!empty($result['duration'])) {
            try {
                $interval         = new \DateInterval((string)$result['duration']);
                $duration_seconds = ($interval->d * 86400)
                                  + ($interval->h * 3600)
                                  + ($interval->i * 60)
                                  + (int)$interval->s;
            } catch (\Exception $e) {
                // Malformed ISO 8601 duration — leave as 0.
            }
        }

        $DB->insert_record('local_nextclicks_xapi', (object)[
            'userid'           => $userid,
            'courseid'         => $courseid,
            'cmid'             => $cmid,
            'verb'             => $verb,
            'objectid'         => $objectid,
            'completion'       => $completion,
            'success'          => $success,
            'score_raw'        => $score_raw,
            'score_min'        => $score_min,
            'score_max'        => $score_max,
            'duration_seconds' => $duration_seconds,
            'timecreated'      => time(),
        ]);
    }

    public static function handle_coursemodule_viewed(\core\event\course_module_viewed $event): void {
        global $DB;

        if (self::is_editing_noise()) {
            return;
        }

        $userid = (int)$event->userid;
        $courseid = (int)$event->courseid;

        // For module-view events, contextinstanceid is the course_modules.id (cmid).
        // objectid is usually the activity instance id (assign.id, forum.id, ...), not cmid.
        $cmid = (int)$event->contextinstanceid;
        if ($cmid <= 0) {
            // Defensive fallback for atypical events.
            $cmid = (int)$event->objectid;
        }

        if ($userid <= 0 || $courseid <= 0 || $courseid === (int)SITEID || $cmid <= 0) {
            return;
        }

        $currentkey = 'cm:' . $cmid;

        $last = $DB->get_record('local_nextclicks_last', [
            'userid'   => $userid,
            'courseid' => $courseid
        ]);

        if ($last && (time() - (int)$last->timecreated) < 1800) {
            $sourcekey = $last->itemtype . ':' . (int)$last->itemid;
            self::record_transition($userid, $courseid, $sourcekey, $currentkey);
        }

        self::log_event($userid, $courseid, 'cm', $cmid);
        self::upsert_last($userid, $courseid, 'cm', $cmid);
    }
}
