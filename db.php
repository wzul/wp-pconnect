<?php
/**
 * WordPress DB drop-in replacement for persistent database connections
 *
 * WordPress Trac Ticket {@link https://core.trac.wordpress.org/ticket/31018#comment:3}
 * Code taken from {@link https://wordpress.org/plugins/persistent-database-connection-updater/}
 * 
 */

/**
 * A Subclass of wpdb, a WordPress Database Access Abstraction Object
 *
 * To use, this file needs to be placed in wp-content
 * and will be used instead of wpdb directly.
 *
 * @link http://codex.wordpress.org/Function_Reference/wpdb_Class
 *
 */
class persistent_wpdb extends wpdb {

	/**
	 * Connects to and selects database.
	 *
	 * If `$allow_bail` is false, the lack of database connection will need to be handled manually.
	 *
	 * @since 3.0.0
	 * @since 3.9.0 $allow_bail parameter added.
	 *
	 * @param bool $allow_bail Optional. Allows the function to bail. Default true.
	 * @return bool True with a successful connection, false on failure.
	 */
	public function db_connect( $allow_bail = true ) {
		$this->is_mysql = true;

		$client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

		/*
		 * Set the MySQLi error reporting off because WordPress handles its own.
		 * This is due to the default value change from `MYSQLI_REPORT_OFF`
		 * to `MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT` in PHP 8.1.
		 */
		mysqli_report( MYSQLI_REPORT_OFF );

		$this->dbh = mysqli_init();

		$host    = $this->dbhost;
		$port    = null;
		$socket  = null;
		$is_ipv6 = false;

		$host_data = $this->parse_db_host( $this->dbhost );
		if ( $host_data ) {
			list( $host, $port, $socket, $is_ipv6 ) = $host_data;
		}

		/*
		 * If using the `mysqlnd` library, the IPv6 address needs to be enclosed
		 * in square brackets, whereas it doesn't while using the `libmysqlclient` library.
		 * @see https://bugs.php.net/bug.php?id=67563
		 */
		if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
			$host = "[$host]";
		}

		if ( WP_DEBUG ) {
			mysqli_real_connect( $this->dbh, 'p:' . $host, $this->dbuser, $this->dbpassword, null, $port, $socket, $client_flags );
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@mysqli_real_connect( $this->dbh, 'p:' . $host, $this->dbuser, $this->dbpassword, null, $port, $socket, $client_flags );
		}

		if ( $this->dbh->connect_errno ) {
			$this->dbh = null;
		}

		if ( ! $this->dbh && $allow_bail ) {
			wp_load_translations_early();

			// Load custom DB error template, if present.
			if ( file_exists( WP_CONTENT_DIR . '/db-error.php' ) ) {
				require_once WP_CONTENT_DIR . '/db-error.php';
				die();
			}

			$message = '<h1>' . __( 'Error establishing a database connection' ) . "</h1>\n";

			$message .= '<p>' . sprintf(
				/* translators: 1: wp-config.php, 2: Database host. */
				__( 'This either means that the username and password information in your %1$s file is incorrect or that contact with the database server at %2$s could not be established. This could mean your host&#8217;s database server is down.' ),
				'<code>wp-config.php</code>',
				'<code>' . htmlspecialchars( $this->dbhost, ENT_QUOTES ) . '</code>'
			) . "</p>\n";

			$message .= "<ul>\n";
			$message .= '<li>' . __( 'Are you sure you have the correct username and password?' ) . "</li>\n";
			$message .= '<li>' . __( 'Are you sure you have typed the correct hostname?' ) . "</li>\n";
			$message .= '<li>' . __( 'Are you sure the database server is running?' ) . "</li>\n";
			$message .= "</ul>\n";

			$message .= '<p>' . sprintf(
				/* translators: %s: Support forums URL. */
				__( 'If you are unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href="%s">WordPress support forums</a>.' ),
				__( 'https://wordpress.org/support/forums/' )
			) . "</p>\n";

			$this->bail( $message, 'db_connect_fail' );

			return false;
		} elseif ( $this->dbh ) {
			if ( ! $this->has_connected ) {
				$this->init_charset();
			}

			$this->has_connected = true;

			$this->set_charset( $this->dbh );

			$this->ready = true;
			$this->set_sql_mode();
			$this->select( $this->dbname, $this->dbh );

			return true;
		}

		return false;
	}

}

$GLOBALS['wpdb'] = new persistent_wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
