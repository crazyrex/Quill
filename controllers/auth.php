<?php
use Abraham\TwitterOAuth\TwitterOAuth;

function buildRedirectURI() {
  return Config::$base_url . 'auth/callback';
}

$app->get('/auth/start', function() use($app) {
  $req = $app->request();

  $params = $req->params();

  // the "me" parameter is user input, and may be in a couple of different forms:
  // aaronparecki.com http://aaronparecki.com http://aaronparecki.com/
  if(!array_key_exists('me', $params) || !($me = IndieAuth\Client::normalizeMeURL($params['me']))) {
    $html = render('auth_error', array(
      'title' => 'Sign In',
      'error' => 'Invalid "me" Parameter',
      'errorDescription' => 'The URL you entered, "<strong>' . $params['me'] . '</strong>" is not valid.'
    ));
    $app->response()->body($html);
    return;
  }

  if(k($params, 'redirect')) {
    $_SESSION['redirect_after_login'] = $params['redirect'];
  }
  if(k($params, 'reply')) {
    $_SESSION['reply'] = $params['reply'];
  }

  $_SESSION['attempted_me'] = $me;

  $_SESSION['indieauth'] = [
    'authorization_endpoint' => ($authorizationEndpoint=IndieAuth\Client::discoverAuthorizationEndpoint($me)),
    'token_endpoint' => ($tokenEndpoint=IndieAuth\Client::discoverTokenEndpoint($me)),
    'micropub_endpoint' => ($micropubEndpoint=IndieAuth\Client::discoverMicropubEndpoint($me)),
  ];

  $defaultScope = 'create update media';

  if($tokenEndpoint && $micropubEndpoint && $authorizationEndpoint) {
    // Generate a "state" parameter for the request
    $state = IndieAuth\Client::generateStateParameter();
    $_SESSION['auth_state'] = $state;

    $authorizationURL = IndieAuth\Client::buildAuthorizationURL($authorizationEndpoint, $me, buildRedirectURI(), Config::$base_url, $state, $defaultScope);
  } else {
    $authorizationURL = false;
  }

  // If the user has already signed in before and has a micropub access token,
  // and the endpoints are all the same, skip the debugging screens and redirect
  // immediately to the auth endpoint.
  // This will still generate a new access token when they finish logging in.
  $user = ORM::for_table('users')->where('url', $me)->find_one();
  if($user && $user->micropub_access_token
    && $user->micropub_endpoint == $micropubEndpoint
    && $user->token_endpoint == $tokenEndpoint
    && $user->authorization_endpoint == $authorizationEndpoint
    && !array_key_exists('restart', $params)) {

    // TODO: fix this by caching the endpoints maybe in the session instead of writing them to the DB here.
    // Then remove the line below that blanks out the access token
    $user->micropub_endpoint = $micropubEndpoint;
    $user->authorization_endpoint = $authorizationEndpoint;
    $user->token_endpoint = $tokenEndpoint;
    $user->save();

    // Request whatever scope was previously granted
    $authorizationURL = parse_url($authorizationURL);
    $authorizationURL['scope'] = $user->micropub_scope;
    $authorizationURL = http_build_url($authorizationURL);

    $app->redirect($authorizationURL, 302);

  } else {

    if(k($params, 'dontask') && $params['dontask']) {
      // Request whatever scope was previously granted
      $authorizationURL = parse_url($authorizationURL);
      $authorizationURL['scope'] = $user->micropub_scope ?: $defaultScope;
      $authorizationURL = http_build_url($authorizationURL);

      $_SESSION['dontask'] = 1;
      $app->redirect($authorizationURL, 302);
    }

    $html = render('auth_start', array(
      'title' => 'Sign In',
      'me' => $me,
      'authorizing' => $me,
      'meParts' => parse_url($me),
      'tokenEndpoint' => $tokenEndpoint,
      'micropubEndpoint' => $micropubEndpoint,
      'authorizationEndpoint' => $authorizationEndpoint,
      'authorizationURL' => $authorizationURL
    ));
    $app->response()->body($html);
  }
});

$app->get('/auth/redirect', function() use($app) {
  $req = $app->request();
  $params = $req->params();

  if(!isset($params['scope']))
    $params['scope'] = '';

  $authorizationURL = parse_url($params['authorization_url']);
  parse_str($authorizationURL['query'], $query);
  $query['scope'] = $params['scope'];
  $authorizationURL['query'] = http_build_query($query);
  $authorizationURL = http_build_url($authorizationURL);

  $app->redirect($authorizationURL);
  return;
});

