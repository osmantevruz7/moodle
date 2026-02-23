<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\course_viewed',
        'callback'    => '\local_nextclicks\observer::handle_course_viewed',
        'includefile' => '/local/nextclicks/classes/observer.php',
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\core\event\course_module_viewed',
        'callback'    => '\local_nextclicks\observer::handle_coursemodule_viewed',
        'includefile' => '/local/nextclicks/classes/observer.php',
        'priority'    => 9999,
    ],
];