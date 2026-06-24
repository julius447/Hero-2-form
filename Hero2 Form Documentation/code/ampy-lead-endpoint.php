<?php
/**
 * ===========================================================================
 * AMPY LEAD ENDPOINT — REST route POST /wp-json/ampy/v1/lead
 * ===========================================================================
 * Reference backend for the Ampy Hero 2 offertformulär (the single Bricks
 * component at bricks/ampy-offert-form.html). This endpoint receives the EXACT
 * JSON payload produced by that component's buildPayload() and performs a
 * TWO-ROW write into the Ampy CRM (ampy-dash-2): one `customers` row (deduped /
 * UPSERTed on phone_e164) and one `deals` row (FK to the customer).
 *
 * ---------------------------------------------------------------------------
 * PRIME DIRECTIVE: NEVER DROP A CALLABLE LEAD.
 * A "callable lead" = full_name + phone + postal_code + consent. If those four
 * are present and the honeypot is empty, we MUST persist the lead. When any
 * OTHER field is missing, malformed, or ambiguous (e.g. missing org.nr on a
 * company lead, a non-+46 phone, an unknown enum) we PERSIST + FLAG for a human
 * booker to follow up — we do NOT reject. Rejection is reserved for: missing
 * callable-minimum, failed consent, rate-limit throttle, and bot honeypot hits.
 * ---------------------------------------------------------------------------
 *
 * INSTALL: see for-ai-agents/04-php-backend.md. Recommended: drop this in a
 * mu-plugin (wp-content/mu-plugins/ampy-lead-endpoint.php) so it loads on every
 * request, cannot be deactivated by accident, and survives theme switches.
 *
 * SOURCE OF TRUTH for the payload shape is the frontend's buildPayload():
 *   bricks/ampy-offert-form.html, function buildPayload() (~line 287).
 * Keep AOF_FIELD_WHITELIST below in lockstep with that function.
 *
 * EVERY Ampy-specific unknown (real table names, the real DB connection,
 * the published policy version, the confirmed source_form code, the exact
 * column names in ampy-dash-2) is marked [VERIFY]. Do NOT ship until each
 * [VERIFY] has been confirmed against the live ampy-dash-2 schema.
 * ===========================================================================
 */

if (!defined('ABSPATH')) {
    // Guard: never expose this file when loaded directly over HTTP.
    exit;
}

/* ===========================================================================
 * 0. CONFIGURATION — [VERIFY] every constant against the live environment.
 * ===========================================================================
 * These mirror the DEV constants at the top of the frontend script. The SERVER
 * value is authoritative for policy_version and source_form; the client value
 * is treated as ADVISORY (logged on mismatch, never trusted blindly).
 */

// [VERIFY] The server's own published privacy policy version. The client sends
// its own copy in `policy_version`; we persist OURS and log any mismatch.
// Keep in sync with POLICY_VERSION in the frontend (currently 'ampy-privacy-2026-06').
define('AOF_POLICY_VERSION', 'ampy-privacy-2026-06');

// [VERIFY] source_form code. Frontend currently sends 3 (SOURCE_FORM=3) but the
// FUNKTIONALITET.md §9.8 leaves this "to confirm with the dash team". We accept
// the client value into a whitelist of known codes and otherwise default + flag.
define('AOF_SOURCE_FORM_DEFAULT', 3);
define('AOF_SOURCE_FORM_ALLOWED', '1,3'); // [VERIFY] comma list of valid codes

// Rate-limit windows (defence-in-depth; the honeypot is the primary bot filter).
// IMPORTANT: both limiters count SUCCESSFUL leads, not attempts — a user who
// fat-fingers the form, or several visitors behind one NAT/CDN egress IP, are
// NEVER locked out for failed tries (prime directive: never drop a callable lead).
// [VERIFY] The per-IP limiter is DISABLED by default: behind a CDN, REMOTE_ADDR is
// the shared edge IP, so a per-IP cap would throttle ALL traffic. Enable it ONLY
// after aof_client_ip() is wired to the real visitor IP (see that function + the
// GO-LIVE checklist). The per-phone limiter is always safe (phone is per-person).
define('AOF_RL_IP_ENABLED', false); // [VERIFY] set true once aof_client_ip() is trusted
define('AOF_RL_IP_MAX',    20);   // max SUCCESSFUL leads per IP per window
define('AOF_RL_IP_WINDOW', 600);  // window in seconds (10 min)
define('AOF_RL_PHONE_MAX', 5);    // max SUCCESSFUL leads per phone per window
define('AOF_RL_PHONE_WINDOW', 3600); // 1 hour

