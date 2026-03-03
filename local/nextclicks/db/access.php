<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Allows an external service/token to read trajectory data via the web service.
    'local/nextclicks:viewtrajectories' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