$app->get('/auth/callback', function() use($app) {
  $req = $app->request();
  $params = $req->params();

  // If there is no state in the session, start the login again
  if(!array_key_exists('auth_state', $_SESSION)) {
    $html = render('auth_error', array(
      'title' => 'Auth Callback',
      'error' => 'Missing session state',
      'errorDescription' => 'Something went wrong, please try signing in again, and make sure cookies are enabled for this domain.'
    ));
    $app->response()->body($html);
    return;
  }

  if(!array_key_exists('code', $params) || trim($params['code']) == '') {
    $html = render('auth_error', array(
      'title' => 'Auth Callback',
      'error' => 'Missing authorization code',
      'errorDescription' => 'No authorization code was provided in the request.'
    ));
    $app->response()->body($html);
    return;
  }

  // Verify the state came back and matches what we set in the session
  // Should only fail for malicious attempts, ok to show a not as nice error message
  if(!array_key_exists('state', $params)) {
    $html = render('auth_error', array(
      'title' => 'Auth Callback',
      'error' => 'Missing state parameter',
      'errorDescription' => 'No state parameter was provided in the request. This shouldn\'t happen. It is possible this is a malicious authorization attempt, or your authorization server failed to pass back the "state" parameter.'
    ));
    $app->response()->body($html);
    return;
  }

  if($params['state'] != $_SESSION['auth_state']) {
    $html = render('auth_error', array(
      'title' => 'Auth Callback',
      'error' => 'Invalid state',
      'errorDescription' => 'The state parameter provided did not match the state provided at the start of authorization. This is most likely caused by a malicious authorization attempt.'
    ));
    $app->response()->body($html);
    return;
  }

  if(!isset($_SESSION['attempted_me'])) {
    $html = render('auth_error', [
      'title' => 'Auth Callback',
      'error' => 'Missing data',
      'errorDescription' => 'We forgot who was logging in. It\'s possible you took too long to finish signing in, or something got mixed up by signing in in another tab.'
    ]);
    $app->response()->body($html);
    return;
  }
  $me = $_SESSION['attempted_me'];

  // Now the basic sanity checks have passed. Time to start providing more helpful messages when there is an error.
  // An authorization code is in the query string, and we want to exchange that for an access token at the token endpoint.

  // Discover the endpoints
  $micropubEndpoint = $_SESSION['indieauth']['micropub_endpoint'];
  $tokenEndpoint = $_SESSION['indieauth']['token_endpoint'];

  if($tokenEndpoint) {
    $token = IndieAuth\Client::getAccessToken($tokenEndpoint, $params['code'], $me, buildRedirectURI(), Config::$base_url, true);
  } else {
    $token = array('auth'=>false, 'response'=>false);
  }

  $redirectToDashboardImmediately = false;

  // If a valid access token was returned, store the token info in the session and they are signed in
  if(k($token['auth'], array('me','access_token','scope'))) {
    // Double check that the domain of the returned "me" matches the expected
    if(!\p3k\url\host_matches($token['auth']['me'], $me)) {
      $html = render('auth_error', [
        'title' => 'Error Signing In',
        'error' => 'Invalid user',
        'errorDescription' => 'The user URL that was returned from the token endpoint (<code>'.$token['auth']['me'].'</code>) did not match the domain of the user signing in (<code>'.$me.'</code>).'
      ]);
      $app->response()->body($html);
      return;
    }

    $_SESSION['auth'] = $token['auth'];
    $_SESSION['me'] = $me = $token['auth']['me'];

    $user = ORM::for_table('users')->where('url', $me)->find_one();
    if($user) {
      // Already logged in, update the last login date
      $user->last_login = date('Y-m-d H:i:s');
      // If they have logged in before and we already have an access token, then redirect to the dashboard now
      if($user->micropub_access_token)
        $redirectToDashboardImmediately = true;
    } else {
      // New user! Store the user in the database
      $user = ORM::for_table('users')->create();
      $user->url = $me;
      $user->date_created = date('Y-m-d H:i:s');
    }
    $user->authorization_endpoint = $_SESSION['indieauth']['authorization_endpoint'];
    $user->token_endpoint = $tokenEndpoint;
    $user->micropub_endpoint = $micropubEndpoint;
    $user->micropub_access_token = $token['auth']['access_token'];
    $user->micropub_scope = $token['auth']['scope'];
    $user->micropub_response = $token['response'];
    $user->save();
    $_SESSION['user_id'] = $user->id();

    // Make a request to the micropub endpoint to discover the syndication targets and media endpoint if any.
    // Errors are silently ignored here. The user will be able to retry from the new post interface and get feedback.
    get_micropub_config($user, ['q'=>'config']);
  }

  unset($_SESSION['auth_state']);
  unset($_SESSION['attempted_me']);
  unset($_SESSION['indieauth']);

  if($redirectToDashboardImmediately || k($_SESSION, 'dontask')) {
    unset($_SESSION['dontask']);
    if(k($_SESSION, 'redirect_after_login')) {
      $dest = $_SESSION['redirect_after_login'];
      unset($_SESSION['redirect_after_login']);
      $app->redirect($dest, 302);
    } else {
      $query = [];
      if(k($_SESSION, 'reply')) {
        $query['reply'] = $_SESSION['reply'];
        unset($_SESSION['reply']);
      }
      $app->redirect('/new?' . http_build_query($query), 302);
    }
  } else {
    $tokenResponse = $token['response'];
    $parsed = @json_decode($tokenResponse);
    if($parsed)
      $tokenResponse = json_encode($parsed, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES);

    $html = render('auth_callback', array(
      'title' => 'Sign In',
      'me' => $me,
      'authorizing' => $me,
      'meParts' => parse_url($me),
      'tokenEndpoint' => $tokenEndpoint,
      'auth' => $token['auth'],
      'response' => $tokenResponse,
      'curl_error' => (array_key_exists('error', $token) ? $token['error'] : false),
      'destination' => (k($_SESSION, 'redirect_after_login') ?: '/new')
    ));
    $app->response()->body($html);
  }
});