// [VERIFY] CRM target. TWO modes are sketched below:
//   (A) $wpdb against tables that live in the same WP/MySQL database, OR
//   (B) a Supabase REST call to ampy-dash-2 (the CRM is described as a Supabase
//       project in FUNKTIONALITET.md §8 / MEMORY). Pick ONE; the other is a stub.
// Default reference uses $wpdb prepared statements. If ampy-dash-2 is Supabase,
// replace aof_upsert_customer() / aof_insert_deal() bodies with the [VERIFY]
// Supabase client calls marked below and delete the $wpdb path.
define('AOF_DB_MODE', 'wpdb'); // 'wpdb' | 'supabase'  [VERIFY]

// [VERIFY] real table names in ampy-dash-2. With $wpdb these are usually the WP
// prefix + name; with Supabase they are bare ('customers' / 'deals').
define('AOF_TABLE_CUSTOMERS', 'customers'); // [VERIFY]
define('AOF_TABLE_DEALS',     'deals');     // [VERIFY]

// [VERIFY] Supabase connection (only used if AOF_DB_MODE === 'supabase').
// NEVER hardcode the service-role key in a theme file. Read it from an env var
// or wp-config constant that is NOT in version control.
define('AOF_SUPABASE_URL', getenv('AMPY_SUPABASE_URL') ?: ''); // [VERIFY]
define('AOF_SUPABASE_KEY', getenv('AMPY_SUPABASE_SERVICE_KEY') ?: ''); // [VERIFY] service-role, server-only

/* ===========================================================================
 * 1. FIELD WHITELIST — must match buildPayload() exactly.
 * ===========================================================================
 * Anything NOT in this list is dropped before processing (defence against
 * payload injection / extra keys). Source: buildPayload() in the frontend.
 */
function aof_field_whitelist() {
    return array(
        'full_name',        // string  — privat: namn, org: kontaktperson
        'phone_e164',       // string  — normalised to +46… client-side; re-normalised here
        'postal_code',      // string  — digits (client strips non-digits)
        'email',            // string  — optional enrichment (but required by booking team for privat)
        'org_number',       // string|null — digits or null; NULLABLE, never blocks
        'org_name',         // string|null
        'kundtyp',          // 'privat'|'brf'|'foretag'
        'vertical',         // 'service'|'laddbox'|'batteri'|'foretag_brf'|'oklart'
        'tjanst_intresse',  // string|null  — free text the booker reads
        'bradska',          // '24h'|'72h'|'1_2v'|'flexibel'|null
        'beskrivning',      // string|null
        'street_address',   // string|null
        'bilder_count',     // number — images travel on a SEPARATE multipart channel
        'kallsida',         // string — full request path (resolver source)
        'source',           // 'bricks'
        'source_form',      // number — advisory; validated against allowlist
        'consent',          // boolean true — required
        'policy_version',   // string — advisory; we persist AOF_POLICY_VERSION
        'company_url',      // honeypot — MUST be empty
    );
}

// Enum allowlists (the form is the authority; unknown values are flagged, not rejected).
function aof_kundtyp_enum()  { return array('privat', 'brf', 'foretag'); }
function aof_vertical_enum() { return array('service', 'laddbox', 'batteri', 'foretag_brf', 'oklart'); }
function aof_bradska_enum()  { return array('24h', '72h', '1_2v', 'flexibel'); }

/* ===========================================================================
 * 2. ROUTE REGISTRATION
 * ===========================================================================
 */
