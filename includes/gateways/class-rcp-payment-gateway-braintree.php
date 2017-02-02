<?php
/**
 * Braintree Payment Gateway Class
 *
 * @package Restrict Content Pro
 * @since 2.7
 */

class RCP_Payment_Gateway_Braintree extends RCP_Payment_Gateway {

	protected $merchantId;
	protected $publicKey;
	protected $privateKey;
	protected $encryptionKey;
	protected $environment;


// @todo only load if PHP 5.4+

	public function init() {

		if ( version_compare( PHP_VERSION, '5.4.0', '<' ) ) {
			return;
		}

		global $rcp_options;

		$this->supports[] = 'one-time';
		$this->supports[] = 'recurring';
		$this->supports[] = 'fees';
		$this->supports[] = 'trial';
		$this->supports[] = 'gateway-submits-form';

		if ( $this->test_mode ) {
			$this->merchantId    = ! empty( $rcp_options['braintree_sandbox_merchantId'] ) ? sanitize_text_field( $rcp_options['braintree_sandbox_merchantId'] ) : '';
			$this->publicKey     = ! empty( $rcp_options['braintree_sandbox_publicKey'] ) ? sanitize_text_field( $rcp_options['braintree_sandbox_publicKey'] ) : '';
			$this->privateKey    = ! empty( $rcp_options['braintree_sandbox_privateKey'] ) ? sanitize_text_field( $rcp_options['braintree_sandbox_privateKey'] ) : '';
			$this->encryptionKey = ! empty( $rcp_options['braintree_sandbox_encryptionKey'] ) ? sanitize_text_field( $rcp_options['braintree_sandbox_encryptionKey'] ) : '';
			$this->environment   = 'sandbox';
		} else {
			$this->merchantId    = ! empty( $rcp_options['braintree_live_merchantId'] ) ? sanitize_text_field( $rcp_options['braintree_live_merchantId'] ) : '';
			$this->publicKey     = ! empty( $rcp_options['braintree_live_publicKey'] ) ? sanitize_text_field( $rcp_options['braintree_live_publicKey'] ) : '';
			$this->privateKey    = ! empty( $rcp_options['braintree_live_privateKey'] ) ? sanitize_text_field( $rcp_options['braintree_live_privateKey'] ) : '';
			$this->encryptionKey = ! empty( $rcp_options['braintree_live_encryptionKey'] ) ? sanitize_text_field( $rcp_options['braintree_live_encryptionKey'] ) : '';
			$this->environment   = 'production';
		}

		require_once RCP_PLUGIN_DIR . 'includes/libraries/braintree/lib/Braintree.php';

		Braintree_Configuration::environment( $this->environment );
		Braintree_Configuration::merchantId( $this->merchantId );
		Braintree_Configuration::publicKey( $this->publicKey );
		Braintree_Configuration::privateKey( $this->privateKey );

	}

	public function validate_fields() {
		// if ( empty( $_POST['payment_method_nonce'] ) ) {
		// 	rcp_errors()->add( 'braintree_payment_method_nonce_failed', __( 'Payment error.', 'rcp' ), 'register' );
		// }
	}

