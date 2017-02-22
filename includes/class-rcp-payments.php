<?php
/**
 * RCP Payments class
 *
 * This class handles querying, inserting, updating, and removing payments
 * Also handles calculating earnings
 *
 * @package     Restrict Content Pro
 * @subpackage  Classes/Payments
 * @copyright   Copyright (c) 2017, Restrict Content Pro
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.5
 */

class RCP_Payments {

	/**
	 * Holds the name of our payments database table
	 *
	 * @access  public
	 * @since   1.5
	 */
	public $db_name;

	/**
	 * Holds the name of our payment meta database table
	 *
	 * @access  public
	 * @since   2.6
	 */
	public $meta_db_name;

	/**
	 * Holds the version number of our discounts database table
	 *
	 * @access  public
	 * @since   1.5
	 */
	public $db_version;

	/**
	 * Get things going.
	 *
	 * @return void
	 */
	function __construct() {

		$this->db_name      = rcp_get_payments_db_name();
		$this->meta_db_name = rcp_get_payment_meta_db_name();
		$this->db_version   = '1.5';

	}


	/**
	 * Add a payment to the database
	 *
	 * @access  public
	 * @param   array $payment_data Array All of the payment data, such as amount, date, user ID, etc
	 * @since   1.5
	 * @return  int|false ID of the newly created payment, or false on failure.
	 */
	public function insert( $payment_data = array() ) {

		global $wpdb;

		$defaults = array(
			'subscription'      => '',
			'date'              => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			'amount'            => 0.00,
			'user_id'           => 0,
			'payment_type'      => '',
			'subscription_key'  => '',
			'transaction_id'    => '',
			'status'            => 'complete'
		);

		$args = wp_parse_args( $payment_data, $defaults );

		if( $this->payment_exists( $args['transaction_id'] ) ) {
			return;
		}

		$add = $wpdb->insert( $this->db_name, $args, array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );

		// if insert was succesful, return the payment ID
		if( $add ) {

			$payment_id = $wpdb->insert_id;

			// clear the payment caches
			delete_transient( 'rcp_earnings' );
			delete_transient( 'rcp_payments_count' );

			// Remove trialing status, if it exists
			delete_user_meta( $args['user_id'], 'rcp_is_trialing' );

			do_action( 'rcp_insert_payment', $payment_id, $args, $args['amount'] );

			return $payment_id;

		}

		return false;

	}


