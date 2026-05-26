<?php
defined('MOODLE_INTERNAL') || die();

// Register the external function so Moodle knows about it.
$functions = [
    'local_nextclicks_get_trajectories' => [
        'classname'   => 'local_nextclicks\external',
        'methodname'  => 'get_trajectories',
        'description' => 'Returns individual learner navigation events for EDM processing.',
        'type'        => 'read',
        'capabilities' => 'local/nextclicks:viewtrajectories',
        'ajax'        => false,
    ],
    'local_nextclicks_get_file_dwell' => [
        'classname'   => 'local_nextclicks\external',
        'methodname'  => 'get_file_dwell',
        'description' => 'Returns tracked file dwell seconds per user.',
        'type'        => 'read',
        'capabilities' => 'local/nextclicks:viewtrajectories',
        'ajax'        => false,
    ],
    'local_nextclicks_track_dwell' => [
        'classname'   => 'local_nextclicks\external',
        'methodname'  => 'track_dwell',
        'description' => 'Stores dwell-time heartbeat pings for resource modules.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];

// Pre-built service: admins can create a token for this service and
// external tools (Python scripts, EDM dashboards) call it via REST.
$services = [
    'Learner Trajectory API' => [
        'functions'       => [
            'local_nextclicks_get_trajectories',
            'local_nextclicks_get_file_dwell',
            'local_nextclicks_track_dwell',
            'core_course_get_contents',
            'gradereport_user_get_grade_items',
            'mod_quiz_get_quizzes_by_courses',
            'mod_quiz_get_user_attempts',
        ],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'learner_trajectory_api',
    ],
];
