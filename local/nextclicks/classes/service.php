<?php
namespace local_nextclicks;

defined('MOODLE_INTERNAL') || die();

class service {

    /**
     * Return up to $limit recommendations for a given course + sourcekey.
     * 1) Prefer clickstream transitions (most frequent next targets)
     * 2) Ignore modules marked deletioninprogress
     * 3) If not enough clickstream results, fill with newly added / not-yet-seen activities in this course
     */
    public static function get_top_next(int $courseid, string $sourcekey, int $limit = 3): array {
        global $DB;

        $courseid = (int)$courseid;
        $limit = max(1, (int)$limit);

        $results = [];
        $seenkeys = [];

        // ------------------------------------------------------------
        // A) Clickstream-based recommendations
        // ------------------------------------------------------------
        $records = $DB->get_records(
            'local_nextclicks_trans',
            ['courseid' => $courseid, 'source' => $sourcekey],
            'cnt DESC',
            '*',
            0,
            $limit * 10
        );

        foreach ($records as $r) {

            // We only recommend course home or cm pages; ignore everything else.
            if (strpos($r->target, 'course:') === 0) {
                // Optional: You can exclude course-home recommendations if you want:
                // continue;

                $targetcourse = (int)substr($r->target, strlen('course:'));
                if ($targetcourse !== $courseid) {
                    continue;
                }

                $key = 'course:' . $courseid;
                if (isset($seenkeys[$key])) {
                    continue;
                }

                $results[] = [
                    'key'  => $key,
                    'name' => 'Course home',
                    'url'  => new \moodle_url('/course/view.php', ['id' => $courseid]),
                    'cnt'  => (int)$r->cnt,
                    'src'  => 'clickstream',
                ];
                $seenkeys[$key] = true;

            } else if (strpos($r->target, 'cm:') === 0) {
                $cmid = (int)substr($r->target, strlen('cm:'));
                if ($cmid <= 0) {
                    continue;
                }

                $item = self::resolve_cmid($courseid, $cmid);
                if (!$item) {
                    // If module is gone or invalid, clean transitions that point to it.
                    $DB->delete_records('local_nextclicks_trans', [
                        'courseid' => $courseid,
                        'target'   => 'cm:' . $cmid
                    ]);
                    continue;
                }

                $key = 'cm:' . $cmid;
                if (isset($seenkeys[$key])) {
                    continue;
                }

                $results[] = [
                    'key'  => $key,
                    'name' => $item['name'],
                    'url'  => $item['url'],
                    'cnt'  => (int)$r->cnt,
                    'src'  => 'clickstream',
                ];
                $seenkeys[$key] = true;
            }

            if (count($results) >= $limit) {
                return $results;
            }
        }

        // ------------------------------------------------------------
        // B) Fill with newly added / not-yet-clicked activities (fallback)
        // ------------------------------------------------------------
        // Strategy:
        // - take course modules that are visible, not deletioninprogress
        // - order by added/created time (course_modules.added if available) newest first
        // - exclude those already recommended from clickstream

        $fallback = self::get_new_course_modules($courseid, $limit * 20);

        foreach ($fallback as $f) {
            $key = 'cm:' . (int)$f['cmid'];
            if (isset($seenkeys[$key])) {
                continue;
            }

            $results[] = [
                'key'  => $key,
                'name' => $f['name'],
                'url'  => $f['url'],
                'cnt'  => 0,
                'src'  => 'new',
            ];
            $seenkeys[$key] = true;

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Resolve a course module id (cmid) to a current activity name + URL.
     * Returns null if:
     * - module missing
     * - deletioninprogress = 1
     * - cannot resolve instance name
     */
    private static function resolve_cmid(int $courseid, int $cmid): ?array {
        global $DB;

        // Load course_modules row to check deletioninprogress directly.
        $cmrow = $DB->get_record('course_modules', [
            'id' => $cmid,
            'course' => $courseid
        ], 'id, course, module, instance, visible, deletioninprogress', IGNORE_MISSING);

        if (!$cmrow || !empty($cmrow->deletioninprogress)) {
            return null;
        }

        // Get module name (assign/url/forum/etc).
        $mod = $DB->get_record('modules', ['id' => (int)$cmrow->module], 'name', IGNORE_MISSING);
        if (!$mod || empty($mod->name)) {
            return null;
        }

        $modname = $mod->name;
        $instanceid = (int)$cmrow->instance;

        // Try to fetch the activity name from the module table.
        $activity = $DB->get_record($modname, ['id' => $instanceid], 'name', IGNORE_MISSING);
        if (!$activity || empty($activity->name)) {
            return null;
        }

        return [
            'name' => format_string($activity->name),
            'url'  => new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cmid]),
        ];
    }

    /**
     * Get a list of newest valid activities (course modules) in a course.
     * Only returns modules with:
     * - visible = 1
     * - deletioninprogress = 0
     * - resolvable name
     */
    private static function get_new_course_modules(int $courseid, int $limit = 50): array {
        global $DB;

        // Some Moodle versions have course_modules.added.
        // We'll order by added DESC if present, otherwise by id DESC.
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('course_modules');
        $hasadded = $dbman->field_exists($table, new \xmldb_field('added'));

        $orderby = $hasadded ? 'cm.added DESC' : 'cm.id DESC';

        $sql = "
            SELECT cm.id AS cmid, cm.module, cm.instance
            FROM {course_modules} cm
            WHERE cm.course = :courseid
              AND cm.visible = 1
              AND cm.deletioninprogress = 0
            ORDER BY $orderby
        ";

        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, $limit);

        $out = [];
        foreach ($rows as $row) {
            $resolved = self::resolve_cmid($courseid, (int)$row->cmid);
            if (!$resolved) {
                continue;
            }
            $out[] = [
                'cmid' => (int)$row->cmid,
                'name' => $resolved['name'],
                'url'  => $resolved['url'],
            ];
        }

        return $out;
    }
}