	/**
	 * Checks if a payment exists in the DB
	 *
	 * @param   string $transaction_id The transaction ID of the payment record.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  bool
	 */
	public function payment_exists( $transaction_id = '' ) {

		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . $this->db_name . " WHERE `transaction_id`='%s' LIMIT 1;",
				$transaction_id
			)
		);

		return (bool) $found;

	}


	/**
	 * Update a payment in the datbase.
	 *
	 * @param   int   $payment_id   ID of the payment record to update.
	 * @param   array $payment_data Array of all payment data to update.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  int|false The number of rows updated, or false on error.
	 */
	public function update( $payment_id = 0, $payment_data = array() ) {

		global $wpdb;
		do_action( 'rcp_update_payment', $payment_id, $payment_data );
		return $wpdb->update( $this->db_name, $payment_data, array( 'id' => $payment_id ) );
	}


	/**
	 * Delete a payment from the datbase.
	 *
	 * @param   int $payment_id ID of the payment to delete.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  void
	*/
	public function delete( $payment_id = 0 ) {
		global $wpdb;
		do_action( 'rcp_delete_payment', $payment_id );
		$remove = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db_name} WHERE `id` = '%d';", absint( $payment_id ) ) );

	}


	/**
	 * Retrieve a specific payment
	 *
	 * @param   int $payment_id ID of the payment to retrieve.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  object
	 */
	public function get_payment( $payment_id = 0 ) {

		global $wpdb;

		$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->db_name} WHERE id = %d", absint( $payment_id ) ) );

		if( empty( $payment->status ) ) {
			$payment->status = 'complete';
		}

		return $payment;

	}


	/**
	 * Retrieve a specific payment by a field
	 *
	 * @param   string $field Name of the field to check against.
	 * @param   mixed  $value Value of the field.
	 *
	 * @access  public
	 * @since   1.8.2
	 * @return  object
	 */
	public function get_payment_by( $field = 'id', $value = '' ) {

		global $wpdb;

		$payment = $wpdb->get_row( "SELECT * FROM {$this->db_name} WHERE {$field} = {$value}" );

		if( empty( $payment->status ) ) {
			$payment->status = 'complete';
		}

		return $payment;

	}


	/**
	 * Retrieve payments from the database
	 *
	 * @param   array $args Query arguments to override the defaults.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  array Array of objects.
	 */
	public function get_payments( $args = array() ) {

		global $wpdb;

		$defaults = array(
			'number'       => 20,
			'offset'       => 0,
			'subscription' => 0,
			'user_id'      => 0,
			'date'         => array(),
			'fields'       => false,
			'status'       => '',
			's'            => '',
			'order'        => 'DESC',
			'orderby'      => 'id'
		);

		$args  = wp_parse_args( $args, $defaults );

		$where = '';

		// payments for a specific subscription level
		if( ! empty( $args['subscription'] ) ) {
			$where .= "WHERE `subscription`= '{$args['subscription']}' ";
		}

		// payments for specific users
		if( ! empty( $args['user_id'] ) ) {

			if( is_array( $args['user_id'] ) )
				$user_ids = implode( ',', $args['user_id'] );
			else
				$user_ids = intval( $args['user_id'] );

			if( ! empty( $args['subscription'] ) ) {
				$where .= "AND `user_id` IN( {$user_ids} ) ";
			} else {
				$where .= "WHERE `user_id` IN( {$user_ids} ) ";
			}

		}

		// payments for specific statuses
		if( ! empty( $args['status'] ) ) {

			if( is_array( $args['status'] ) )
				$statuss = implode( ',', $args['status'] );
			else
				$statuss = intval( $args['status'] );

			if( ! empty( $args['subscription'] ) || ! empty( $args['user_id'] ) ) {
				$where .= "AND `status` IN( {$statuss} ) ";
			} else {
				$where .= "WHERE `status` IN( {$statuss} ) ";
			}

		}

		// Setup the date query
		if( ! empty( $args['date'] ) && is_array( $args['date'] ) ) {

			$day   = ! empty( $args['date']['day'] )   ? absint( $args['date']['day'] )   : null;
			$month = ! empty( $args['date']['month'] ) ? absint( $args['date']['month'] ) : null;
			$year  = ! empty( $args['date']['year'] )  ? absint( $args['date']['year'] )  : null;
			$date_where = '';

			$date_where .= ! is_null( $year )  ? $year . " = YEAR ( date ) " : '';

			if( ! is_null( $month ) ) {
				$date_where = $month  . " = MONTH ( date ) AND " . $date_where;
			}

			if( ! is_null( $day ) ) {
				$date_where = $day . " = DAY ( date ) AND " . $date_where;
			}

			if( ! empty( $args['user_id'] ) || ! empty( $args['subscription'] ) ) {
				$where .= "AND (" . $date_where . ")";
			} else {
				$where .= "WHERE ( " . $date_where . " ) ";
			}
		}

		// Fields to return
		if( $args['fields'] ) {
			$fields = $args['fields'];
		} else {
			$fields = '*';
		}

		if( ! empty( $args['s'] ) ) {

			if( empty( $where ) )
				$where = "WHERE ";
			else
				$where = " AND ";

			// Search by email
			if( is_email( $args['s'] ) ) {

				$user = get_user_by( 'email', $args['s'] );

				$where .= "`user_id`=$user->ID ";

			} else {

				$levels_db = new RCP_Levels;

				// Search by subscription key
				if( strlen( $args['s'] ) == 32 ) {

					$where .= "`subscription_key`= '{$args['s']}' ";

				} elseif( $levels_db->get_level_by( 'name', $args['s'] ) ) {

					// Matching subscription level found so search for payments with this level
					$where .= "`subscription`= '{$args['s']}' ";
				} else {
					$where .= "`transaction_id`='{$args['s']}' ";
				}
			}

		}

		if ( 'DESC' === strtoupper( $args['order'] ) ) {
			$order = 'DESC';
		} else {
			$order = 'ASC';
		}

		$columns = array(
			'id',
			'user_id',
			'subscription',
			'subscription_key',
			'transaction_id',
			'status',
			'date'
		);

		$orderby = array_key_exists( $args['orderby'], $columns ) ? $args['orderby'] : 'id';

		$payments = $wpdb->get_results( $wpdb->prepare( "SELECT {$fields} FROM " . $this->db_name . " {$where}ORDER BY {$orderby} {$order} LIMIT %d,%d;", absint( $args['offset'] ),  $args['number'] ) );

		return $payments;

	}


	/**
	 * Count the total number of payments in the database
	 *
	 * @param   array $args Query arguments to override the defaults.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  int
	 */
	public function count( $args = array() ) {

		global $wpdb;

		$defaults = array(
			'user_id' => 0,
			'status'  => ''
		);

		$args  = wp_parse_args( $args, $defaults );

		$where = '';

		if( ! empty( $args['user_id'] ) ) {

			if( is_array( $args['user_id'] ) )
				$user_ids = implode( ',', $args['user_id'] );
			else
				$user_ids = intval( $args['user_id'] );

			$where .= " WHERE `user_id` IN( {$user_ids} ) ";

		}

		if( ! empty( $args['status'] ) ) {

			if( is_array( $args['status'] ) ) {
				$statuss = implode( ',', $args['status'] );
			} else {
				$statuss = intval( $args['status'] );
			}

			if( ! empty( $args['user_id'] ) ) {
				$where .= " AND `status` IN( {$statuss} ) ";
			} else {
				$where .= " WHERE `status` IN( {$statuss} ) ";
			}

		}

		$key   = md5( 'rcp_payments_' . serialize( $args ) );
		$count = get_transient( $key );

		if( $count === false ) {
			$count = $wpdb->get_var( "SELECT COUNT(ID) FROM " . $this->db_name . "{$where};" );
			set_transient( $key, $count, 10800 );
		}

		return $count;

	}


	/**
	 * Calculate the total earnings of all payments in the database
	 *
	 * @param   array $args Query arguments to override the defaults.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  float
	 */
	public function get_earnings( $args = array() ) {

		global $wpdb;

		$defaults = array(
			'earnings'     => 1, // Just for the cache key
			'subscription' => 0,
			'user_id'      => 0,
			'date'         => array()
		);

		$args = wp_parse_args( $args, $defaults );

		$cache_args = $args;
		$cache_args['date'] = implode( ',', $args['date'] );
		$cache_key = md5( implode( ',', $cache_args ) );

		$where = '';

		// payments for a specific subscription level
		if( ! empty( $args['subscription'] ) ) {
			$where .= "WHERE `subscription`= '{$args['subscription']}' ";
		}

		// payments for specific users
		if( ! empty( $args['user_id'] ) ) {

			if( is_array( $args['user_id'] ) )
				$user_ids = implode( ',', $args['user_id'] );
			else
				$user_ids = intval( $args['user_id'] );

			if( ! empty( $args['subscription'] ) ) {
				$where .= "`user_id` IN( {$user_ids} ) ";
			} else {
				$where .= "WHERE `user_id` IN( {$user_ids} ) ";
			}

		}

		// Setup the date query
		if( ! empty( $args['date'] ) && is_array( $args['date'] ) ) {

			$day   = ! empty( $args['date']['day'] )   ? absint( $args['date']['day'] )   : null;
			$month = ! empty( $args['date']['month'] ) ? absint( $args['date']['month'] ) : null;
			$year  = ! empty( $args['date']['year'] )  ? absint( $args['date']['year'] )  : null;
			$date_where = '';

			$date_where .= ! is_null( $year )  ? $year . " = YEAR ( date ) " : '';

			if( ! is_null( $month ) ) {
				$date_where = $month  . " = MONTH ( date ) AND " . $date_where;
			}

			if( ! is_null( $day ) ) {
				$date_where = $day . " = DAY ( date ) AND " . $date_where;
			}

			if( ! empty( $args['user_id'] ) || ! empty( $args['subscription'] ) ) {
				$where .= "AND (" . $date_where . ") ";
			} else {
				$where .= "WHERE ( " . $date_where . " ) ";
			}
		}

		// Exclude refunded payments
		if( false !== strpos( $where, 'WHERE' ) ) {

			$where .= "AND ( `status` = 'complete' OR `status` IS NULL )";

		} else {

			$where .= "WHERE ( `status` = 'complete' OR `status` IS NULL )";

		}

		$earnings = get_transient( $cache_key );

		if( $earnings === false ) {
			$earnings = $wpdb->get_var( "SELECT SUM(amount) FROM " . $this->db_name . " {$where};" );
			set_transient( $cache_key, $earnings, 3600 );
		}

		return round( $earnings, 2 );

	}


	/**
	 * Calculate the total refunds of all payments in the database
	 *
	 * @param   array $args Query arguments to override the defaults.
	 *
	 * @access  public
	 * @since   2.5
	 * @return  float
	 */
	public function get_refunds( $args = array() ) {

		global $wpdb;

		$defaults = array(
			'refunds'      => 2, // Just for the cache key
			'subscription' => 0,
			'user_id'      => 0,
			'date'         => array()
		);

		$args = wp_parse_args( $args, $defaults );

		$cache_args = $args;
		$cache_args['date'] = implode( ',', $args['date'] );
		$cache_key = md5( implode( ',', $cache_args ) );

		$where = '';

		// refunds for a specific subscription level
		if( ! empty( $args['subscription'] ) ) {
			$where .= "WHERE `subscription`= '{$args['subscription']}' ";
		}

		// refunds for specific users
		if( ! empty( $args['user_id'] ) ) {

			if( is_array( $args['user_id'] ) )
				$user_ids = implode( ',', $args['user_id'] );
			else
				$user_ids = intval( $args['user_id'] );

			if( ! empty( $args['subscription'] ) ) {
				$where .= "`user_id` IN( {$user_ids} ) ";
			} else {
				$where .= "WHERE `user_id` IN( {$user_ids} ) ";
			}

		}

		// Setup the date query
		if( ! empty( $args['date'] ) && is_array( $args['date'] ) ) {

			$day   = ! empty( $args['date']['day'] )   ? absint( $args['date']['day'] )   : null;
			$month = ! empty( $args['date']['month'] ) ? absint( $args['date']['month'] ) : null;
			$year  = ! empty( $args['date']['year'] )  ? absint( $args['date']['year'] )  : null;
			$date_where = '';

			$date_where .= ! is_null( $year )  ? $year . " = YEAR ( date ) " : '';

			if( ! is_null( $month ) ) {
				$date_where = $month  . " = MONTH ( date ) AND " . $date_where;
			}

			if( ! is_null( $day ) ) {
				$date_where = $day . " = DAY ( date ) AND " . $date_where;
			}

			if( ! empty( $args['user_id'] ) || ! empty( $args['subscription'] ) ) {
				$where .= "AND (" . $date_where . ") ";
			} else {
				$where .= "WHERE ( " . $date_where . " ) ";
			}
		}

		// Exclude refunded payments
		if( false !== strpos( $where, 'WHERE' ) ) {

			$where .= "AND ( `status` = 'refunded' )";

		} else {

			$where .= "WHERE ( `status` = 'refunded' )";

		}

		$refunds = get_transient( $cache_key );

		if( $refunds === false ) {
			$refunds = $wpdb->get_var( "SELECT SUM(amount) FROM " . $this->db_name . " {$where};" );
			set_transient( $cache_key, $refunds, 3600 );
		}

		return round( $refunds, 2 );

	}


	/**
	 * Retrieves the last payment made by a user
	 *
	 * @param   int $user_id ID of the user to check.
	 *
	 * @access  public
	 * @since   1.5
	 * @return  int|float|false Amount of last payment or false if none is found.
	*/
	public function last_payment_of_user( $user_id = 0 ) {
		global $wpdb;
		$query = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . $this->db_name . " WHERE `user_id`='%d' ORDER BY id DESC LIMIT 1;", $user_id ) );
		if( $query )
			return $query[0]->amount;
		return false;
	}

	/**
	 * Retrieve payment meta field for a payment.
	 *
	 * @param   int    $payment_id    Payment ID.
	 * @param   string $meta_key      The meta key to retrieve.
	 * @param   bool   $single        Whether to return a single value.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  mixed                 Will be an array if $single is false. Will be value of meta data field if $single is true.
	 */
	public function get_meta( $payment_id = 0, $meta_key = '', $single = false ) {
		return get_metadata( 'payment', $payment_id, $meta_key, $single );
	}

	/**
	 * Add meta data field to a payment.
	 *
	 * @param   int    $payment_id    Payment ID.
	 * @param   string $meta_key      Metadata name.
	 * @param   mixed  $meta_value    Metadata value.
	 * @param   bool   $unique        Optional, default is false. Whether the same key should not be added.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  bool                  False for failure. True for success.
	 */
	public function add_meta( $payment_id = 0, $meta_key = '', $meta_value, $unique = false ) {
		return add_metadata( 'payment', $payment_id, $meta_key, $meta_value, $unique );
	}

	/**
	 * Update payment meta field based on Payment ID.
	 *
	 * Use the $prev_value parameter to differentiate between meta fields with the
	 * same key and Payment ID.
	 *
	 * If the meta field for the payment does not exist, it will be added.
	 *
	 * @param   int    $payment_id    Payment ID.
	 * @param   string $meta_key      Metadata key.
	 * @param   mixed  $meta_value    Metadata value.
	 * @param   mixed  $prev_value    Optional. Previous value to check before removing.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  bool                  False on failure, true if success.
	 */
	public function update_meta( $payment_id = 0, $meta_key = '', $meta_value, $prev_value = '' ) {
		return update_metadata( 'payment', $payment_id, $meta_key, $meta_value, $prev_value );
	}

	/**
	 * Remove metadata matching criteria from a payment.
	 *
	 * You can match based on the key, or key and value. Removing based on key and
	 * value, will keep from removing duplicate metadata with the same key. It also
	 * allows removing all metadata matching key, if needed.
	 *
	 * @param   int    $payment_id    Payment ID.
	 * @param   string $meta_key      Metadata name.
	 * @param   mixed  $meta_value    Optional. Metadata value.
	 *
	 * @access  public
	 * @since   2.6
	 * @return  bool                  False for failure. True for success.
	 */
	public function delete_meta( $payment_id = 0, $meta_key = '', $meta_value = '' ) {
		return delete_metadata( 'payment', $payment_id, $meta_key, $meta_value );
	}

}