add_action('rest_api_init', function () {
    register_rest_route('ampy/v1', '/lead', array(
        'methods'             => 'POST',
        'callback'            => 'aof_handle_lead',
        // Public endpoint: the form posts without auth. Security is the honeypot,
        // rate-limit, sanitisation, and the callable-minimum gate — NOT a nonce
        // (the component renders on ~165 static-cached pages where a per-request
        // nonce would be stale; see for-ai-agents/04-php-backend.md "Security").
        'permission_callback' => '__return_true',
    ));
});

/* ===========================================================================
 * 3. MAIN HANDLER
 * ===========================================================================
 */
function aof_handle_lead(WP_REST_Request $request) {

    /* --- 3.1 Parse JSON body ------------------------------------------------
     * The frontend sends Content-Type: application/json. get_json_params()
     * returns the decoded array, or null if the body was not valid JSON.
     */
    $body = $request->get_json_params();
    if (!is_array($body)) {
        return aof_response(array(
            'ok'    => false,
            'code'  => 'bad_request',
            'error' => 'Malformed JSON body.',
        ), 400);
    }

    /* --- 3.2 Apply the whitelist -------------------------------------------
     * Build a clean array containing ONLY known keys. Unknown keys are ignored.
     */
    $raw = array();
    foreach (aof_field_whitelist() as $key) {
        $raw[$key] = array_key_exists($key, $body) ? $body[$key] : null;
    }

    /* --- 3.3 HONEYPOT (first real check) ------------------------------------
     * company_url is a hidden field a human never sees. If it is non-empty, the
     * submitter is almost certainly a bot. We return a 200 OK with a FAKE
     * success body (so the bot learns nothing and stops retrying) but write
     * NOTHING to the CRM. We use a plausible-looking fake lead_id.
     */
    if (isset($raw['company_url']) && trim((string) $raw['company_url']) !== '') {
        // Optional: log silently for tuning the filter. No PII is stored.
        error_log('[aof] honeypot triggered — silently discarded');
        return aof_response(array(
            'ok'      => true,                 // fake success
            'lead_id' => aof_fake_lead_id(),   // fake id, not in DB
        ), 200);
    }

    /* --- 3.4 RATE LIMIT (per IP) — CHECK ONLY, counts successes -------------
     * We only COUNT successful writes (incremented at 3.13), so failed attempts
     * never throttle a real user. Disabled by default until the visitor IP
     * source is trusted (behind a CDN, REMOTE_ADDR is the shared edge IP).
     */
    $ip = aof_client_ip();
    if (AOF_RL_IP_ENABLED && aof_rate_over('ip_' . md5($ip), AOF_RL_IP_MAX)) {
        return aof_response(array(
            'ok'    => false,
            'code'  => 'rate_limited',
            'error' => 'För många försök. Försök igen om en stund.',
        ), 429);
    }

    /* --- 3.5 SANITISE every field ------------------------------------------
     * Free text -> sanitize_text_field / sanitize_textarea_field.
     * Identifiers / digits -> explicit normalisation below. Output escaping for
     * the booker view is a SEPARATE concern (see 04-php-backend.md "Security":
     * escape on render with esc_html(), never trust stored data as safe HTML).
     */
    $full_name      = sanitize_text_field((string) ($raw['full_name'] ?? ''));
    $email          = sanitize_email((string) ($raw['email'] ?? ''));
    $org_name       = aof_nullable_text($raw['org_name']);
    $tjanst         = aof_nullable_text($raw['tjanst_intresse']);
    $beskrivning    = aof_nullable_textarea($raw['beskrivning']);
    $street_address = aof_nullable_text($raw['street_address']);
    $kallsida       = sanitize_text_field((string) ($raw['kallsida'] ?? ''));
    $source         = sanitize_text_field((string) ($raw['source'] ?? 'bricks'));

    // Phone: client already runs toE164(); we re-normalise defensively (never
    // trust the client). Strip spaces/dashes/parens, coerce Swedish prefixes.
    $phone = aof_normalise_e164((string) ($raw['phone_e164'] ?? ''));

    // Postal code: strip ALL non-digits (spec: "strip spaces first"); the form
    // sends digits already, but a "123 45" could slip through.
    $postal_code = preg_replace('/\D+/', '', (string) ($raw['postal_code'] ?? ''));

    // org_number: digits only or null. NEVER blocks a lead.
    $org_number = null;
    if (isset($raw['org_number']) && $raw['org_number'] !== null && $raw['org_number'] !== '') {
        $org_number = preg_replace('/\D+/', '', (string) $raw['org_number']);
        if ($org_number === '') {
            $org_number = null;
        }
    }

    // Enums: keep the value but remember if it was out-of-range so we can flag.
    $kundtyp  = sanitize_text_field((string) ($raw['kundtyp'] ?? ''));
    $vertical = sanitize_text_field((string) ($raw['vertical'] ?? ''));
    $bradska  = aof_nullable_text($raw['bradska']);

    $kundtyp_ok  = in_array($kundtyp, aof_kundtyp_enum(), true);
    $vertical_ok = in_array($vertical, aof_vertical_enum(), true);
    $bradska_ok  = ($bradska === null) || in_array($bradska, aof_bradska_enum(), true);

    // bilder_count: integer >= 0. Images themselves arrive on a separate
    // multipart channel keyed by lead_id (see 04-php-backend.md "Images").
    $bilder_count = max(0, (int) ($raw['bilder_count'] ?? 0));

    // source_form: advisory. Validate against allowlist, else default + flag.
    $allowed_forms = array_map('intval', explode(',', AOF_SOURCE_FORM_ALLOWED));
    $source_form   = (int) ($raw['source_form'] ?? AOF_SOURCE_FORM_DEFAULT);
    $source_form_flagged = false;
    if (!in_array($source_form, $allowed_forms, true)) {
        $source_form_flagged = true;
        $source_form = AOF_SOURCE_FORM_DEFAULT;
    }

    /* --- 3.6 CONSENT gate ---------------------------------------------------
     * GDPR: a lead may only be stored with affirmative consent. The form sends
     * consent:true + policy_version (advisory). We require strict boolean true.
     */
    $consent = ($raw['consent'] === true || $raw['consent'] === 'true' || $raw['consent'] === 1 || $raw['consent'] === '1');
    if (!$consent) {
        return aof_response(array(
            'ok'    => false,
            'code'  => 'consent_required',
            'error' => 'Samtycke krävs för att vi ska få kontakta dig.',
        ), 422);
    }

    // policy_version: persist the SERVER's version; log any client mismatch.
    $client_policy = sanitize_text_field((string) ($raw['policy_version'] ?? ''));
    $policy_mismatch = ($client_policy !== '' && $client_policy !== AOF_POLICY_VERSION);
    if ($policy_mismatch) {
        error_log('[aof] policy_version mismatch — client=' . $client_policy . ' server=' . AOF_POLICY_VERSION);
    }

    /* --- 3.7 CALLABLE-MINIMUM gate (the only content-based rejection) -------
     * full_name + phone + postal_code + consent. Consent already checked above.
     * Phone is validated as GENERIC E.164 (^\+\d{8,15}$). A non-+46 number is
     * NOT rejected — it is accepted and flagged for review (the booker may have
     * an international customer). Only a phone that cannot be coerced into ANY
     * plausible E.164 shape fails the callable minimum.
     */
    $errors = array();
    if ($full_name === '' || mb_strlen($full_name) < 2) {
        $errors['full_name'] = 'Namn saknas.';
    }
    if (!preg_match('/^\+\d{8,15}$/', $phone)) {
        $errors['phone_e164'] = 'Telefonnummer saknas eller går inte att tolka.';
    }
    if (!preg_match('/^\d{5}$/', $postal_code)) {
        // 5 digits after stripping spaces. Swedish postnr is always 5 digits.
        $errors['postal_code'] = 'Postnummer ska vara fem siffror.';
    }
    if (!empty($errors)) {
        // This is the one place we refuse: without these we have no callable lead.
        return aof_response(array(
            'ok'     => false,
            'code'   => 'missing_required',
            'error'  => 'Obligatoriska uppgifter saknas eller är felaktiga.',
            'fields' => $errors,
        ), 422);
    }

    /* --- 3.8 RATE LIMIT (per phone) — CHECK ONLY, counts successes ----------
     * Throttle repeated SUCCESSFUL leads from the same number (counted at 3.13).
     * Checked here, after callable-minimum, so only valid leads ever count and a
     * stuck retry that keeps failing validation never locks the number out.
     */
    if (aof_rate_over('phone_' . md5($phone), AOF_RL_PHONE_MAX)) {
        return aof_response(array(
            'ok'    => false,
            'code'  => 'rate_limited',
            'error' => 'För många försök från detta nummer. Försök igen senare.',
        ), 429);
    }

    /* --- 3.9 BUILD REVIEW FLAGS --------------------------------------------
     * Persist-and-flag, never reject. The booker queue reads `review_flags`.
     */
    $flags = array();

    // Missing/malformed org.nr on a company/BRF lead. DECISION (owner-flippable):
    // org.nr stays OPTIONAL; we flag instead of rejecting. To make it MANDATORY
    // later, flip exactly ONE block — see the marked spot below ([ORG_NR_POLICY]).
    if (in_array($kundtyp, array('brf', 'foretag'), true)) {
        if ($org_number === null || !aof_orgnr_plausible($org_number)) {
            $flags[] = 'orgnr_missing_or_malformed';
        }
        if ($org_name === null || $org_name === '') {
            $flags[] = 'orgname_missing';
        }
    }
    if (!$kundtyp_ok)  { $flags[] = 'kundtyp_unknown:' . substr($kundtyp, 0, 32); }
    if (!$vertical_ok) { $flags[] = 'vertical_unknown:' . substr($vertical, 0, 32); }
    if (!$bradska_ok)  { $flags[] = 'bradska_unknown:' . substr((string) $bradska, 0, 32); }
    if (!preg_match('/^\+46\d{6,13}$/', $phone)) { $flags[] = 'phone_non_se'; }
    if ($email === '') { $flags[] = 'email_missing'; }       // privat needs e-post per booking team
    if ($kundtyp === 'privat' && ($street_address === null || $street_address === '')) {
        $flags[] = 'address_missing_privat';                 // privat needs adress per booking team
    }
    if ($source_form_flagged) { $flags[] = 'source_form_unknown'; }
    if ($policy_mismatch)     { $flags[] = 'policy_version_mismatch'; }

    /* --- [ORG_NR_POLICY] ----------------------------------------------------
     * OWNER-FLIPPABLE: to make org.nr MANDATORY for brf/foretag, UNCOMMENT this
     * single block. It is the ONLY change required. Leaving it commented keeps
     * the first-principles moat (never drop a callable lead).
     *
     * if (in_array($kundtyp, array('brf','foretag'), true)
     *     && ($org_number === null || !aof_orgnr_plausible($org_number))) {
     *     return aof_response(array(
     *         'ok'=>false,'code'=>'orgnr_required',
     *         'error'=>'Organisationsnummer krävs för företag och förening.',
     *     ), 422);
     * }
     * ----------------------------------------------------------------------- */

    /* --- 3.10 SERVER-STAMPED consent timestamp -----------------------------
     * current_time('mysql', true) => UTC 'Y-m-d H:i:s'. The client never sends
     * consent_at; the SERVER is the legal record of WHEN consent was given.
     */
    $consent_at = current_time('mysql', true);

    /* --- 3.11 TWO-ROW WRITE ------------------------------------------------
     * UPSERT customer (dedupe on phone_e164) -> get customer_id -> insert deal.
     * If the customer write fails we MUST NOT lose the lead: we log loudly and
     * still attempt the deal write with a null FK + a flag, so a human sees it.
     */
    $customer_id = aof_upsert_customer(array(
        'full_name'      => $full_name,
        'phone_e164'     => $phone,
        'email'          => ($email !== '' ? $email : null),
        'street_address' => $street_address,
        'postal_code'    => $postal_code,
        'org_number'     => $org_number,
        'org_name'       => $org_name,
    ));

    if ($customer_id === false) {
        // Persist-and-flag rather than drop: record the failure so it can be
        // recovered. We still try to write the deal with a null customer FK.
        error_log('[aof] customer upsert FAILED for phone=' . $phone . ' — writing deal with null FK');
        $flags[] = 'customer_upsert_failed';
        $customer_id = null;
    }

    $lead_id = aof_insert_deal(array(
        'customer_id'      => $customer_id,
        'vertical'         => $vertical,
        'kundtyp'          => $kundtyp,
        'tjanst_intresse'  => $tjanst,
        'bradska'          => $bradska,
        'beskrivning'      => $beskrivning,
        'kallsida'         => $kallsida,
        'source'           => $source,
        'source_form'      => $source_form,
        'consent_at'       => $consent_at,
        'policy_version'   => AOF_POLICY_VERSION,
        'bilder_count'     => $bilder_count,
        'review_flags'     => $flags,           // stored as JSON / text — see migration
    ));

    if ($lead_id === false) {
        // The deal is the lead. If THIS fails we genuinely cannot return a
        // lead_id. Log the full sanitised payload so the lead can be recovered
        // by hand — a dropped lead is the worst outcome.
        error_log('[aof] deal insert FAILED — recoverable payload: ' . wp_json_encode(array(
            'full_name' => $full_name, 'phone' => $phone, 'postal_code' => $postal_code,
            'kundtyp' => $kundtyp, 'vertical' => $vertical, 'kallsida' => $kallsida,
        )));
        return aof_response(array(
            'ok'    => false,
            'code'  => 'persist_failed',
            'error' => 'Kunde inte spara just nu. Försök igen eller ring oss.',
        ), 500);
    }

    /* --- 3.13 COUNT THIS SUCCESS toward the rate limits --------------------
     * Only persisted leads count, so failed/invalid attempts never throttle a
     * legitimate user. Per-IP increments only when the limiter is enabled.
     */
    if (AOF_RL_IP_ENABLED) { aof_rate_hit('ip_' . md5($ip), AOF_RL_IP_WINDOW); }
    aof_rate_hit('phone_' . md5($phone), AOF_RL_PHONE_WINDOW);

    /* --- 3.12 SUCCESS -------------------------------------------------------
     * 2xx is returned ONLY after a successful deal write. The frontend treats
     * any non-2xx as an error and shows the "ring oss" fallback, so we never
     * 2xx without a persisted, callable lead. lead_id enables the optional
     * enrichment PATCH and the future multipart image upload.
     */
    return aof_response(array(
        'ok'      => true,
        'lead_id' => $lead_id,
    ), 201);
}

