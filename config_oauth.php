<?php
// /sweepxpress/google_oauth.php

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env file located in the sweepxpress root
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad(); // hindi nagka-crash kapag walang .env (hal. sa bagong server)

// Define constants from .env
define('GOOGLE_CLIENT_ID', $_ENV['GOOGLE_CLIENT_ID'] ?? '');
define('GOOGLE_CLIENT_SECRET', $_ENV['GOOGLE_CLIENT_SECRET'] ?? '');
define('GOOGLE_REDIRECT_URI', $_ENV['GOOGLE_REDIRECT_URI'] ?? '');

define('FACEBOOK_APP_ID', $_ENV['FACEBOOK_APP_ID'] ?? '');
define('FACEBOOK_APP_SECRET', $_ENV['FACEBOOK_APP_SECRET'] ?? '');
define('FACEBOOK_REDIRECT_URI', $_ENV['FACEBOOK_REDIRECT_URI'] ?? '');
