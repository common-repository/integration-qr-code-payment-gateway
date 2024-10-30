<?php
/**
 * The admin-facing functionality of the plugin.
 *
 * @package    NEGPAY QR Code Payment Gateway
 * @subpackage Includes
 * @author     Davkharbayar Myagmar
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 */

// add Gateway to woocommerce
add_filter( 'woocommerce_payment_gateways', 'negpaywc_woocommerce_payment_add_gateway_class' );



function negpaywc_woocommerce_payment_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_negpay_Payment_Gateway'; // class name
	
	return $gateways;
}

/*
 * Класс өөрөө,  plugins_loaded үйлдлийн  дотор байгааг анхаарна уу
 */
add_action( 'plugins_loaded', 'negpaywc_payment_gateway_init' );


function negpaywc_payment_gateway_init() {

	// Хэрэв WooCommerce төлбөрийн гетвей класс байхгүй бол юу ч буцаж ирэхгүй
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
	

	class WC_negpay_Payment_Gateway extends WC_Payment_Gateway {
		/**
		 * Байгуулагч
		 */
		public function __construct() {
	  
			$this->id                 = 'wc-negpay';
			$this->icon               = apply_filters( 'negpaywc_custom_gateway_icon', NEGPAY_WOO_PLUGIN_DIR . 'includes/icon/logo.png' );
			$this->has_fields         = true;
			$this->method_title       = __( 'negpay QR Code', 'negpay-qr-code-payment-for-woocommerce' );
			$this->method_description = __( 'QPay төлбөрийн үйлчилгээг ашиглан бүх банкны аппликейшнээс төлбөр төлөх боломжтой үйлчилгээ юм.', 'negpay-qr-code-payment-for-woocommerce' );
			$this->order_button_text  = __( 'Proceed to Payment', 'negpay-qr-code-payment-for-woocommerce' );
			// Method with all the options fields
			$this->api_key 				= $this->get_option( 'api_key' );
            $this->init_form_fields();
         
            // Load the settings.
            $this->init_settings();
		  
			// Define user set variables
			$this->title                = $this->get_option( 'title' );
			$this->description          = $this->get_option( 'description' );
			$this->instructions         = $this->get_option( 'instructions', $this->description );
			$this->confirm_message      = $this->get_option( 'confirm_message' );
			$this->thank_you            = $this->get_option( 'thank_you' );
			$this->payment_status       = $this->get_option( 'payment_status', 'on-hold' );
			$this->name 	            = $this->get_option( 'name' );
			$this->pay_button 		    = $this->get_option( 'pay_button' );
			$this->app_theme 		    = $this->get_option( 'theme', 'light' );
			$this->additional_content   = $this->get_option( 'additional_content' );
			$this->default_status       = apply_filters( 'negpaywc_process_payment_order_status', 'pending' );
			$this->work_mode       		= $this->get_option( 'work_mode', 'production' );
			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// We need custom JavaScript to obtain the transaction number
	        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );


			// thank you page output
			add_action( 'woocommerce_receipt_'.$this->id, array( $this, 'negpay_generate_qr_code' ), 4, 1 );

			// verify payment from redirection
            add_action( 'woocommerce_api_negpaywc-payment', array( $this, 'capture_payment' ) );

			add_action( 'woocommerce_api_negpaywc-error-handling', array( $this, 'get_error_handling' ) );

			// add support for payment for on hold orders
			add_action( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'on_hold_payment' ), 10, 2 );

			// change wc payment link if exists payment method is QR Code
			add_filter( 'woocommerce_get_checkout_payment_url', array( $this, 'custom_checkout_url' ), 10, 2 );
			
			// add custom text on thankyou page
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );

			if ( ! $this->is_valid_for_use() ) {
                $this->enabled = 'no';
            }
			
			//webhook
			add_action('woocommerce_api_negpay_payment', array($this, 'check_ipn_response'));
			
			add_filter('woocommerce_api_negpay_expire_date', array($this, 'get_expire_date'));

            
		}


		/**
	     * Тухайн хэрэглэгчийн улын мөнгөн тэмдэгтийг байгаа нь.
	     *
	     * @return bool
	     */
	    public function is_valid_for_use() {
			if ( in_array( get_woocommerce_currency(), apply_filters( 'negpaywc_supported_currencies', array( 'MNT' ) ) ) ) {
				return true;
			}

	    	return false;
        }
        
        /**
	     * Admin Panel Options.
	     *
	     * @since 1.0.0
	     */
	    public function admin_options() {
	    	if ( $this->is_valid_for_use() ) {
	    		parent::admin_options();
	    	} else {
	    		?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( 'Gateway disabled', 'negpay-qr-code-payment-for-woocommerce' ); ?></strong>: <?php _e( 'Энэ сан нь таны дэлгүүрийн валютыг дэмжихгүй байна. QR төлбөр нь зөвхөн Монголын валют (₮)-ийг дэмждэг болно. Дэмжлэг авахын хүсвэл хөгжүүлэгчтэй холбоо барина уу.', 'negpay-qr-code-payment-for-woocommerce' ); ?>
	    			</p>
	    		</div>
	    		<?php
	    	}
        }
	
		/**
		 * Гарцын тохиргооны  талбарууд
		 */
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title'       => __( 'Идэвхтэй/Идэвхгүй:', 'negpay-qr-code-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'NEGPAY-QPAY үйлчилгээг идэвхжүүлэх', 'negpay-qr-code-payment-for-woocommerce' ),
					'description' => __( 'Хэрэв та худалдан авагчдаас төлбөрийг QPAY үйлчилгээгээр авахыг хүсвэл идэвхжүүлнэ үү!', 'negpay-qr-code-payment-for-woocommerce' ),
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'title' => array(
					'title'       => __( 'Title:', 'negpay-qr-code-payment-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Төлбөрийн хэрэгсэл сонгох хэсэгт харагдана.', 'negpay-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'NEGPAY-QR', 'negpay-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Description:', 'negpay-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Төлбөрийн хэрэгсэл сонгох хэсгийн тайлбар хэсэгт харагдана.', 'negpay-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Хэрэглэгч та бүх банкны аппликэйшн дахь QPay цэснээс төлбөрөө хялбар, аюулгүй төлөх боломжтой.', 'negpay-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => true,
				),
				'instructions' => array(
					'title'       => __( 'Тайлбар: ', 'negpay-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Вэб сайтруу хандсан тохиолдолд төлбөрийн цонхон дээр гарч ирэх тайлбар', 'negpay-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Төлбөр төлөгдсөний дараа таны захиалга идэвхжихийг анхаарна уу? Төлбөриийг бүх төрлийн банкны аппликейшнээс дээрх QR кодыг уншуулан төлөх боломжтой.', 'negpay-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => true,
				),
				'confirm_message' => array(
					'title'       => __( 'Худалдан авалтын дараах мессеж:', 'negpay-qr-code-payment-for-woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Энэ нь төлбөрийг боловсруулах текст хэлбэрээр харилцагч руу илгээсэн мессежийг харуулна.', 'negpay-qr-code-payment-for-woocommerce' ),
					'default'     => __( 'Баталгаажуулах дээр дарж, данснаасаа мөнгө хасагдсаны дараа дарна уу. Бид таны гүйлгээг гараар баталгаажуулах болно. Та итгэлтэй байна уу?', 'negpay-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => true,
				),
                'thank_you' => array(
                    'title'       => __( 'Баярлалаа мессеж:', 'negpay-qr-code-payment-for-woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'This displays a message to customer after a successful payment is made.', 'negpay-qr-code-payment-for-woocommerce' ),
                    'default'     => __( 'Төлбөр төлсөнд баярлалаа. Таны гүйлгээ дууссан бөгөөд таны захиалга амжилттай хийгдсэн байна. Дэлгэрэнгүй мэдээллийг имэйлээр ирсэн имэйл хаягаар шалгана уу. Гүйлгээний дэлгэрэнгүй мэдээллийг үзэхийн тулд банкны дансны хуулгаа шалгана уу.', 'negpay-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => true,
				),
				'payment_status' => array(
                    'title'       => __( 'Төлөгдсөн захиалгын төлөв:', 'negpay-qr-code-payment-for-woocommerce' ),
                    'type'        => 'select',
					'description' =>  __( 'Qpay үйлчилгээгээр төлбөр төлөгдсөний дараах захиалгын төлөв', 'negpay-qr-code-payment-for-woocommerce' ),
					'desc_tip'    => true,
                    'default'     => 'on-hold',
                    'options'     => apply_filters( 'negpaywc_settings_order_statuses', array(
						'pending'      => __( 'Pending Payment', 'negpay-qr-code-payment-for-woocommerce' ),
						'on-hold'      => __( 'On Hold', 'negpay-qr-code-payment-for-woocommerce' ),
						'processing'   => __( 'Processing', 'negpay-qr-code-payment-for-woocommerce' ),
						'completed'    => __( 'Completed', 'negpay-qr-code-payment-for-woocommerce' )
                    ) )
                ),
				'theme' => array(
                    'title'       => __( 'Төлбөрийн цонхны загвар:', 'negpay-qr-code-payment-for-woocommerce' ),
                    'type'        => 'select',
					'desc_tip' => true,
					'description' =>  __( 'Арын дэвсгэрийн өнгийг өөрчилнө', 'negpay-qr-code-payment-for-woocommerce' ),
                    'default'     => 'light',
                    'options'     => apply_filters( 'negpaywc_popup_themes', array(
						'light'     => __( 'Цагаан', 'negpay-qr-code-payment-for-woocommerce' ),
						'dark'      => __( 'Хар', 'negpay-qr-code-payment-for-woocommerce' )
                    ) )
                ),
				'work_mode' => array(
                    'title'       => __( 'Ажиллах горим:', 'negpay-qr-code-payment-for-woocommerce' ),
                    'type'        => 'select',
					'desc_tip' => true,
					'description' =>  __( 'Тестээр ажиллуулж үзэх боломжтой', 'negpay-qr-code-payment-for-woocommerce' ),
                    'default'     => 'production',
                    'options'     => apply_filters( 'negpaywc_popup_themes', array(
						'production' => __( 'Production', 'negpay-qr-code-payment-for-woocommerce' ),
						'test'       => __( 'Test', 'negpay-qr-code-payment-for-woocommerce' )
                    ) )
                ),
				'api_key' => array(
                        'title' => __( 'API KEY:', 'negpay-qr-code-payment-for-woocommerce'),
                        'type' => 'text',
                        'default' => __( 'API KEY', 'negpay-qr-code-payment-for-woocommerce'),
                        'desc_tip' => true,
                        'description' => __( 'NEGPAY системийн зөвшөөрөл түлхүүр.', 'negpay-qr-code-payment-for-woocommerce')
                ),
				'expire_date' => array(
                        'title' => __( 'Лицензийн дуусах огноо:', 'negpay-qr-code-payment-for-woocommerce'),
                        'type' => 'text',
						'default' => $this->get_expire_date(),
						'custom_attributes' => array('readonly' => 'readonly'),
                ),
				
			);
		}
		
		
		public function get_expire_date(){
			$payload = array("x_apikey" => $this->api_key);
			$response = wp_remote_post(NEGPAY_WOO_API.'/api/apikey/expiredate', array(
			  'method'    => 'POST',
			  'body'      => http_build_query($payload),
			  'timeout'   => 1000,
			  'sslverify' => false,
			 ));
			 if ( is_wp_error( $response ) ) {
				return '0000-00-00'; 
			 }
			 if ( empty( $response['body'] ))
			 {
				return '0000-00-00';
			 }
			$response_body = json_decode($response['body']);
			if(empty($response_body)){
				return '0000-00-00'; 
			}
			if($response_body->response_code == 1){
				return $response_body->expireDate;
			}else{
				return '0000-00-00';
			}
		}
		


		/**
		 * wSD ID талбарыг харуулах
		 */
		public function payment_fields() {
			// display description before the payment form
	        if ( $this->description ) {
	        	// display the description with <p> tags
	        	echo wpautop( wp_kses_post( $this->description ) );
			}
		}

		/**
		 * Validate  ID field
		 */
		public function validate_fields() {
			return true;
		}

		/**
		 * Нэмэлт JS ба CSS тохиргоо
		 */
		public function payment_scripts() {
			// Хэрэв манай төлбөрийн гарц идэвхгүй бол бид JS-г бас дараалалд оруулах шаардлагагүй
	        if ( 'no' === $this->enabled ) {
	        	return;
			}

			$ver = NEGPAY_WOO_PLUGIN_VERSION;
            if( defined( 'NEGPAY_WOO_PLUGIN_ENABLE_DEBUG' ) ) {
                $ver = time();
			}
			
			if ( is_checkout() ) {
			    wp_enqueue_style( 'negpaywc-selectize', plugins_url( 'css/selectize.min.css' , __FILE__ ), array(), '0.12.6' );
				wp_enqueue_script( 'negpaywc-selectize', plugins_url( 'js/selectize.min.js' , __FILE__ ), array( 'jquery' ), '0.12.6', false );
			}
		
			wp_register_style( 'negpaywc-jquery-confirm', plugins_url( 'css/jquery-confirm.min.css' , __FILE__ ), array(), '3.3.4' );
			wp_register_style( 'negpaywc-qr-code', plugins_url( 'css/negpay.min.css' , __FILE__ ), array( 'negpaywc-jquery-confirm' ), $ver );
			wp_register_script( 'negpaywc-qr-code', plugins_url( 'js/easy.qrcode.min.js' , __FILE__ ), array( 'jquery' ), '4.4.6', true );
	      	wp_register_script( 'negpaywc-jquery-confirm', plugins_url( 'js/jquery-confirm.min.js' , __FILE__ ), array( 'jquery' ), '3.3.4', true );
		    wp_register_script( 'negpaywc', plugins_url( 'js/negpay.min.js' , __FILE__ ), array( 'jquery', 'negpaywc-qr-code', 'negpaywc-jquery-confirm' ), $ver, true );
			wp_register_script( 'negpaywc-jquery-confirm', plugins_url( 'js/jquery-confirm.min.js' , __FILE__ ), array( 'jquery' ), '3.3.4', true );
			wp_enqueue_script('jquery'); 
			wp_register_script( 'lottie-player', plugins_url( 'js/lottie-player.js' , __FILE__ ), false, NULL, true );
			wp_register_script( 'socket-io', plugins_url( 'js/socket.io.js' , __FILE__ ), false, NULL, true );
		}

		/**
		 * Төлбөрийг боловсруулж, үр дүнг буцаана
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
			$message = __( 'Төлбөрийн мэдээллийг хүлээж байна.!', 'negpay-qr-code-payment-for-woocommerce' );

			//Хүлээгдэж буй  (төлбөрийг хүлээх)
			$order->update_status( $this->default_status );

			// update meta
			update_post_meta( $order->get_id(), '_negpaywc_order_paid', 'no' );

			// Захиалгын нэмэлт тэмдэглэл
			$order->add_order_note( apply_filters( 'negpaywc_process_payment_note', $message, $order ), false );

			if ( apply_filters( 'negpaywc_payment_empty_cart', false ) ) {
			    // Сагс хоослох
			    WC()->cart->empty_cart();
			}

			do_action( 'negpaywc_after_payment_init', $order_id, $order );


			// Төлбөр төлөлтийн дараах хуудас дуудах
			return array(
				'result' 	=> 'success',
				'redirect'	=> apply_filters( 'negpaywc_process_payment_redirect', $order->get_checkout_payment_url( true ), $order )
			);
		}
		
		
		
		
		/**
	     *
	     * @param WC_Order $order_id Order id.
	     * @return string
	     */
		 
		public function negpay_generate_qr_code( $order_id ) {
			
		  $customer_order = wc_get_order( $order_id );
			$totalone =  apply_filters( 'negpaywc_order_total_amount', $customer_order->get_total(), $customer_order );
			$work_mode = $this->work_mode;
			if($work_mode == 'production'){
					$payload = array(
					   "x_amount"               => $totalone,
					   "x_orderKey"             => $customer_order->get_order_key(),
					   "x_email"                =>  $customer_order->get_billing_email(),
					   "x_apikey"				=> $this->api_key,
					   "x_request_url" 			=> get_site_url() . '/wc-api/negpay_payment',
					   "x_device" 				=> (wp_is_mobile() ) ? 'Гар утас' : 'Компьютер',
					   "x_store_order_id"       => $order_id
					 );
					$response = wp_remote_post(NEGPAY_WOO_API.'/api/payment', array(
					  'method'    => 'POST',
					  'body'      => http_build_query( $payload ),
					  'timeout'   => 1000,
					  'sslverify' => false,
					 ));
					 if ( is_wp_error( $response ) ) {
					 $this->get_error_handling($customer_order);
						return; 
						//throw new Exception( __( 'Төлбөрийн системтэй холбогдох үед алдаа гарлаа уучлаарай', 'negpay-qr-code-payment-for-woocommerce' ) );

					 }
					 if ( empty( $response['body'] ))
					 {
						$this->get_error_handling($customer_order);
						return; 			
					 }
					$response_body = json_decode($response['body']);
					if(empty($response_body)){
						$this->get_error_handling($customer_order);
						return; 
					}
					
					if ( ($response_body->response_code == 1 ) || ( $response_body->response_code == 4) ) {
						$order = wc_get_order( $order_id );
						$total = apply_filters( 'negpaywc_order_total_amount', $order->get_total(), $order );
							
									wp_enqueue_style( 'negpaywc-jquery-confirm' );
									wp_enqueue_style( 'negpaywc-qr-code' );
									wp_enqueue_script( 'negpaywc-qr-code' );
									wp_enqueue_script( 'negpaywc' );
									wp_enqueue_script('lottie-player');
									wp_enqueue_script('socket-io');
									wp_localize_script( 'negpaywc', 'negpaywc_params',
									array( 
										'ajaxurl'           => admin_url( 'admin-ajax.php' ),
										'qrdata'            => $response_body->qrdata,
										'test' 				=> $response_body->qrdata,
										'qrurl' 			=> $response_body->qrurl,
										'invoiceno'			=> $response_body->invoiceNo,
										'payload'			=> $customer_order,
										'order_id'          => $order_id,
										'order_amount'      => $total,
										'order_key'         => $order->get_order_key(),
										'order_number'      => htmlentities( $order->get_order_number() ),
										'confirm_message'   => $this->confirm_message,
										'success'			=> 1,
										'processing_text'   => apply_filters( 'negpaywc_payment_processing_text', __( 'Do not close or refresh this window and wait while we are processing your request...', 'negpay-qr-code-payment-for-woocommerce' ) ),
										'callback_url'      => add_query_arg( array( 'wc-api' => 'negpaywc-payment' ), trailingslashit( get_home_url() ) ),
										'payment_url'       => $order->get_checkout_payment_url(),
										'cancel_url'        => apply_filters( 'negpaywc_payment_cancel_url', wc_get_checkout_url(), $this->get_return_url( $order ), $order ),
										'payment_status'    => $this->payment_status,
										'app_theme'         => $this->app_theme,
										'prevent_reload'    => apply_filters( 'negpaywc_enable_payment_reload', true ),
										'intent_interval'   => apply_filters( 'negpaywc_auto_open_interval', 1000 ),
										'btn_show_interval' => apply_filters( 'negpaywc_button_show_interval', 60000 ),
										'is_mobile'         =>  ( wp_is_mobile() ) ? 'yes' : 'no',
										'app_version'       => NEGPAY_WOO_PLUGIN_VERSION,
										'negpay_woo_api'    => NEGPAY_WOO_API
									)
								);

					
					if ( 'yes' === $this->enabled && $order->needs_payment() === true && $order->has_status( $this->default_status )) { ?>
						<section class="woo-wooopay-section">
							<div class="negpaywc-info">
								<h6 class="negpaywc-waiting-text"><?php echo _e('Төлбөрийн мэдээллийг боловсруулж байх үед сайтыг дахин ачааллахгүй байхыг анхаарна уу.', 'negpay-qr-code-payment-for-woocommerce' ); ?></h6>
								<button id="negpaywc-`" class="btn button" disabled="disabled"><?php _e( 'Төлбөрийн мэдээллийг хүлээж байна...', 'negpay-qr-code-payment-for-woocommerce' ); ?></button>
								<?php do_action( 'negpaywc_after_before_title', esc_html($order) ); ?>
								<div class="negpaywc-buttons" style="display: none;">
									<button id="negpaywc-confirm-payment" class="btn button" data-theme="<?php echo apply_filters( 'negpaywc_payment_dialog_theme', 'purple' ); ?>"><?php echo esc_html( apply_filters( 'negpaywc_payment_button_text', 'Худалдан авах' /*$this->pay_button*/ ) ); ?></button>
									<?php if ( apply_filters( 'negpaywc_show_cancel_button', true ) ) { ?>
										<button id="negpaywc-cancel-payment" class="btn button"><?php _e(esc_html('Буцах'), 'negpay-qr-code-payment-for-woocommerce' ); ?></button>
									<?php } ?>
								</div>
								<?php do_action( 'negpaywc_after_payment_buttons', esc_html($order)); ?>
								<div id="js_qrcode">
									<div id="negpaywc-qrcode"<?php echo isset( $style ) ? esc_attr($style) : ''; ?>><?php do_action( 'negpaywc_after_qr_code', esc_html($order)); ?></div>
									<?php if ( apply_filters( 'negpaywc_show_order_total', true ) ) { ?>
										<div id="negpaywc-order-total" class="negpaywc-order-total">
											<?php _e( esc_html('ДҮН:'), 'negpay-qr-code-payment-for-woocommerce' ); ?> <span id="negpaywc-order-total-amount-<?php echo esc_html($this->app_theme); ?>"><?php echo esc_html($total); ?> ₮</span></div>
									<?php } ?>
									<?php if ( wp_is_mobile() && apply_filters( 'negpaywc_show_direct_pay_button', true ) ) { ?>
											<div class="jconfirm-buttons" style="padding-bottom: 5px;">
												<button type="button" id="negpay-pay" class="btn btn-purple btn-negpay-pay"><?php echo apply_filters( 'negpaywc_negpay_direct_pay_text', __( 'QR төлбөр хийхийн тулд энд дарна уу!', 'negpay-qr-code-payment-for-woocommerce' ) ); ?></button>
											</div>
									<?php } ?>
									<?php if ( apply_filters( 'negpaywc_show_description', true ) ) { ?>
										<div id="negpaywc-description" class="negpaywc-description">
											<?php 
												 echo esc_html( $this->instructions);
											?>
										</div>
									<?php } ?>
								</div>
								<div id="payment-success-container" style="display: none;"></div>
							</div>
						</section><?php
					}
					
				}else if($response_body->response_code == -1){ 
					$this->get_error_handling($customer_order);
					return; 
				}
			}else{
					$order = wc_get_order( $order_id );
						$total = apply_filters( 'negpaywc_order_total_amount', $order->get_total(), $order );
							
									wp_enqueue_style('negpaywc-jquery-confirm');
									wp_enqueue_style('negpaywc-qr-code');
									wp_enqueue_script('negpaywc-qr-code');
									wp_enqueue_script('negpaywc');
									wp_localize_script( 'negpaywc', 'negpaywc_params',
									array( 
										'ajaxurl'           => admin_url( 'admin-ajax.php' ),
										'payload'			=> $customer_order,
										'order_id'          => $order_id,
										'order_amount'      => $total,
										'order_key'         => $order->get_order_key(),
										'order_number'      => htmlentities( $order->get_order_number() ),
										'confirm_message'   => $this->confirm_message,
										'success'			=> 99,
										'processing_text'   => apply_filters( 'negpaywc_payment_processing_text', __( 'Do not close or refresh this window and wait while we are processing your request...', 'negpay-qr-code-payment-for-woocommerce' ) ),
										'callback_url'      => add_query_arg( array( 'wc-api' => 'negpaywc-payment' ), trailingslashit( get_home_url() ) ),
										'payment_url'       => $order->get_checkout_payment_url(),
										'cancel_url'        => apply_filters( 'negpaywc_payment_cancel_url', wc_get_checkout_url(), $this->get_return_url( $order ), $order ),
										'payment_status'    => $this->payment_status,
										'app_theme'         => $this->app_theme,
										'prevent_reload'    => apply_filters( 'negpaywc_enable_payment_reload', true ),
										'intent_interval'   => apply_filters( 'negpaywc_auto_open_interval', 1000 ),
										'btn_show_interval' => apply_filters( 'negpaywc_button_show_interval', 300000 ),
										'is_mobile'         =>  ( wp_is_mobile() ) ? 'yes' : 'no',
										'app_version'       => NEGPAY_WOO_PLUGIN_VERSION,
									)
								);

					
					if ( 'yes' === $this->enabled && $order->needs_payment() === true && $order->has_status( $this->default_status )) { ?>
						<section class="woo-wooopay-section">
							<div class="negpaywc-info">
								<h6 class="negpaywc-waiting-text"><?php _e( 'Төлбөрийн мэдээллийг боловсруулж байх үед сайтыг дахин ачааллахгүй байхыг анхаарна уу.', 'negpay-qr-code-payment-for-woocommerce' ); ?></h6>
								<button id="negpaywc-`" class="btn button" disabled="disabled"><?php _e( 'Төлбөрийн мэдээллийг хүлээж байна...', 'negpay-qr-code-payment-for-woocommerce' ); ?></button>
								<?php do_action( 'negpaywc_after_before_title', esc_html($order) ); ?>
								<div class="negpaywc-buttons" style="display: none;">
									<button id="negpaywc-confirm-payment" class="btn button" data-theme="<?php echo apply_filters( 'negpaywc_payment_dialog_theme', 'purple' ); ?>"><?php echo esc_html( apply_filters( 'negpaywc_payment_button_text', 'Худалдан авах' /*$this->pay_button*/ ) ); ?></button>
									<?php if ( apply_filters( 'negpaywc_show_cancel_button', true ) ) { ?>
										<button id="negpaywc-cancel-payment" class="btn button"><?php _e( 'Буцах', 'negpay-qr-code-payment-for-woocommerce' ); ?></button>
									<?php } ?>
								</div>
								<?php do_action( 'negpaywc_after_payment_buttons', esc_html($order) ); ?>
								<div id="js_qrcode">
									<div id="negpaywc-qrcode"<?php echo isset( $style ) ? esc_attr($style) : ''; ?>><?php do_action( 'negpaywc_after_qr_code', esc_html($order) ); ?></div>
									<?php if ( apply_filters( 'negpaywc_show_order_total', true ) ) { ?>
										<div id="negpaywc-order-total" class="negpaywc-order-total"><?php _e( 'ДҮН:', 'negpay-qr-code-payment-for-woocommerce' ); ?> <span id="negpaywc-order-total-amount-<?php echo $this->app_theme; ?>"><?php echo esc_html($total); ?> ₮</span></div>
									<?php } ?>
									
									<?php if ( wp_is_mobile() && apply_filters( 'negpaywc_show_direct_pay_button', true ) ) { ?>
											<div class="jconfirm-buttons" style="padding-bottom: 5px;">
												<button type="button" id="negpay-pay" class="btn btn-purple btn-negpay-pay"><?php echo apply_filters( 'negpaywc_negpay_direct_pay_text', __( 'QR төлбөр хийхийн тулд энд дарна уу!', 'negpay-qr-code-payment-for-woocommerce' ) ); ?></button>
											</div>
									<?php } ?>
									<?php if ( apply_filters( 'negpaywc_show_description', true ) ) { ?>
										<div id="negpaywc-description" class="negpaywc-description">
											<?php 
												 echo esc_html($this->instructions);
											?>
										</div>
									<?php } ?>
								</div>
								<div id="payment-success-container" style="display: none;"></div>
							</div>
						</section><?php
					}		
		
			}
		}
		
		//Алдаа ны мэдээлэл 
		
		public function get_error_handling($customer_order){
			wp_enqueue_style( 'negpaywc-jquery-confirm' );
			wp_enqueue_style( 'negpaywc-qr-code' );
			wp_enqueue_script( 'negpaywc-qr-code' );
			wp_enqueue_script( 'negpaywc' );
			wp_enqueue_script('lottie-player');
			wp_localize_script( 'negpaywc', 'negpaywc_params',
				array( 
					'message'           => 'Төлбөрийн сервис идэвхигүй байна.',
					'app_theme'         => $this->app_theme,
					'is_mobile'         =>  ( wp_is_mobile() ) ? 'yes' : 'no',
					'success'			=> 999,
					'cancel_url'        => apply_filters( 'negpaywc_payment_cancel_url', wc_get_checkout_url(), $this->get_return_url( $customer_order ), $customer_order ),
					'app_version'       => NEGPAY_WOO_PLUGIN_VERSION,
				)
			)
									
			?>
					<section class="woo-negpay-section">
						<div class="negpaywc-info">
						<div id="js_qrcode">
							<div id="negpaywc-qrcode"<?php echo isset($style) ? esc_attr($style) : ''; ?>></div>
						</div>
						</div>
												<div id="js_qrcode">
									<div id="negpaywc-qrcode"<?php echo isset( $style ) ? esc_attr($style) : ''; ?>><?php do_action( 'negpaywc_after_qr_code',  esc_html($order) ); ?></div>
									<?php if ( apply_filters( 'negpaywc_show_order_total', true ) ) { ?>
										<div id="negpaywc-order-total" class="negpaywc-order-total"><?php _e( 'ДҮН:', 'negpay-qr-code-payment-for-woocommerce' ); ?> <span id="negpaywc-order-total-amount-<?php echo esc_html($this->app_theme) ; ?>"><?php echo esc_html($total); ?> ₮</span></div>
									<?php } ?>
									<?php if ( wp_is_mobile() && apply_filters( 'negpaywc_show_direct_pay_button', true ) ) { ?>
			
											<div class="jconfirm-buttons" style="padding-bottom: 5px;">
												<button type="button" id="negpay-pay" class="btn btn-dark btn-negpay-pay"><?php echo apply_filters( 'negpaywc_negpay_direct_pay_text', __( 'QR төлбөр хийхийн тулд энд дарна уу!', 'negpay-qr-code-payment-for-woocommerce' ) ); ?></button>
											</div>
									<?php } ?>
									<?php if ( apply_filters( 'negpaywc_show_description', true ) ) { ?>
										<div id="negpaywc-description" class="negpaywc-description">
											<?php
												echo esc_html($this->instructions)
											 ?>
										</div>
									<?php } ?>
								</div>
								<div id="payment-success-container" style="display: none;"></div>
					</section><?php
			return;
			
		}

		/**
	     * Төлбөрийн баталгаажуулалтыг боловсруулах.
	     */
        public function capture_payment() {
            // get order id
            if ( ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) || ! isset( $_GET['wc-api'] ) || ( 'negpaywc-payment' !== $_GET['wc-api'] ) ) {
                return;
            }

			$order_id = wc_get_order_id_by_order_key( sanitize_text_field( $_POST['wc_order_key'] ) );
			$order = wc_get_order( $order_id );
            
            if ( is_a( $order, 'WC_Order' ) ) {
				$order->update_status( apply_filters( 'negpaywc_capture_payment_order_status', $this->payment_status ) );

				wc_reduce_stock_levels( $order->get_id() );

				if ( in_array( $this->payment_status, apply_filters( 'negpaywc_valid_order_status_for_note', array( 'pending', 'on-hold' ) ) ) ) {
		            $order->add_order_note( __( 'Төлбөрийн процесс дууссан. Захиалгыг шалгаад баталгаажуулаарай', 'negpay-qr-code-payment-for-woocommerce' ), false );
				}

				update_post_meta( $order->get_id(), '_negpaywc_order_paid', 'yes' );

				do_action( 'negpaywc_after_payment_verify', $order->get_id(),  esc_html($order) );

				wp_safe_redirect( apply_filters( 'negpaywc_payment_redirect_url', $this->get_return_url( $order ), $order ) );
                exit;
            } else {
                $title = __( 'Энэ захиалгын мэдээлэл олдсонгүй. Хэрэв таны данснаас мөнгө хасагдсан бол сайтын админтай холбоо барьж асуудлаа шийднэ үү!', 'negpay-qr-code-payment-for-woocommerce' );
                        
                wp_die( $title, get_bloginfo( 'name' ) );
                exit;
			}
        }
        
        
		
		
		/**
	     * Custom order received text.
	     *
	     * @param string   $text Default text.
	     * @param WC_Order $order Order data.
	     * @return string
	     */
	    public function order_received_text( $text, $order ) {
	    	if ( $this->id === $order->get_payment_method() && ! empty( $this->thank_you ) ) {
	    		return esc_html( $this->thank_you );
	    	}
    
	    	return $text;
        }
		
		        /**
        * ТӨлбөрийн хүүк
        */
        public function check_ipn_response(){

            $error_msg = "Unknown error";
            $auth_status = false;
			$request_json = json_decode(file_get_contents('php://input'), true);
			if ($request_json !== false && !empty($request_json)) {
				$service_hmac = $request_json['signature'];
				$hmac = hash_hmac("sha512", $request_json['order_key'], trim($this->api_key));
					if ($hmac == $service_hmac) {
                        $auth_status = true;
                    } else {
                        $error_msg = 'Signature does not match';
                    }
			}else{
				$error_msg = "No signature sent.";
				wp_die($error_msg);
			}
			
			// if (isset($requestHeaders['HTTP_NEGPAY_SIGNATURE']) && !empty($requestHeaders['HTTP_NEGPAY_SIGNATURE'])) {
				// $service_hmac = $requestHeaders['HTTP_NEGPAY_SIGNATURE'];
				// $request_json = file_get_contents('php://input');
                // $request_data = json_decode($request_json, true);
				// $encoded_json = json_encode($request_data);
                // if ($request_json !== false && !empty($request_json)) {
                    // $hmac = hash_hmac("sha512", $encoded_json, trim($this->api_key));
                    // if ($hmac == $service_hmac) {
                        // $auth_status = true;
                    // } else {
                        // $error_msg = 'Signature does not match';
                    // }
                // } else {
                    // $error_msg = 'Error reading POST data';

                // }

			// }else{
				// $error_msg = "No signature sent.";
				// wp_die($error_msg);
			// }
            if ($auth_status) {
				$request = json_decode(file_get_contents('php://input'), true);
				$order_id = wc_get_order_id_by_order_key($request['order_key']);
				$response_order_id = $order_id; 
				$order = new WC_Order($response_order_id);
				if(!$order->has_status($this->payment_status)){
					$order->add_order_note( 'Order status: ' . $this->payment_status );
					$order->add_order_note( 'negpay.mn сервисээс илгээв ');
					$order->update_status($this->payment_status);
					$error_msg = "IPN check SUCCESS\n";
					header( 'HTTP/1.1 200 OK' );
					print $error_msg;
				}else{
					$error_msg = 'PAYMENT ALREADY COMPLETED';
				}				
					

			}else{
				wp_die($error_msg);
			}
			wp_die($error_msg);
        }
		


		/**
	     * Custom checkout URL.
	     *
	     * @param string   $url Default URL.
	     * @param WC_Order $order Order data.
	     * @return string
	     */
	    public function custom_checkout_url( $url, $order ) {
	    	if ( $this->id === $order->get_payment_method() && ( ( $order->has_status( 'on-hold' ) && $this->default_status === 'on-hold' ) || ( $order->has_status( 'pending' ) && apply_filters( 'negpaywc_custom_checkout_url', false ) ) ) ) {
	    		return esc_url( remove_query_arg( 'pay_for_order', $url ) );
	    	}
    
	    	return $url;
		}


		/**
	     * Allows payment for orders with on-hold status.
	     *
	     * @param string   $statuses  Default status.
	     * @param WC_Order $order     Order data.
	     * @return string
	     */
		public function on_hold_payment( $statuses, $order ) {
			if ( $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) && $order->get_meta( '_negpaywc_order_paid', true ) !== 'yes' && $this->default_status === 'on-hold' ) {
				$statuses[] = 'on-hold';
			}
		
			return $statuses;
		}
    }
}