/* ===========================================================================
 * 4. PERSISTENCE — $wpdb reference (swap for Supabase if AOF_DB_MODE='supabase')
 * ===========================================================================
 */

/**
 * UPSERT a customer row, deduped on phone_e164. Returns customer_id (int|string)
 * or false on failure. Uses a prepared SELECT then UPDATE/INSERT to stay portable
 * across MySQL versions (a raw ON DUPLICATE KEY requires a unique index on
 * phone_e164 — the migration adds one, see ampy-dash-2-migration.sql).
 *
 * [VERIFY] column names below against the real ampy-dash-2 `customers` schema.
 */
function aof_upsert_customer($c) {
    if (AOF_DB_MODE === 'supabase') {
        return aof_supabase_upsert_customer($c); // [VERIFY] stub below
    }

    global $wpdb;
    $table = AOF_TABLE_CUSTOMERS; // [VERIFY] e.g. $wpdb->prefix . 'customers'

    // Find existing by phone (dedupe key).
    $existing = $wpdb->get_var(
        $wpdb->prepare("SELECT id FROM `{$table}` WHERE phone_e164 = %s LIMIT 1", $c['phone_e164'])
    );

    if ($existing) {
        // UPDATE: fill blanks but do not wipe existing data with nulls. We use
        // COALESCE so a later, richer submission enriches an existing customer.
        $ok = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET
                full_name      = %s,
                email          = COALESCE(NULLIF(%s,''), email),
                street_address = COALESCE(NULLIF(%s,''), street_address),
                postal_code    = COALESCE(NULLIF(%s,''), postal_code),
                org_number     = COALESCE(NULLIF(%s,''), org_number),
                org_name       = COALESCE(NULLIF(%s,''), org_name)
             WHERE id = %d",
            $c['full_name'],
            (string) ($c['email'] ?? ''),
            (string) ($c['street_address'] ?? ''),
            (string) ($c['postal_code'] ?? ''),
            (string) ($c['org_number'] ?? ''),
            (string) ($c['org_name'] ?? ''),
            $existing
        ));
        return ($ok === false) ? false : $existing;
    }

    // INSERT new customer. $wpdb->insert() prepares + escapes each value.
    $ok = $wpdb->insert($table, array(
        'full_name'      => $c['full_name'],
        'phone_e164'     => $c['phone_e164'],
        'email'          => $c['email'],
        'street_address' => $c['street_address'],
        'postal_code'    => $c['postal_code'],
        'org_number'     => $c['org_number'],
        'org_name'       => $c['org_name'],
    ));
    return ($ok === false) ? false : $wpdb->insert_id;
}