$app->get('/signout', function() use($app) {
  unset($_SESSION['auth']);
  unset($_SESSION['me']);
  unset($_SESSION['auth_state']);
  unset($_SESSION['user_id']);
  $app->redirect('/', 302);
});

$app->post('/auth/reset', function() use($app) {
  if($user=require_login($app, false)) {
    revoke_micropub_token($user->micropub_access_token, $user->token_endpoint);

    $user->authorization_endpoint = '';
    $user->token_endpoint = '';
    $user->micropub_endpoint = '';
    $user->authorization_endpoint = '';
    $user->micropub_media_endpoint = '';
    $user->micropub_scope = '';
    $user->micropub_access_token = '';
    $user->save();

    unset($_SESSION['auth']);
    unset($_SESSION['me']);
    unset($_SESSION['auth_state']);
    unset($_SESSION['user_id']);
  }
  $app->redirect('/', 302);
});

$app->post('/auth/twitter', function() use($app) {
  if($user=require_login($app, false)) {
    $params = $app->request()->params();
    // User just auth'd with twitter, store the access token
    $user->twitter_access_token = $params['twitter_token'];
    $user->twitter_token_secret = $params['twitter_secret'];
    $user->save();

    $app->response()['Content-type'] = 'application/json';
    $app->response()->body(json_encode(array(
      'result' => 'ok'
    )));
  } else {
    $app->response()['Content-type'] = 'application/json';
    $app->response()->body(json_encode(array(
      'result' => 'error'
    )));
  }
});

function getTwitterLoginURL(&$twitter) {
  $request_token = $twitter->oauth('oauth/request_token', [
    'oauth_callback' => Config::$base_url . 'auth/twitter/callback'
  ]);
  $_SESSION['twitter_auth'] = $request_token;
  return $twitter->url('oauth/authorize', ['oauth_token' => $request_token['oauth_token']]);
}

$app->get('/auth/twitter', function() use($app) {
  $params = $app->request()->params();
  if($user=require_login($app, false)) {

    // If there is an existing Twitter token, check if it is valid
    // Otherwise, generate a Twitter login link
    $twitter_login_url = false;

    if(array_key_exists('login', $params)) {
      $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret);
      $twitter_login_url = getTwitterLoginURL($twitter);
    } else {
      $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret,
        $user->twitter_access_token, $user->twitter_token_secret);

      if($user->twitter_access_token) {
        if($twitter->get('account/verify_credentials')) {
          $app->response()['Content-type'] = 'application/json';
          $app->response()->body(json_encode(array(
            'result' => 'ok'
          )));
          return;
        } else {
          // If the existing twitter token is not valid, generate a login link
          $twitter_login_url = getTwitterLoginURL($twitter);
        }
      } else {
        $twitter_login_url = getTwitterLoginURL($twitter);
      }
    }

    $app->response()['Content-type'] = 'application/json';
    $app->response()->body(json_encode(array(
      'url' => $twitter_login_url
    )));

  } else {
    $app->response()['Content-type'] = 'application/json';
    $app->response()->body(json_encode(array(
      'result' => 'error'
    )));
  }
});

$app->get('/auth/twitter/callback', function() use($app) {
  if($user=require_login($app)) {
    $params = $app->request()->params();

    $twitter = new TwitterOAuth(Config::$twitterClientID, Config::$twitterClientSecret,
      $_SESSION['twitter_auth']['oauth_token'], $_SESSION['twitter_auth']['oauth_token_secret']);
    $credentials = $twitter->oauth('oauth/access_token', ['oauth_verifier' => $params['oauth_verifier']]);

    $user->twitter_access_token = $credentials['oauth_token'];
    $user->twitter_token_secret = $credentials['oauth_token_secret'];
    $user->twitter_username = $credentials['screen_name'];
    $user->save();

    $app->redirect('/settings');
  }
});
