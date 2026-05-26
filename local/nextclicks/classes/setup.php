<?php
namespace local_nextclicks;

defined('MOODLE_INTERNAL') || die();

/**
 * Installation and upgrade setup helpers for local_nextclicks.
 *
 * @package    local_nextclicks
 */
class setup {
    /** Web service shortname declared in db/services.php. */
    private const SERVICE_SHORTNAME = 'learner_trajectory_api';

    /** Token label shown in Moodle's web service token table. */
    private const TOKEN_NAME = 'Nextclicks learner trajectory API';

    /**
     * Ensure the learner trajectory web service has a permanent token.
     *
     * The token is generated for the primary site administrator so no separate
     * user/capability setup is required during plugin installation.
     *
     * @return string|null The token value, or null if the service/admin is not available yet.
     */
    public static function ensure_webservice_token(): ?string {
        global $DB;

        // Enable web services and the REST protocol so the token is immediately usable.
        self::enable_webservices();

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        if (!$service) {
            return null;
        }

        $admin = get_admin();
        if (!$admin || empty($admin->id)) {
            return null;
        }

        $existing = $DB->get_record('external_tokens', [
            'externalserviceid' => $service->id,
            'userid'            => $admin->id,
            'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
        ], 'id, token', IGNORE_MULTIPLE);

        if ($existing) {
            self::store_token_config($existing->token, (int)$admin->id);
            return $existing->token;
        }

        // Insert the token directly to avoid manipulating the $USER global that
        // core_external\util::generate_token() reads for creatorid.
        $tokenvalue = md5(uniqid(rand(), true));
        $DB->insert_record('external_tokens', (object)[
            'token'             => $tokenvalue,
            'userid'            => (int)$admin->id,
            'tokentype'         => EXTERNAL_TOKEN_PERMANENT,
            'externalserviceid' => (int)$service->id,
            'contextid'         => \context_system::instance()->id,
            'creatorid'         => (int)$admin->id,
            'timecreated'       => time(),
            'validuntil'        => 0,
            'iprestriction'     => '',
            'name'              => self::TOKEN_NAME,
            'lastaccess'        => null,
            'privatetoken'      => null,
            'sid'               => null,
        ]);

        self::store_token_config($tokenvalue, (int)$admin->id);
        return $tokenvalue;
    }

    /**
     * Enable Moodle web services and the REST protocol via plugin config calls.
     * This ensures a freshly installed Moodle site can serve the API without
     * any manual admin steps.
     */
    private static function enable_webservices(): void {
        // Enable the web service subsystem.
        if (!get_config('core', 'enablewebservices')) {
            set_config('enablewebservices', 1);
        }

        // Add REST to the list of active protocols.
        $current = get_config('core', 'webserviceprotocols');
        $protocols = $current ? array_map('trim', explode(',', $current)) : [];
        if (!in_array('rest', $protocols)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }
    }

    /**
     * Store token metadata in plugin config for external tooling/bootstrap.
     *
     * @param string $token Token value.
     * @param int $userid Token owner user id.
     */
    private static function store_token_config(string $token, int $userid): void {
        set_config('apitoken', $token, 'local_nextclicks');
        set_config('apitokenuserid', $userid, 'local_nextclicks');
        set_config('apitokenshortname', self::SERVICE_SHORTNAME, 'local_nextclicks');
    }
}