/**
 * INSERT a deal row with the customer FK. Returns lead_id or false.
 * [VERIFY] column names against the real ampy-dash-2 `deals` schema. In
 * particular: is the source-page column `kallsida` or `lead_magnet_slug`?
 * FUNKTIONALITET.md §7 says the form REUSES `lead_magnet_slug` as kallsida.
 * Adjust the key below to match production.
 */
function aof_insert_deal($d) {
    if (AOF_DB_MODE === 'supabase') {
        return aof_supabase_insert_deal($d); // [VERIFY] stub below
    }

    global $wpdb;
    $table = AOF_TABLE_DEALS; // [VERIFY] e.g. $wpdb->prefix . 'deals'

    $ok = $wpdb->insert($table, array(
        'customer_id'     => $d['customer_id'],            // FK (nullable on failure)
        'vertical'        => $d['vertical'],
        'kundtyp'         => $d['kundtyp'],
        'tjanst_intresse' => $d['tjanst_intresse'],
        'bradska'         => $d['bradska'],
        'beskrivning'     => $d['beskrivning'],
        // [VERIFY] rename to 'lead_magnet_slug' if that is the live column:
        'kallsida'        => $d['kallsida'],
        'source'          => $d['source'],
        'source_form'     => $d['source_form'],
        'consent_at'      => $d['consent_at'],             // server UTC timestamp
        'policy_version'  => $d['policy_version'],
        'bilder_count'    => $d['bilder_count'],
        'review_flags'    => wp_json_encode($d['review_flags']), // JSON text/jsonb
    ));
    return ($ok === false) ? false : $wpdb->insert_id;
}

