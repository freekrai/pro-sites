<?php
/**
 * Paypal API function
 */
if ( ! class_exists( 'PaypalApiHelper' ) ) {
	class PaypalApiHelper {

		public static function SetExpressCheckout( $paymentAmount, $desc, $blog_id = '', $domain = '', $force_recurring = false ) {
			global $psts;

			$recurring = $psts->get_setting( 'recurring_subscriptions' );
			$nvpstr    = '';

			//Force recurring is used for Non recurring subscriptions with trial
			$recurring = $recurring ? $recurring : $force_recurring;

			if ( $recurring ) {
				$nvpstr .= "&L_BILLINGAGREEMENTDESCRIPTION0=" . urlencode( html_entity_decode( $desc, ENT_COMPAT, "UTF-8" ) );
				$nvpstr .= "&L_BILLINGTYPE0=RecurringPayments";
			} else {
				$nvpstr .= "&PAYMENTREQUEST_0_DESC=" . urlencode( html_entity_decode( $desc, ENT_COMPAT, "UTF-8" ) );
			}

			$checkout_url = $psts->checkout_url( $blog_id, $domain );
			$checkout_url = add_query_arg(
				array(
					'action' => 'complete'
				),
				$checkout_url
			);
			$cancel_url   = add_query_arg(
				array(
					'action' => 'canceled'
				),
				$checkout_url
			);
			$nvpstr .= "&CURRENCYCODE=" . $psts->get_setting( 'pypl_currency' );
			$nvpstr .= "&PAYMENTREQUEST_0_AMT=" . $paymentAmount;
			$nvpstr .= "&PAYMENTREQUEST_0_PAYMENTACTION=Sale";
			$nvpstr .= "&LOCALECODE=" . $psts->get_setting( 'pypl_site' );
			$nvpstr .= "&NOSHIPPING=1";
			$nvpstr .= "&ALLOWNOTE=0";
			$nvpstr .= "&RETURNURL=" . urlencode( $checkout_url );
			$nvpstr .= "&CANCELURL=" . urlencode( $cancel_url );

			//formatting
			$nvpstr .= "&HDRIMG=" . urlencode( $psts->get_setting( 'pypl_header_img' ) );
			$nvpstr .= "&HDRBORDERCOLOR=" . urlencode( $psts->get_setting( 'pypl_header_border' ) );
			$nvpstr .= "&HDRBACKCOLOR=" . urlencode( $psts->get_setting( 'pypl_header_back' ) );
			$nvpstr .= "&PAYFLOWCOLOR=" . urlencode( $psts->get_setting( 'pypl_page_back' ) );

			$resArray = self::api_call( "SetExpressCheckout", $nvpstr );

			return $resArray;
		}

		public static function DoExpressCheckoutPayment( $token, $payer_id, $paymentAmount, $frequency, $desc, $blog_id, $level, $activation_key = '', $tax = '' ) {
			global $psts;
			$item_amt = $paymentAmount - $tax;

			$nvpstr = "&TOKEN=" . urlencode( $token );
			$nvpstr .= "&PAYERID=" . urlencode( $payer_id );
			if ( ! defined( 'PSTS_NO_BN' ) ) {
				$nvpstr .= "&BUTTONSOURCE=incsub_SP";
			}
			$nvpstr .= "&PAYMENTREQUEST_0_AMT=$paymentAmount";
			$nvpstr .= "&PAYMENTREQUEST_0_ITEMAMT=$item_amt";
			$nvpstr .= "&L_BILLINGTYPE0=RecurringPayments";
			$nvpstr .= "&PAYMENTACTION=Sale";
			$nvpstr .= "&CURRENCYCODE=" . $psts->get_setting( 'pypl_currency' );
			$nvpstr .= "&DESC=" . urlencode( html_entity_decode( $desc, ENT_COMPAT, "UTF-8" ) );

			$nvpstr .= "&PAYMENTREQUEST_0_CUSTOM=" . PSTS_PYPL_PREFIX . '_' . $blog_id . '_' . $level . '_' . $frequency . '_' . $paymentAmount . '_' . $psts->get_setting( 'pypl_currency' ) . '_' . time() . '_' . $activation_key;

			$nvpstr .= "&PAYMENTREQUEST_0_NOTIFYURL=" . urlencode( network_site_url( 'wp-admin/admin-ajax.php?action=psts_pypl_ipn', 'admin' ) );

			if ( ! empty( $tax ) ) {
				$nvpstr .= "&PAYMENTREQUEST_0_TAXAMT=" . $tax;
			}
			$resArray = self::api_call( "DoExpressCheckoutPayment", $nvpstr );

			return $resArray;
		}

		public static function CreateRecurringPaymentsProfileExpress( $token, $paymentAmount, $initAmount, $frequency, $desc, $blog_id, $level, $modify = false, $activation_key = '', $total_billing_cycle = '', $tax = false ) {
			global $psts;

			$trial_days = $psts->get_setting( 'trial_days', 0 );
			$has_trial  = $psts->is_trial_allowed( $blog_id );

			$nvpstr = "&TOKEN=" . $token;
			$nvpstr .= "&AMT=$paymentAmount";

			if ( ! empty( $initAmount ) ) {
				$nvpstr .= "&INITAMT=" . $initAmount;
			} else {
				//apply setup fee (if applicable)
				$setup_fee = $psts->get_setting( 'setup_fee', 0 );

				if ( empty( $blog_id ) ) {
					if ( $level != 0 ) {
						$has_setup_fee = false;
					} else {
						$has_setup_fee = true;
					}
				} else {
					$has_setup_fee = $psts->has_setup_fee( $blog_id, $level );
				}

				if ( $has_setup_fee && ! empty ( $setup_fee ) ) {
					$nvpstr .= "&INITAMT=" . round( $setup_fee, 2 );
				}
			}

			//handle free trials
			if ( $has_trial ) {
				$nvpstr .= "&TRIALBILLINGPERIOD=Day";
				$nvpstr .= "&TRIALBILLINGFREQUENCY=" . $trial_days;
				$nvpstr .= "&TRIALTOTALBILLINGCYCLES=1";
				$nvpstr .= "&TRIALAMT=0.00";
			} //handle modification
			elseif ( $modify ) { // expiration is in the future\
				$nvpstr .= "&TRIALBILLINGPERIOD=Month";
				$nvpstr .= "&TRIALBILLINGFREQUENCY=$frequency";
				$nvpstr .= "&TRIALTOTALBILLINGCYCLES=1";
				$nvpstr .= "&TRIALAMT=" . round( $initAmount, 2 );
			}
			if ( $has_trial ) {
				$nvpstr .= "&PROFILESTARTDATE=" . ( is_pro_trial( $blog_id ) ? urlencode( gmdate( 'Y-m-d\TH:i:s.00\Z', $psts->get_expire( $blog_id ) ) ) : self::startDate( $trial_days, 'days' ) );
			} else {
				$nvpstr .= "&PROFILESTARTDATE=" . ( ( $modify ) ? self::modStartDate( $modify ) : self::startDate( $frequency ) );
			}

			$nvpstr .= "&CURRENCYCODE=" . $psts->get_setting( 'pypl_currency' );
			$nvpstr .= "&BILLINGPERIOD=Month";
			$nvpstr .= "&BILLINGFREQUENCY=$frequency";

			//Non recurring subscription with trial
			if ( ! empty( $total_billing_cycle ) && $total_billing_cycle == 1 ) {
				$nvpstr .= "&TOTALBILLINGCYCLES=$total_billing_cycle";
			}
			$nvpstr .= "&DESC=" . urlencode( html_entity_decode( $desc, ENT_COMPAT, "UTF-8" ) );
			$nvpstr .= "&MAXFAILEDPAYMENTS=1";
			$nvpstr .= "&PROFILEREFERENCE=" . PSTS_PYPL_PREFIX . '_' . $blog_id . '_' . $level . '_' . $frequency . '_' . $paymentAmount . '_' . $psts->get_setting( 'pypl_currency' ) . '_' . time() . '_' . $activation_key;

			//Tax Calculated for each payment
			if ( $tax ) {
				$nvpstr .= "&TAXAMT=" . $tax;
			}

			$resArray = self::api_call( "CreateRecurringPaymentsProfile", $nvpstr );

			return $resArray;
		}

		public static function CreateRecurringPaymentsProfileDirect( $paymentAmount, $initAmount, $frequency, $desc, $blog_id, $level, $cctype, $acct, $expdate, $cvv2, $firstname, $lastname, $street, $street2, $city, $state, $zip, $countrycode, $email, $modify = false, $activation_key = '', $total_billing_cycle = '', $tax = false ) {
			global $psts;

			$trial_days = $psts->get_setting( 'trial_days', 0 );
			$has_trial  = $psts->is_trial_allowed( $blog_id );

			$nvpstr = "&AMT=$paymentAmount";

			if ( ! empty( $initAmount ) ) {
				$nvpstr .= "&INITAMT=" . $initAmount;
			} else {
				//apply setup fee (if applicable)
				$setup_fee = $psts->get_setting( 'setup_fee', 0 );

				if ( empty( $blog_id ) ) {
					if ( $level != 0 ) {
						$has_setup_fee = false;
					} else {
						$has_setup_fee = true;
					}
				} else {
					$has_setup_fee = $psts->has_setup_fee( $blog_id, $level );
				}

				if ( $has_setup_fee && ! empty ( $setup_fee ) ) {
					$nvpstr .= "&INITAMT=" . round( $setup_fee, 2 );
				}

			}
			//handle free trials
			if ( $has_trial ) {

				$nvpstr .= "&TRIALBILLINGPERIOD=Day";
				$nvpstr .= "&TRIALBILLINGFREQUENCY=" . $trial_days;
				$nvpstr .= "&TRIALTOTALBILLINGCYCLES=1";
				$nvpstr .= "&TRIALAMT=0.00";
				//handle modifications
			} elseif ( $modify ) { // expiration is in the future
				$nvpstr .= "&TRIALBILLINGPERIOD=Month";
				$nvpstr .= "&TRIALBILLINGFREQUENCY=$frequency";
				$nvpstr .= "&TRIALTOTALBILLINGCYCLES=1";
				$nvpstr .= "&TRIALAMT=" . round( $initAmount, 2 );
			}
			if ( $has_trial ) {
				$nvpstr .= "&PROFILESTARTDATE=" . ( is_pro_trial( $blog_id ) ? urlencode( gmdate( 'Y-m-d\TH:i:s.00\Z', $psts->get_expire( $blog_id ) ) ) : self::startDate( $trial_days, 'days' ) );
			} else {
				$nvpstr .= "&PROFILESTARTDATE=" . ( ( $modify ) ? self::modStartDate( $modify ) : self::startDate( $frequency ) );
			}

			$nvpstr .= "&CURRENCYCODE=" . $psts->get_setting( 'pypl_currency' );
			$nvpstr .= "&BILLINGPERIOD=Month";
			$nvpstr .= "&BILLINGFREQUENCY=$frequency";

			//Non recurring subscription with trial
			if ( ! empty( $total_billing_cycle ) && $total_billing_cycle == 1 ) {
				$nvpstr .= "&TOTALBILLINGCYCLES=$total_billing_cycle";
			}

			$nvpstr .= "&DESC=" . urlencode( html_entity_decode( $desc, ENT_COMPAT, "UTF-8" ) );
			$nvpstr .= "&MAXFAILEDPAYMENTS=1";
			$nvpstr .= "&PROFILEREFERENCE=" . PSTS_PYPL_PREFIX . '_' . $blog_id . '_' . $level . '_' . $frequency . '_' . $paymentAmount . '_' . $psts->get_setting( 'pypl_currency' ) . '_' . time() . '_' . $activation_key;
			$nvpstr .= "&CREDITCARDTYPE=$cctype";
			$nvpstr .= "&ACCT=$acct";
			$nvpstr .= "&EXPDATE=$expdate";
			$nvpstr .= "&CVV2=$cvv2";
			$nvpstr .= "&FIRSTNAME=$firstname";
			$nvpstr .= "&LASTNAME=$lastname";
			$nvpstr .= "&STREET=$street";
			$nvpstr .= "&STREET2=$street2";
			$nvpstr .= "&CITY=$city";
			$nvpstr .= "&STATE=$state";
			$nvpstr .= "&ZIP=$zip";
			$nvpstr .= "&COUNTRYCODE=$countrycode";
			$nvpstr .= "&EMAIL=$email";

			//Tax Calculated for payment
			if ( $tax ) {
				$nvpstr .= "&TAXAMT=" . $tax;
			}

			$resArray = self::api_call( "CreateRecurringPaymentsProfile", $nvpstr );

			return $resArray;
		}

		public static function DoDirectPayment( $paymentAmount, $frequency, $desc, $blog_id, $level, $cctype, $acct, $expdate, $cvv2, $firstname, $lastname, $street, $street2, $city, $state, $zip, $countrycode, $email, $activation_key = '', $tax = false ) {
			global $psts;

			$nvpstr = "&AMT=$paymentAmount";
			if ( ! defined( 'PSTS_NO_BN' ) ) {
				$nvpstr .= "&BUTTONSOURCE=incsub_SP";
			}
			$nvpstr .= "&IPADDRESS=" . $_SERVER['REMOTE_ADDR'];
			$nvpstr .= "&PAYMENTACTION=Sale";
			$nvpstr .= "&CURRENCYCODE=" . $psts->get_setting( 'pypl_currency' );
			$nvpstr .= "&DESC=" . urlencode( html_entity_decode( $desc, ENT_COMPAT, "UTF-8" ) );

			$nvpstr .= "&CUSTOM=" . PSTS_PYPL_PREFIX . '_' . $blog_id . '_' . $level . '_' . $frequency . '_' . $paymentAmount . '_' . $psts->get_setting( 'pypl_currency' ) . '_' . time() . '_' . $activation_key;

			$nvpstr .= "&CREDITCARDTYPE=$cctype";
			$nvpstr .= "&ACCT=$acct";
			$nvpstr .= "&EXPDATE=$expdate";
			$nvpstr .= "&CVV2=$cvv2";
			$nvpstr .= "&FIRSTNAME=$firstname";
			$nvpstr .= "&LASTNAME=$lastname";
			$nvpstr .= "&STREET=$street";
			$nvpstr .= "&STREET2=$street2";
			$nvpstr .= "&CITY=$city";
			$nvpstr .= "&STATE=$state";
			$nvpstr .= "&ZIP=$zip";
			$nvpstr .= "&COUNTRYCODE=$countrycode";
			$nvpstr .= "&EMAIL=$email";
			//Tax Calculated for payment
			if ( $tax ) {
				$nvpstr .= "&TAXAMT=" . $tax;
			}

			$resArray = self::api_call( "DoDirectPayment", $nvpstr );

			return $resArray;
		}

		public static function GetExpressCheckoutDetails( $token ) {
			$nvpstr = "&TOKEN=" . $token;

			return self::api_call( 'GetExpressCheckoutDetails', $nvpstr );
		}

		public static function GetTransactionDetails( $transaction_id ) {

			$nvpstr = "&TRANSACTIONID=" . $transaction_id;

			$resArray = self::api_call( "GetTransactionDetails", $nvpstr );

			return $resArray;
		}

		public static function GetRecurringPaymentsProfileDetails( $profile_id ) {

			$nvpstr = "&PROFILEID=" . $profile_id;

			$resArray = self::api_call( "GetRecurringPaymentsProfileDetails", $nvpstr );

			return $resArray;
		}

		public static function ManageRecurringPaymentsProfileStatus( $profile_id, $action, $note ) {

			$nvpstr = "&PROFILEID=" . $profile_id;
			$nvpstr .= "&ACTION=$action"; //Should be Cancel, Suspend, Reactivate
			$nvpstr .= "&NOTE=" . urlencode( html_entity_decode( $note, ENT_COMPAT, "UTF-8" ) );

			$resArray = self::api_call( "ManageRecurringPaymentsProfileStatus", $nvpstr );

			return $resArray;
		}

		/**
		 * Retrieve the details of a previously created recurring payments profile.
		 *
		 * @access    public
		 *
		 * @param    array    call config data
		 *
		 * @return    array
		 */


		public static function GetRecurringPaymentsProfileStatus( $profile_id ) {

			$PayPalResult  = self::GetRecurringPaymentsProfileDetails( $profile_id );
			$PayPalErrors  = $PayPalResult['ERRORS'];
			$ProfileStatus = isset( $PayPalResult['STATUS'] ) ? $PayPalResult['STATUS'] : 'Unknown';

			$ResponseArray = array(
				'PayPalResult'  => $PayPalResult,
				'ProfileStatus' => $ProfileStatus
			);

			return $ResponseArray;
		}

		public static function UpdateRecurringPaymentsProfile( $profile_id, $custom ) {

			$nvpstr = "&PROFILEID=" . $profile_id;
			$nvpstr .= "&PROFILEREFERENCE=$custom";

			$resArray = self::api_call( "UpdateRecurringPaymentsProfile", $nvpstr );

			return $resArray;
		}

		function RefundTransaction( $transaction_id, $partial_amt = false, $note = '' ) {
			global $psts;
			$nvpstr = "&TRANSACTIONID=" . $transaction_id;

			if ( $partial_amt ) {
				$nvpstr .= "&REFUNDTYPE=Partial";
				$nvpstr .= "&AMT=" . urlencode( $partial_amt );
				$nvpstr .= "&CURRENCYCODE=" . $psts->get_setting( 'pypl_currency' );
			} else {
				$nvpstr .= "&REFUNDTYPE=Full";
			}

			if ( $note ) {
				$nvpstr .= "&NOTE=" . urlencode( $note );
			}

			$resArray = $this->api_call( "RefundTransaction", $nvpstr );

			return $resArray;
		}

		public static function api_call( $methodName, $nvpStr ) {
			global $psts;

			//set api urls
			if ( $psts->get_setting( 'pypl_status' ) == 'live' ) {
				$API_Endpoint = "https://api-3t.paypal.com/nvp";
			} else {
				$API_Endpoint = "https://api-3t.sandbox.paypal.com/nvp";
			}

			//NVPRequest for submitting to server
			$query_string = "METHOD=" . urlencode( $methodName ) . "&VERSION=63.0&PWD=" . urlencode( $psts->get_setting( 'pypl_api_pass' ) ) . "&USER=" . urlencode( $psts->get_setting( 'pypl_api_user' ) ) . "&SIGNATURE=" . urlencode( $psts->get_setting( 'pypl_api_sig' ) ) . $nvpStr;

			//print_r(deformatNVP($query_string));

			//build args
			$args['user-agent']  = "Pro Sites: http://premium.wpmudev.org/project/pro-sites | PayPal Express/Pro Gateway";
			$args['body']        = $query_string;
			$args['sslverify']   = false; //many servers don't have an updated CA bundle
			$args['timeout']     = 60;
			$args['httpversion'] = '1.1';

			//use built in WP http class to work with most server setups
			$response = wp_remote_post( $API_Endpoint, $args );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) != 200 ) {
				trigger_error( 'Pro Sites: Problem contacting PayPal API - ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'Response code: ' . wp_remote_retrieve_response_code( $response ) ), E_USER_WARNING );

				return false;
			} else {
				//convert NVPResponse to an Associative Array
				$nvpResArray = self::deformatNVP( $response['body'] );

				return $nvpResArray;
			}
		}

		public static function RedirectToPayPal( $token ) {
			global $psts;

			//set api urls
			if ( $psts->get_setting( 'pypl_status' ) == 'live' ) {
				$paypalURL = "https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout";
			} else {
				$paypalURL = "https://www.sandbox.paypal.com/webscr?cmd=_express-checkout";
			}

			// Redirect to paypal.com here
			$paypalURL = add_query_arg(
				array(
					'token' => $token
				),
				$paypalURL
			);
			wp_redirect( $paypalURL );
			exit;
		}

		//This function will take NVPString and convert it to an Associative Array and it will decode the response.
		public static function deformatNVP( $nvpstr ) {
			parse_str( $nvpstr, $nvpArray );

			return $nvpArray;
		}

		/**
		 * @param $frequency
		 * @param string $period
		 *
		 * @return string
		 */
		public static function startDate( $frequency, $period = 'month' ) {
			$result = strtotime( "+$frequency $period" );

			return urlencode( gmdate( 'Y-m-d\TH:i:s.00\Z', $result ) );
		}

		/**
		 * @param $expire_stamp
		 *
		 * @return string
		 */
		public static function modStartDate( $expire_stamp ) {
			return urlencode( gmdate( 'Y-m-d\TH:i:s.00\Z', $expire_stamp ) );
		}
	}
}