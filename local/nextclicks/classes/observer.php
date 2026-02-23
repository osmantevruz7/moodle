<?php
namespace local_nextclicks;

defined('MOODLE_INTERNAL') || die();

class observer {

    private static function is_editing_noise(): bool {
        // When you are in edit mode, Moodle triggers many admin-like views.
        // We skip those to avoid polluting transitions.
        return !empty($_GET['edit']) || !empty($_POST['edit']);
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

    private static function record_transition(int $courseid, string $sourcekey, string $targetkey): void {
        global $DB;

        if ($sourcekey === $targetkey) {
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
            self::record_transition($courseid, $sourcekey, $currentkey);
        }

        self::upsert_last($userid, $courseid, 'course', $courseid);
    }

    public static function handle_coursemodule_viewed(\core\event\course_module_viewed $event): void {
        global $DB;

        if (self::is_editing_noise()) {
            return;
        }

        $userid = (int)$event->userid;
        $courseid = (int)$event->courseid;

        // For course_module_viewed, objectid is the course module id (cmid).
        $cmid = (int)$event->objectid;

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
            self::record_transition($courseid, $sourcekey, $currentkey);
        }

        self::upsert_last($userid, $courseid, 'cm', $cmid);
    }
}