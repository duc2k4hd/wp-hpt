<?php



define( 'ITSEC_ENCRYPTION_KEY', 'MGdVP00gZFteQFJxID9USjY9bXFKWWh2dyVeNipKJS85RHdKXVZfW19KUE8kfDprXk1PbkkwdnZpfT1mc2p0Tw==' );



if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', false );
}





 // Added by FlyingPress
 // Added by FlyingPress

 // Added by WP Rocket

 // Added by WP Rocket

 // WP-Optimize Cache

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */
// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'haiphongtech' );
/** Database username */
define( 'DB_USER', 'root' );
/** Database password */
define( 'DB_PASSWORD', '' );
/** Database hostname */
define('DB_HOST', 'localhost');
/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );
/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );
/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY','Kn.9S[!T:7xZq$kP~/^uH )l!.:+71TE35-2!1#U7/8P&3X-;;3BNg$<rfU.USt/');
define('SECURE_AUTH_KEY','*I#wmL)i403]Hh^4-I=I:7_e4XEs}FZ! -{pjsMu&RaK,w}GZeWdcvJE8)*ZB}tw');
define('LOGGED_IN_KEY','6ljQ5Suf%.w$R|%O{`v-CT{#Z)Qipn&5Sq|t21hf)w_E),B`qTKTj?wp=POF7+S4');
define('NONCE_KEY',']]y =(Rnygn/_;KkOp%e=1t8+Bop|n_AJetLN<&p.:WId/jYtKY26:yJt%hZet4M');
define('AUTH_SALT','$RttAlJ:DmF#j@3o$()+>(ux C wluOyRJCMQK$U ]6;E|dmm|3q+r>|-iBNCh(!');
define('SECURE_AUTH_SALT','~N6|>3zBnE?1+Om+S?go+K:r3_v8o,^*%cCsCDjf9^UkOInAO+`@-h#L~M+J/>Rh');
define('LOGGED_IN_SALT','=;TpP+N!e8CN4fCknm;|YPffq@T;}eRKpGr*+Aax e}<p~!:NxVV+T_o].;Snz$W');
define('NONCE_SALT','a*<hI(dl9-5Bt+SU:tKbho.R-L2WxS`v6*?sflfs/6Q0Svvejg6<j@^WY_z< V6D');
// define('ALLOW_UNFILTERED_UPLOADS', true);

/**#@-*/
/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';
/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define('JETPACK_DEV_DEBUG', true);
// define('SAVEQUERIES', false);
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

define('WP_POST_REVISIONS', false);
define('AUTOSAVE_INTERVAL', 3600);
define('WP_CRON_LOCK_TIMEOUT', 300);

define('WP_CACHE_KEY_SALT', 'haiphongtech_');

define('WP_MEMORY_LIMIT', '1024M');
define('WP_MAX_MEMORY_LIMIT', '1024M');

/* Add any custom values between this line and the "stop editing" line. */
define( 'DUPLICATOR_AUTH_KEY', '`6ph+>Vy hNBls;CvgPtOwY|b]Z%gCjl-=IQQi!jz/&32xj {nxj!~bqs_QQ)]>}' );
define('DISALLOW_FILE_EDIT', true); // chặn Editor sửa plugin/theme
define('DISALLOW_FILE_MODS', true); // chặn cài/cập nhật qua Dashboard
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );

/* That's all, stop editing! Happy publishing. */
/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';