/* ---------------------------------------------------------------------------
 * 4b. SUPABASE STUBS — [VERIFY] only used if AOF_DB_MODE === 'supabase'.
 * Replace the bodies with the real ampy-dash-2 REST/RPC calls. Use a Postgres
 * RPC (a stored function) to do the customer-UPSERT + deal-INSERT atomically
 * and return lead_id in one round trip — that is cleaner than two REST calls.
 * ------------------------------------------------------------------------- */
function aof_supabase_upsert_customer($c) {
    // [VERIFY] If you use an atomic RPC for the whole two-row write, this is a
    // no-op and aof_supabase_insert_deal() does everything. Stub returns false
    // so a misconfigured Supabase mode fails loudly rather than silently.
    error_log('[aof] aof_supabase_upsert_customer is a [VERIFY] stub — implement before enabling supabase mode');
    return false;
}
function aof_supabase_insert_deal($d) {
    // Example shape of the real call (DO NOT ship as-is):
    //   $res = wp_remote_post(AOF_SUPABASE_URL . '/rest/v1/rpc/ampy_create_lead', array(
    //     'headers' => array(
    //        'apikey'        => AOF_SUPABASE_KEY,
    //        'Authorization' => 'Bearer ' . AOF_SUPABASE_KEY,
    //        'Content-Type'  => 'application/json',
    //        'Prefer'        => 'return=representation',
    //     ),
    //     'body' => wp_json_encode($d),  // RPC arg names must match the SQL fn
    //     'timeout' => 8,
    //   ));
    //   if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 300) return false;
    //   $row = json_decode(wp_remote_retrieve_body($res), true);
    //   return $row['lead_id'] ?? false;
    error_log('[aof] aof_supabase_insert_deal is a [VERIFY] stub — implement before enabling supabase mode');
    return false;
}

