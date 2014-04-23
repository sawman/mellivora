<?php

session_set_cookie_params(
    CONFIG_SESSION_TIMEOUT,
    '/',
    null,
    CONFIG_SSL_COMPAT,
    true
);
session_start();

function user_is_logged_in () {
    if (isset($_SESSION['id'])) {
        return $_SESSION['id'];
    }

    return false;
}

function user_is_staff () {
    if (user_is_logged_in() && $_SESSION['class'] >= CONFIG_UC_MODERATOR) {
        return true;
    }

    return false;
}

function user_class_name ($class) {
    switch ($class) {
        case CONFIG_UC_MODERATOR:
            return 'Moderator';
        case CONFIG_UC_USER:
            return 'User';
    }
}

function login_session_refresh() {

    // if users session has expired, but they have
    // the "remember me" cookie
    if (!user_is_logged_in() && login_cookie_isset()) {
        login_session_create_from_login_cookie();
    }

    if (user_is_logged_in()) {
        // check our session fingerprint. this should probably
        // be removed as it's likely to cause more problems
        // than it solves or adds security
        if ($_SESSION['fingerprint'] != get_fingerprint()) {
            logout();
        }

        session_regenerate_id(true);
    }
}

function login_create($email, $password, $remember_me) {

    if(empty($email) || empty($password)) {
        message_error('Please enter your email and password.');
    }

    $user = db_select_one(
        'users',
        array(
            'id',
            'passhash',
            'class',
            'enabled'
        ),
        array(
            'email'=>$email
        )
    );

    if (!check_passhash($password, $user['passhash'])) {
        message_error('Login failed');
    }

    if (!$user['enabled']) {
        message_generic('Ooops!', 'Your account is not enabled.
        If you have just registered, this is normal - an email with instructions will be sent out closer to the event start date!
        In all other cases, please contact the system administrator with any questions.');
    }

    login_session_create($user);

    if ($remember_me) {
        login_cookie_create($user);
    }

    log_user_ip($user['id']);

    return true;
}

function login_session_create($user) {
    $_SESSION['id'] = $user['id'];
    $_SESSION['class'] = $user['class'];
    $_SESSION['enabled'] = $user['enabled'];
    $_SESSION['fingerprint'] = get_fingerprint();
}

function login_cookie_create($user, $token_series = false) {

    $time = time();
    $ip = get_ip(true);

    if (!$token_series) {
        $token_series = generate_random_string(16);
    }
    $token = hash('sha256', generate_random_string(128));

    db_insert(
        'cookie_tokens',
        array(
            'added'=>$time,
            'ip_created'=>$ip,
            'ip_last'=>$ip,
            'user_id'=>$user['id'],
            'token_series'=>$token_series,
            'token'=>$token
        )
    );

    $cookie_content = array (
        't'=>$token,
        'ts'=>$token_series
    );

    setcookie(
        'login_tokens', // name
        json_encode($cookie_content), // content
        $time+CONFIG_COOKIE_TIMEOUT, // expiry
        '/', // path
        null, // domain
        CONFIG_SSL_COMPAT, // serve over SSL only
        true // httpOnly
    );
}

function login_cookie_destroy() {

    if (!login_cookie_isset()) {
        return;
    }

    $cookieObj = login_cookie_decode();

    db_delete(
        'cookie_tokens',
        array(
            'token'=>$cookieObj->{'t'},
            'token_series'=>$cookieObj->{'ts'}
        )
    );

    unset($_COOKIE['login_tokens']);
    setcookie('login_tokens', '', time() - 3600);
}

function login_cookie_isset() {
    return isset($_COOKIE['login_tokens']);
}

function login_cookie_decode() {

    if (!login_cookie_isset()) {
        log_exception(new Exception('Tried to decode nonexistent login cookie'));
        logout();
    }

    return json_decode($_COOKIE['login_tokens']);
}

function login_session_create_from_login_cookie() {

    if (!login_cookie_isset()) {
        log_exception(new Exception('Tried to create session from nonexistent login cookie'));
        logout();
    }

    $cookieObj = login_cookie_decode();

    $cookie_token_entry = db_select_one(
        'cookie_tokens',
        array(
            'user_id'
        ),
        array(
            'token'=>$cookieObj->{'t'},
            'token_series'=>$cookieObj->{'ts'}
        )
    );

    if (!$cookie_token_entry['user_id']) {

        /*
         * TODO, here we could check:
         *    - if the token_series matches and
         *    - the token does not match
         * this means someone has already used this cookie
         * likely had their cookie to re-authenticate.
         * This mean the cookie is likely stolen.
         */

        log_exception(new Exception('An invalid cookie was used. Likely this means someone had their cookie stolen'));
        logout();

        // explicitly exit here, even
        // though we do in redirect()
        exit();
    }

    // get the user whom this token
    // was issued for
    $user = db_select_one(
        'users',
        array(
            'id',
            'class',
            'enabled'
        ),
        array(
            'id'=>$cookie_token_entry['user_id']
        )
    );

    // remove the cookie token from the db
    // as it is used, and we don't want it
    // to every be used again
    db_delete(
        'cookie_tokens',
        array(
            'token'=>$cookieObj->{'t'},
            'token_series'=>$cookieObj->{'ts'}
        )
    );

    // issue a new login cookie for the user
    // using the same token series identifier
    login_cookie_create($user, $cookieObj->{'ts'});

    login_session_create($user);
}

function log_user_ip($userId) {

    if (!$userId) {
        message_error('No user ID was supplied to the IP logging function');
    }

    $time = time();
    $ip = get_ip(true);

    $entry = db_select_one(
        'ip_log',
        array(
            'id',
            'times_used'
        ),
        array(
            'user_id'=>$userId,
            'ip'=>$ip
        )
    );

    // if the user has logged in with this IP previously
    if ($entry['id']) {

        db_query_fetch_none('
            UPDATE ip_log SET
               last_used=UNIX_TIMESTAMP(),
               ip=:ip,
               times_used=times_used+1
            WHERE id=:id',
            array(
                'ip'=>$ip,
                'id'=>$entry['id']
            )
        );
    }
    // if this is a new IP
    else {
        db_insert(
            'ip_log',
            array(
                'added'=>$time,
                'last_used'=>$time,
                'user_id'=>$userId,
                'ip'=>$ip
            )
        );
    }
}

function make_passhash($password) {

    if (!defined('PASSWORD_DEFAULT')) {
        require_once(CONFIG_PATH_THIRDPARTY . 'password_compat/password.php');
    }

    return password_hash($password, PASSWORD_DEFAULT);
}

function check_passhash($password, $hash) {

    if (!defined('PASSWORD_DEFAULT')) {
        require_once(CONFIG_PATH_THIRDPARTY . 'password_compat/password.php');
    }

    return password_verify($password, $hash);
}

function get_fingerprint() {
    return md5(get_ip());
}

function login_session_destroy () {
    session_unset();
    session_destroy();
}

function enforce_authentication($minClass = CONFIG_UC_USER) {
    login_session_refresh();

    if (!user_is_logged_in()) {
        logout();
    }

    if ($_SESSION['class'] < $minClass) {
        log_exception(new Exception('Class less than required'));
        logout();
    }
}

function logout() {
    login_session_destroy();
    login_cookie_destroy();
    redirect(CONFIG_INDEX_REDIRECT_TO);
}

function register_account($postData) {

    if (!CONFIG_ACCOUNTS_SIGNUP_ALLOWED) {
        message_error('Registration is currently closed.');
    }

    $email = $postData[md5(CONFIG_SITE_NAME.'USR')];
    $password = $postData[md5(CONFIG_SITE_NAME.'PWD')];
    $team_name = $postData[md5(CONFIG_SITE_NAME.'TEAM')];

    if (empty($email) || empty($password) || empty($team_name) || empty($postData['type'])) {
        message_error('Please fill in all the details correctly.');
    }

    if (strlen($team_name) > CONFIG_MAX_TEAM_NAME_LENGTH || strlen($team_name) < CONFIG_MIN_TEAM_NAME_LENGTH) {
        message_error('Your team name was too long or too short.');
    }

    validate_email($email);

    if (!allowed_email($email)) {
        message_error('Email not on whitelist. Please choose a whitelisted email or contact organizers.');
    }

    $user = db_select_one(
        'users',
        array('id'),
        array(
            'team_name' => $team_name,
            'email' => $email
        ),
        null,
        'OR'
    );

    if ($user['id']) {
        message_error('An account with this team name or email already exists.');
    }

    $user_id = db_insert(
        'users',
        array(
            'email'=>$email,
            'passhash'=>make_passhash($password),
            'team_name'=>$team_name,
            'added'=>time(),
            'enabled'=>(CONFIG_ACCOUNTS_DEFAULT_ENABLED ? '1' : '0'),
            'type'=>$postData['type']
        )
    );

    // insertion was successful
    if ($user_id) {

        // log signup IP
        log_user_ip($user_id);

        // signup email
        $email_subject = 'Signup successful - account details';
        // body
        $email_body = htmlspecialchars($team_name).', your registration at '.CONFIG_SITE_NAME.' was successful.'.
            "\r\n".
            "\r\n".
            'Your username is: '.$email.
            "\r\n".
            'Your password is: ';

        $email_body .= (CONFIG_ACCOUNTS_EMAIL_PASSWORD_ON_SIGNUP ? $password : '(encrypted)');

        $email_body .=
            "\r\n".
            "\r\n".
            'Please stay tuned for updates!'.
            "\r\n".
            "\r\n".
            'Regards,'.
            "\r\n".
            CONFIG_SITE_NAME.
            "\r\n".
            CONFIG_SITE_URL;

        // send details to user
        send_email($email, $team_name, $email_subject, $email_body);

        // if account isn't enabled by default, display message and die
        if (!CONFIG_ACCOUNTS_DEFAULT_ENABLED) {
            message_generic('Signup successful', 'Thank you for registering!
            Your chosen email is: ' . htmlspecialchars($email) . '.
            Please stay tuned for updates!');
        }
        else {
            return true;
        }
    }

    // no rows were inserted
    return false;
}