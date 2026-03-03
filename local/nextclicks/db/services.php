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
];

// Pre-built service: admins can create a token for this service and
// external tools (Python scripts, EDM dashboards) call it via REST.
$services = [
    'Learner Trajectory API' => [
        'functions'       => ['local_nextclicks_get_trajectories'],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'learner_trajectory_api',
    ],
];
