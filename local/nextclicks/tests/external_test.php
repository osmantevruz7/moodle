<?php
namespace local_nextclicks\tests;

defined('MOODLE_INTERNAL') || die();

use local_nextclicks\external;

/**
 * PHPUnit tests for local_nextclicks external API functions.
 *
 * Run with:
 *   vendor/bin/phpunit local/nextclicks/tests/external_test.php
 *
 * @package    local_nextclicks
 * @covers     \local_nextclicks\external
 */
class external_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Navigation events seeded directly into the database are returned by
     * get_trajectories with the correct field values.
     */
    public function test_get_trajectories_returns_seeded_events(): void {
        global $DB;
        $this->setAdminUser();

        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('local_nextclicks_events', (object)[
            'userid'      => (int)$user->id,
            'courseid'    => (int)$course->id,
            'itemtype'    => 'cm',
            'itemid'      => 5,
            'timecreated' => 1000000,
        ]);

        $result = external::get_trajectories($course->id, $user->id, 0);

        $this->assertCount(1, $result);
        $this->assertEquals($user->id,   $result[0]['userid']);
        $this->assertEquals($course->id, $result[0]['courseid']);
        $this->assertEquals('cm',        $result[0]['itemtype']);
        $this->assertEquals(5,           $result[0]['itemid']);
    }

    /**
     * timespent is computed as the gap (in seconds) between consecutive events
     * for the same user and course. The last event in a session has timespent = 0
     * because there is no following event.
     */
    public function test_get_trajectories_computes_timespent_from_consecutive_events(): void {
        global $DB;
        $this->setAdminUser();

        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('local_nextclicks_events', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'itemtype' => 'cm', 'itemid' => 1, 'timecreated' => 1000000,
        ]);
        $DB->insert_record('local_nextclicks_events', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'itemtype' => 'cm', 'itemid' => 2, 'timecreated' => 1000300,
        ]);

        $result = external::get_trajectories($course->id, $user->id, 0);

        $this->assertEquals(300, $result[0]['timespent']); // 300 s gap
        $this->assertEquals(0,   $result[1]['timespent']); // last event — no next
    }

    /**
     * When the gap between two events exceeds 1800 s (30 minutes), the learner
     * is assumed to have left and returned in a new session. timespent is set to
     * 0 to avoid inflating time-on-task estimates with idle time.
     */
    public function test_get_trajectories_timespent_zero_when_gap_exceeds_session_boundary(): void {
        global $DB;
        $this->setAdminUser();

        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('local_nextclicks_events', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'itemtype' => 'cm', 'itemid' => 1, 'timecreated' => 1000000,
        ]);
        $DB->insert_record('local_nextclicks_events', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'itemtype' => 'cm', 'itemid' => 2, 'timecreated' => 1002000, // 2000 s gap
        ]);

        $result = external::get_trajectories($course->id, $user->id, 0);

        $this->assertEquals(0, $result[0]['timespent']); // gap > 1800 → 0
    }

    /**
     * The since parameter filters out events older than the given Unix timestamp,
     * allowing the notebook to fetch only new data on incremental runs.
     */
    public function test_get_trajectories_since_filter_excludes_old_events(): void {
        global $DB;
        $this->setAdminUser();

        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();

        $DB->insert_record('local_nextclicks_events', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'itemtype' => 'cm', 'itemid' => 1, 'timecreated' => 1000000,
        ]);
        $DB->insert_record('local_nextclicks_events', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'itemtype' => 'cm', 'itemid' => 2, 'timecreated' => 2000000,
        ]);

        // since=1500000 should exclude the first event (1000000) and return only the second.
        $result = external::get_trajectories(0, 0, 1500000);

        $this->assertCount(1, $result);
        $this->assertEquals(2, $result[0]['itemid']);
    }

    /**
     * get_file_dwell sums all dwell pings for the same user and file resource.
     * Multiple heartbeat pings during one session are aggregated into a single total.
     */
    public function test_get_file_dwell_aggregates_pings_per_user(): void {
        global $DB;
        $this->setAdminUser();

        $user   = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $cmid   = 7;

        $DB->insert_record('local_nextclicks_dwell', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'cmid' => $cmid, 'seconds' => 60, 'timecreated' => 1000000,
        ]);
        $DB->insert_record('local_nextclicks_dwell', (object)[
            'userid' => $user->id, 'courseid' => $course->id,
            'cmid' => $cmid, 'seconds' => 30, 'timecreated' => 1000010,
        ]);

        $result = external::get_file_dwell($course->id, $cmid, $user->id, 0, 0);

        $this->assertCount(1, $result);
        $this->assertEquals($user->id, $result[0]['userid']);
        $this->assertEquals(90, $result[0]['dwellseconds']); // 60 + 30
    }

    /**
     * track_dwell silently clamps seconds to 120 server-side, regardless of
     * what the client sends. This prevents inflated dwell totals from a
     * stalled or malicious client.
     */
    public function test_track_dwell_caps_seconds_at_120(): void {
        global $DB;

        $user     = $this->getDataGenerator()->create_user();
        $course   = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);

        external::track_dwell($resource->cmid, 200);

        $rows = $DB->get_records('local_nextclicks_dwell', ['cmid' => $resource->cmid]);
        $this->assertCount(1, $rows);
        $this->assertEquals(120, reset($rows)->seconds);
    }
}
