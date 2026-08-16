<?php
namespace local_nextclicks\tests;

defined('MOODLE_INTERNAL') || die();

use local_nextclicks\observer;

/**
 * PHPUnit tests for local_nextclicks observer event handlers.
 *
 * Run with:
 *   vendor/bin/phpunit local/nextclicks/tests/observer_test.php
 *
 * @package    local_nextclicks
 * @covers     \local_nextclicks\observer
 */
class observer_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function make_user_in_course(): array {
        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        return [$user, $course];
    }

    private function cm_viewed_event($resource): \mod_resource\event\course_module_viewed {
        return \mod_resource\event\course_module_viewed::create([
            'objectid' => $resource->id,
            'context'  => \context_module::instance($resource->cmid),
        ]);
    }

    // -----------------------------------------------------------------------
    // Navigation event recording
    // -----------------------------------------------------------------------

    /**
     * Viewing a course module writes one row to local_nextclicks_events
     * with the correct itemtype ("cm") and itemid (cmid).
     */
    public function test_coursemodule_viewed_records_navigation_event(): void {
        global $DB;
        [$user, $course] = $this->make_user_in_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        observer::handle_coursemodule_viewed($this->cm_viewed_event($resource));

        $rows = $DB->get_records('local_nextclicks_events', ['userid' => $user->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals('cm',           $row->itemtype);
        $this->assertEquals($resource->cmid, (int)$row->itemid);
        $this->assertEquals($course->id,     (int)$row->courseid);
    }

    /**
     * Viewing the course homepage writes one row with itemtype "course".
     */
    public function test_course_viewed_records_navigation_event(): void {
        global $DB;
        [$user, $course] = $this->make_user_in_course();

        $event = \core\event\course_viewed::create([
            'context' => \context_course::instance($course->id),
        ]);
        observer::handle_course_viewed($event);

        $rows = $DB->get_records('local_nextclicks_events', ['userid' => $user->id]);
        $this->assertCount(1, $rows);
        $this->assertEquals('course', reset($rows)->itemtype);
    }

    // -----------------------------------------------------------------------
    // Transition recording
    // -----------------------------------------------------------------------

    /**
     * Viewing two activities in sequence creates a transition row A→B with cnt=1.
     */
    public function test_two_consecutive_views_create_transition(): void {
        global $DB;
        [$user, $course] = $this->make_user_in_course();
        $r1 = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $r2 = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        observer::handle_coursemodule_viewed($this->cm_viewed_event($r1));
        observer::handle_coursemodule_viewed($this->cm_viewed_event($r2));

        $trans = $DB->get_record('local_nextclicks_trans', [
            'courseid' => $course->id,
            'source'   => 'cm:' . $r1->cmid,
            'target'   => 'cm:' . $r2->cmid,
        ]);
        $this->assertNotFalse($trans);
        $this->assertEquals(1, (int)$trans->cnt);
    }

    /**
     * The same A→B transition fired twice within 8 seconds is deduplicated:
     * the counter stays at 1 instead of incrementing to 2.
     */
    public function test_duplicate_transition_within_window_is_not_double_counted(): void {
        global $DB;
        [$user, $course] = $this->make_user_in_course();
        $r1 = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $r2 = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        // r1 → r2 (transition recorded)
        observer::handle_coursemodule_viewed($this->cm_viewed_event($r1));
        observer::handle_coursemodule_viewed($this->cm_viewed_event($r2));

        // r2 → r1 (transition recorded)
        observer::handle_coursemodule_viewed($this->cm_viewed_event($r1));

        // r1 → r2 again immediately — same pair within 8 s, should be deduplicated
        observer::handle_coursemodule_viewed($this->cm_viewed_event($r2));

        $trans = $DB->get_record('local_nextclicks_trans', [
            'courseid' => $course->id,
            'source'   => 'cm:' . $r1->cmid,
            'target'   => 'cm:' . $r2->cmid,
        ]);
        $this->assertEquals(1, (int)$trans->cnt); // still 1, not 2
    }

    // -----------------------------------------------------------------------
    // H5P xAPI statement capture
    // -----------------------------------------------------------------------

    /**
     * An H5P statement_received event is parsed and stored with the correct
     * verb, score, completion, success, and duration values.
     */
    public function test_h5p_statement_is_stored_with_correct_fields(): void {
        global $DB;
        [$user, $course] = $this->make_user_in_course();
        $h5p = $this->getDataGenerator()->create_module('h5pactivity', ['course' => $course->id]);

        $event = \mod_h5pactivity\event\statement_received::create([
            'objectid' => $h5p->id,
            'context'  => \context_module::instance($h5p->cmid),
            'other'    => [
                'verb'   => ['id' => 'http://adlnet.gov/expapi/verbs/answered'],
                'object' => ['id' => 'http://example.com/subcontent/1'],
                'result' => [
                    'completion' => true,
                    'success'    => true,
                    'score'      => ['raw' => 8, 'min' => 0, 'max' => 10],
                    'duration'   => 'PT30S',
                ],
            ],
        ]);

        observer::handle_h5p_statement($event);

        $rows = $DB->get_records('local_nextclicks_xapi', ['userid' => $user->id]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertEquals('answered',                      $row->verb);
        $this->assertEquals('http://example.com/subcontent/1', $row->objectid);
        $this->assertEquals(1,  (int)$row->completion);
        $this->assertEquals(1,  (int)$row->success);
        $this->assertEquals(8,  (int)$row->score_raw);
        $this->assertEquals(0,  (int)$row->score_min);
        $this->assertEquals(10, (int)$row->score_max);
        $this->assertEquals(30, (int)$row->duration_seconds);
    }
}
