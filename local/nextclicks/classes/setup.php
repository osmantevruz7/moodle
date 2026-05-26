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
        global $DB, $USER;

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
            'userid' => $admin->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ], 'id, token', IGNORE_MULTIPLE);

        if ($existing) {
            self::store_token_config($existing->token, (int)$admin->id);
            return $existing->token;
        }

        $haduser = isset($USER);
        $previoususer = $haduser ? $USER : null;
        $USER = $admin;

        try {
            $token = \core_external\util::generate_token(
                EXTERNAL_TOKEN_PERMANENT,
                $service,
                (int)$admin->id,
                \context_system::instance(),
                0,
                '',
                self::TOKEN_NAME
            );
        } finally {
            if ($haduser) {
                $USER = $previoususer;
            } else {
                unset($USER);
            }
        }

        self::store_token_config($token, (int)$admin->id);
        return $token;
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
