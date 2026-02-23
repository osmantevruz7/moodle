<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_nextclicks';
$plugin->version   = 2026020401;
$plugin->requires  = 2024042200; // Moodle 4.4
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1';

$plugin->dependencies = [
    'local_nextclicks' => 2026020200
];