	public function process_signup() {

		if ( empty( $_POST['payment_method_nonce'] ) ) {
			wp_die(
				__( 'Missing Braintree payment nonce. Please try again. Contact support if the issue persists.', 'rcp' ),
				__( 'Error', 'rcp' ),
				array( 'response' => 400 )
			);
		}

		$paid   = false;
		$member = new RCP_Member( $this->user_id );

		/**
		 * Set up the transaction arguments.
		 */
		$txn_args = array(
			'paymentMethodNonce' => $_POST['payment_method_nonce']
		);

		if ( $this->is_trial() ) {

			// Braintree only supports 'day' and 'month' units
			$duration      = $this->subscription_data['trial_duration'];
			$duration_unit = $this->subscription_data['trial_duration_unit'];

			if ( 'year' === $duration_unit ) {
				$duration = '12';
				$duration_unit = 'month';
			}

			$txn_args['trialPeriod'] = true;
			$txn_args['trialDuration'] = $duration;
			$txn_args['trialDurationUnit'] = $duration_unit;
		}

		/**
		 * Set up the customer object.
		 *
		 * Get the customer record from Braintree if it already exists,
		 * otherwise create a new customer record.
		 */
		// $customer = false;
		try {
			$customer = Braintree_Customer::find( $this->subscription_data['user_id'] );

		} catch ( Exception $e ) {
			// Create the customer since it doesn't exist
			try {
				$result = Braintree_Customer::create(
					array(
						'id'                 => $this->subscription_data['user_id'],
						'firstName'          => ! empty( $this->subscription_data['post_data']['rcp_user_first'] ) ? sanitize_text_field( $this->subscription_data['post_data']['rcp_user_first'] ) : '',
						'lastName'           => ! empty( $this->subscription_data['post_data']['rcp_user_last'] ) ? sanitize_text_field( $this->subscription_data['post_data']['rcp_user_last'] ) : '',
						'email'              => $this->subscription_data['user_email'],
						'paymentMethodNonce' => $_POST['payment_method_nonce'],
						'riskData'           => array(
							'customerBrowser' => $_SERVER['HTTP_USER_AGENT'],
							'customerIp'      => rcp_get_ip()
						)
					)
				);

				if ( $result->success ) {
					$customer = $result->customer;
				}

			} catch ( Exception $e ) {
				// Customer lookup/creation failed
				$this->handle_processing_error( $e );
			}
		}

		/**
		 * Set up the subscription values and create the subscription.
		 */
		if ( $this->auto_renew ) {
//@todo cancel existing sub
			$txn_args['planId']             = $this->subscription_data['subscription_id'];
			$txn_args['paymentMethodToken'] = $customer->paymentMethods[0]->token;

			try {
				$result = Braintree_Subscription::create( $txn_args );
				if ( $result->success ) {
					$paid = true;
				}

			} catch ( Exception $e ) {

				$this->handle_processing_error( $e );
			}

		/**
		 * Set up the one-time payment values and create the payment.
		 */
		} else {

			$txn_args['amount'] = $this->amount;
			$txn_args['options']['submitForSettlement'] = true;

			try {
				$result = Braintree_Transaction::sale( $txn_args );
				if ( $result->success ) {
					$paid = true;
				}

			} catch ( Exception $e ) {

				$this->handle_processing_error( $e );
			}

		}

		if ( empty( $result ) || empty( $result->success ) ) {
			wp_die( sprintf( __( 'An error occurred. Please contact the site administrator: %s', 'rcp' ), get_bloginfo( 'admin_email' ) ) );
		}

		/**
		 * Record the payment and adjust the member properties.
		 */
		if ( $paid && ! $this->auto_renew ) {
			// Log the one-time payment
			$payment_data = array(
				'subscription'     => $this->subscription_data['subscription_name'],
				'date'             => date( 'Y-m-d g:i:s', time() ),
				'amount'           => $result->transaction->amount,
				'user_id'          => $this->subscription_data['user_id'],
				'payment_type'     => __( 'Braintree Credit Card One Time', 'rcp' ),
				'subscription_key' => $this->subscription_data['key'],
				'transaction_id'   => $result->transaction->id
			);
			$rcp_payments = new RCP_Payments;
			$rcp_payments->insert( $payment_data );

			// Update the user account
			rcp_set_status( $this->subscription_data['user_id'], 'active' );

		}

		// Update the user account
		if ( $paid && $this->auto_renew ) {
			$member->set_merchant_subscription_id( $result->subscription->id );
			update_user_meta( $this->subscription_data['user_id'], 'rcp_recurring_payment_id', $result->subscription->id );
			$member->renew( true, 'active', $result->subscription->billingPeriodEndDate->format( 'Y-m-d 23:59:59' ) );
		}

		wp_redirect( $this->return_url ); exit;

	}