/* ===========================================================================
 * 5. HELPERS
 * ===========================================================================
 */

/** Build a WP_REST_Response with a status code. JSON is emitted by WP. */
function aof_response($data, $status) {
    return new WP_REST_Response($data, $status);
}

/** A plausible-looking fake id for honeypot replies (never persisted). */
function aof_fake_lead_id() {
    return 'lead_' . wp_generate_password(12, false, false);
}

/** Trim text to a null-or-string. Free text uses sanitize_text_field. */
function aof_nullable_text($v) {
    if ($v === null) return null;
    $v = sanitize_text_field((string) $v);
    return ($v === '') ? null : $v;
}
/** Multi-line free text (description) uses sanitize_textarea_field. */
function aof_nullable_textarea($v) {
    if ($v === null) return null;
    $v = sanitize_textarea_field((string) $v);
    return ($v === '') ? null : $v;
}

/**
 * Server-side E.164 normalisation — mirrors the frontend toE164() so we never
 * trust the client. Strips spaces/dashes/parens, then:
 *   00…  -> +…      (international prefix)
 *   0…   -> +46…    (Swedish national)
 *   46…  -> +46…
 *   digits with no leading 0 -> +46… (Swedish mobile typed without the 0)
 * A value already starting with + is left as-is (after cleaning separators).
 */
