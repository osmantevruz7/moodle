<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_nextclicks', get_string('pluginname', 'local_nextclicks'));

    // Ensure the token exists. install.php runs before db/services.php is
    // registered, so the first successful creation happens here instead.
    $token = get_config('local_nextclicks', 'apitoken');
    if (!$token) {
        $token = \local_nextclicks\setup::ensure_webservice_token();
    }
    $userid = (int)get_config('local_nextclicks', 'apitokenuserid');
    $shortname = get_config('local_nextclicks', 'apitokenshortname');

    if ($token) {
        $tokenlabel = get_string('settings_token_label', 'local_nextclicks');
        $tokendesc  = html_writer::tag('code', s($token), ['style' => 'word-break:break-all']) . '<br>' .
                      get_string('settings_token_desc', 'local_nextclicks', ['userid' => $userid, 'service' => s($shortname)]);
        $settings->add(new admin_setting_description('local_nextclicks/apitoken_display', $tokenlabel, $tokendesc));
    } else {
        $settings->add(new admin_setting_description(
            'local_nextclicks/apitoken_display',
            get_string('settings_token_label', 'local_nextclicks'),
            get_string('settings_token_missing', 'local_nextclicks')
        ));
    }

    $ADMIN->add('localplugins', $settings);
}
