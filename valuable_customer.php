<?php
/*
Plugin Name: Coupon Generator
Description: Plugin to generate coupons based on phone number submission
Version: 1.0
Author: Alegnta Lolamo
*/

// Enqueue necessary scripts and styles
function coupon_generator_enqueue_scripts() {
    wp_enqueue_style('coupon-generator-style', plugin_dir_url(__FILE__) . 'style.css');
}
add_action('wp_enqueue_scripts', 'coupon_generator_enqueue_scripts');

// Display coupon form in the header
function coupon_generator_display_form() {
    ?>
    <div class="coupon-form-container">
        <form id="coupon-generator-form" action="" method="post">
            <label for="phone_number">Phone Number:</label>
            <input type="text" id="phone_number" name="phone_number" required>
            <button type="submit">Generate Coupon</button>
        </form>
    </div>
    <?php
}

add_action('wp_head', 'coupon_generator_display_form');

// Coupon generation and SMS sending logic
function coupon_generator_process_form() {
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['phone_number'])) {
        $phone_number = sanitize_text_field($_POST['phone_number']);

        // Check the occurrence of the phone number in orders
        $phone_occurrences = coupon_generator_check_phone_number_occurrences($phone_number);

        if ($phone_occurrences > 5) {
            // Assuming conditions to generate and send the coupon are met
            $coupon_code = coupon_generator_generate_unique_coupon_code();
            if (coupon_generator_create_woocommerce_coupon($coupon_code)) {
                // Prepare SMS message
                $sms_message = "ውድ ደንበኛችን የከገበሬው ኢ-ኮሜርስ ደንበኛ ስለሆኑ እናመሰግናለን ይህንን ኮድ በማስገባት 10% ቅናሽ ያግኙ:  " . $coupon_code;

                // Send SMS
                coupon_generator_send_sms($sms_message, $phone_number);

                $message = "ውድ ደንበኛችን የከገበሬው ኢ-ኮሜርስ ደንበኛ ስለሆኑ እናመሰግናለን ይህንን ኮድ በማስገባት 10% ቅናሽ ያግኙ: " . $coupon_code;
            } else {
                $message = "Failed to generate a new coupon. It might already exist.";
            }
        } else {
            $message = "This phone number has not been associated with more than five orders.";
        }
    } else {
        $message = "Please enter a valid phone number.";
    }

    // Display message
    echo "<p>$message</p>";
}
add_action('wp_loaded', 'coupon_generator_process_form');

// WooCommerce Coupon creation function
function coupon_generator_create_woocommerce_coupon($coupon_code, $discount_amount = '10', $discount_type = 'percent') {
    // Check if the coupon exists
    $coupon_post = get_page_by_title($coupon_code, OBJECT, 'shop_coupon');
    if ($coupon_post) {
        return false; // Coupon already exists, so no need to create a new one
    }

    $coupon = array(
        'post_title' => $coupon_code,
        'post_content' => '',
        'post_status' => 'publish',
        'post_author' => 1, // Assuming admin. Adjust as necessary.
        'post_type' => 'shop_coupon'
    );

    $new_coupon_id = wp_insert_post($coupon);

    // Add meta data
    update_post_meta($new_coupon_id, 'discount_type', $discount_type);
    update_post_meta($new_coupon_id, 'coupon_amount', $discount_amount);
    update_post_meta($new_coupon_id, 'individual_use', 'yes');
    update_post_meta($new_coupon_id, 'usage_limit', '1');
    update_post_meta($new_coupon_id, 'expiry_date', ''); // Set if required
    update_post_meta($new_coupon_id, 'apply_before_tax', 'yes');
    update_post_meta($new_coupon_id, 'free_shipping', 'no');

    return true;
}

// Generate a unique coupon code
function coupon_generator_generate_unique_coupon_code() {
    $prefix = "WC-";
    $coupon_code = $prefix . wp_generate_uuid4();
    return substr($coupon_code, 0, 12); // Adjust length as needed
}

// Example SMS sending function adapted for coupon notification
function coupon_generator_send_sms($text, $phone) {
  $base_URL = 'http://sms.purposeblacketh.com/api/general/send-sms';
  $ch = curl_init();
  $headers = array(
    "Accept: application/json",
    "Content-Type: application/json",
    "charset: utf-8"
  );
  curl_setopt($ch, CURLOPT_URL, $base_URL);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_HEADER, 0);

  $request_body = array(
    'phone' => strval($phone),
    'text' => strval($text)
  );

  $logger = wc_get_logger();


  // curl_setopt($ch, CURLOPT_POST, true);
  $data = json_encode($request_body);

  // Set request method 
  // curl_setopt($ch, CURL_CUSTOMREQUEST, 'POST');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($ch, CURLOPT_VERBOSE, true);

  // Timeout in seconds 
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);

  $server_output = curl_exec($ch);

  $httpReturnCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  if (curl_errno($ch)){
    $error = curl_error($ch);
    $logger->error(wc_print_r( "Sms not sent for:". $phone ."Order number is: ". $order_id . " Error: ". $error ), array('source' => 'order-created-sms-notifier-log' ));
    throw new Exception(curl_error($ch));
    return; 
  }

  $decoded_output = json_decode($server_output);
  
  if ($httpReturnCode == 200){
    $logger->notice(wc_print_r("SMS sent for: ". $phone . " content: ". $text . " Sms server response: The decoded output is: " . $decoded_output->message, true), array('source' => 'order-created-sms-notifier-log'));

  } else {
    $logger->error(wc_print_r("SMS not sent for: ". $phone . "content: ". $text. " Sms server response: The decoded output is: ".$decoded_output, true), array('source' => 'order-created-sms-notifier-log'));
  }
  
  curl_close($ch);
}

// Check the occurrence of the phone number in orders
function coupon_generator_check_phone_number_occurrences($phone_number) {
    $args = array(
        'billing_phone' => $phone_number,
        'limit' => -1, // Retrieve all orders that match the condition
    );

    $orders = wc_get_orders($args);
    return count($orders);
}