function aof_normalise_e164($s) {
    $s = preg_replace('/[\s\-()]/', '', (string) $s);
    if ($s === '') return '';
    if (strpos($s, '+') === 0) {
        return '+' . preg_replace('/\D+/', '', substr($s, 1));
    }
    if (strpos($s, '00') === 0) {
        return '+' . substr($s, 2);
    }
    if (strpos($s, '0') === 0) {
        return '+46' . substr($s, 1);
    }
    if (strpos($s, '46') === 0) {
        return '+' . $s;
    }
    if (preg_match('/^\d/', $s)) {
        return '+46' . $s;
    }
    return $s;
}

/**
 * Plausible Swedish org.nr = 10 digits (often written NNNNNN-NNNN). We accept
 * 10 OR 12 (some systems prefix the century). This is a PLAUSIBILITY check for
 * FLAGGING only — it never blocks a lead.
 * [VERIFY] tighten with the Luhn checksum if the dash team wants stricter flags.
 */
function aof_orgnr_plausible($digits) {
    $len = strlen((string) $digits);
    return ($len === 10 || $len === 12);
}

/** Best-effort client IP for rate-limiting. */
function aof_client_ip() {
    // [VERIFY] If the site sits behind a proxy/CDN (Cloudflare etc.), read the
    // trusted forwarded header instead of REMOTE_ADDR. Do NOT blindly trust
    // X-Forwarded-For (spoofable) unless your edge sets it authoritatively.
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/**
 * Transient-based rate limiter, split into CHECK and HIT so we can count only
 * SUCCESSFUL leads (hit after a persisted write) rather than every attempt.
 * This is the key safety property: a user who fails validation, or many users
 * behind one shared egress IP, are never throttled for non-leads.
 */
function aof_rate_over($key, $max) {
    return ((int) get_transient('aof_rl_' . $key)) >= $max;
}
function aof_rate_hit($key, $window) {
    $tkey  = 'aof_rl_' . $key;
    $count = (int) get_transient($tkey);
    // set_transient resets the TTL each write — acceptable for abuse throttling
    // (a sustained burst keeps extending its own window).
    set_transient($tkey, $count + 1, $window);
}