	public function process_webhooks() {

		if ( isset( $_GET['bt_challenge'] ) ) {
			$verify = Braintree_WebhookNotification::verify( $_GET['bt_challenge'] );
			die( $verify );
		}

		if ( isset( $_POST['bt_signature'] ) && isset( $_POST['bt_payload'] ) ) {
			$webhook = Braintree_WebhookNotification::parse( $_POST['bt_signature'], $_POST['bt_payload'] );
echo '<pre>'; print_r($webhook); echo '</pre>';
			if ( empty( $webhook->kind ) ) {
				die( 'Invalid webhook' );
			}

			if ( 'check' === $webhook->kind ) {
				die(200);
			}


		}
	}

	protected function handle_processing_error( $e ) {
		// @todo
		echo '<pre>$e '; print_r($e); echo '</pre>';wp_die();
	}

	public function fields() {
		ob_start();
		?>
		<script type="text/javascript">

			jQuery('#rcp_registration_form #rcp_submit').on('click', function(event) {
				event.preventDefault();

				var token = document.getElementById('rcp-braintree-client-token');

				braintree.setup(token.value, 'custom', {
					id: 'rcp_registration_form',
					onReady: function (response) {
						var client = new braintree.api.Client({clientToken: token.value});
						client.tokenizeCard({
							number: jQuery('#rcp_card_number_wrap input').val(),
							expirationDate: jQuery('.rcp_card_exp_month').val() + '/' + jQuery('.rcp_card_exp_year').val()
						}, function (err, nonce) {
							jQuery("input[name='payment_method_nonce']").val(nonce);
							jQuery('#rcp_registration_form').submit();
						});
					},
					onError: function (response) {
						//@todo
						console.log('onError');
						console.log(response);
					}
				});

			});
		</script>

		<fieldset class="rcp_card_fieldset">
			<p id="rcp_card_number_wrap">
				<label><?php _e( 'Card Number', 'rcp' ); ?></label>
				<input data-braintree-name="number" value="">
			</p>

			<p id="rcp_card_cvc_wrap">
				<label><?php _e( 'Card CVC', 'rcp' ); ?></label>
				<input data-braintree-name="cvv" value="">
			</p>

			<p id="rcp_card_zip_wrap">
				<label><?php _e( 'Card ZIP or Postal Code', 'rcp' ); ?></label>
				<input data-braintree-name="postal_code" value="">
			</p>

			<p id="rcp_card_name_wrap">
				<label><?php _e( 'Name on Card', 'rcp' ); ?></label>
				<input data-braintree-name="cardholder_name" value="">
			</p>

			<p id="rcp_card_exp_wrap">
				<label><?php _e( 'Expiration (MM/YYYY)', 'rcp' ); ?></label>
				<select data-braintree-name="expiration_month" class="rcp_card_exp_month card-expiry-month">
					<?php for( $i = 1; $i <= 12; $i++ ) : ?>
						<option value="<?php echo $i; ?>"><?php echo $i . ' - ' . rcp_get_month_name( $i ); ?></option>
					<?php endfor; ?>
				</select>

				<span class="rcp_expiry_separator"> / </span>

				<select data-braintree-name="expiration_year" class="rcp_card_exp_year card-expiry-year">
					<?php
					$year = date( 'Y' );
					for( $i = $year; $i <= $year + 10; $i++ ) : ?>
						<option value="<?php echo $i; ?>"><?php echo $i; ?></option>
					<?php endfor; ?>
				</select>
			</p>
			<input type="hidden" id="rcp-braintree-client-token" name="rcp-braintree-client-token" value="<?php echo Braintree_ClientToken::generate(); ?>" />
		</fieldset>
		<?php
		return ob_get_clean();
	}

	public function scripts() {
		wp_enqueue_script( 'braintree', 'https://js.braintreegateway.com/js/braintree-2.30.0.min.js' );
	}

}