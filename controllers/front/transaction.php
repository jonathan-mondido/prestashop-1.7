<?php

class mondidopayTransactionModuleFrontController extends ModuleFrontController
{
	/**
	 * @see FrontController::postProcess()
	 */
	public function postProcess()
	{
	    // Init Module
        $this->module = Module::getInstanceByName('mondidopay');

        $transaction_id = Tools::getValue('id');
        if (empty($transaction_id)) {
            header(sprintf('%s %s %s', 'HTTP/1.1', '400', 'FAILURE'), true, '400');
            $this->module->log('Error: Invalid transaction ID');
            exit('Error: Invalid transaction ID');
        }

        $payment_ref = Tools::getValue('payment_ref');
        $status = Tools::getValue('status');

		// Use cache to prevent multiple requests
		$cache_id = 'mondido_transaction_' .$transaction_id . $status;
		if (Cache::getInstance()->exists($cache_id)) {
			header(sprintf('%s %s %s', 'HTTP/1.1', '400', 'FAILURE'), true, '400');
			$this->module->log("Payment confirmation rejected. Transaction ID: {$transaction_id}. Status: {$status}");
			exit("Payment confirmation rejected. Transaction ID: {$transaction_id}. Status: {$status}");
		}

		Cache::getInstance()->set($cache_id, true, 60);

		// Lookup transaction
		$transaction_data = $this->module->lookupTransaction($transaction_id);
		if (!$transaction_data) {
			header(sprintf('%s %s %s', 'HTTP/1.1', '400', 'FAILURE'), true, '400');
			$this->module->log('Error: Failed to verify transaction');
			exit('Failed to verify transaction');
		}

        $cart_id = str_replace(['dev', 'a'], '', $payment_ref);
        $cart = new Cart($cart_id);
        $currency =  new Currency((int)$cart->id_currency);

        // Verify hash
        $total = number_format($cart->getOrderTotal(true, 3), 2, '.', '');
        $hash = md5(sprintf('%s%s%s%s%s%s%s',
            $this->module->merchantID,
            $payment_ref,
            $cart->id_customer,
            $total,
            strtolower($currency->iso_code),
            $status,
            $this->module->secretCode
        ));
        if ($hash !== Tools::getValue('response_hash')) {
            header(sprintf('%s %s %s', 'HTTP/1.1', '400', 'FAILURE'), true, '400');
            $this->module->log('Error: Hash verification failed');
            exit('Hash verification failed');
        }

        // Wait for order placement by customer
        set_time_limit(0);
        $times = 0;

        // Lookup Order
        $order_id = mondidopay::getOrderByCartId($cart_id);
        while (!$order_id) {
            $times++;
            if ($times > 6) {
                break;
            }
            sleep(10);

            // Lookup Order
            $order_id = mondidopay::getOrderByCartId($cart_id);
        }

        // Order was placed
        if (!$order_id) {
	        // Place order
	        $this->module->validateOrder(
		        $cart->id,
		        Configuration::get('PS_OS_MONDIDOPAY_PENDING'),
		        $total,
		        $this->module->displayName,
		        null,
		        [],
		        $currency->id,
		        false,
		        $cart->secure_key
	        );

	        $order_id = $this->module->currentOrder;
        }

        // Update Order status
        $statuses = [
            'pending' => Configuration::get('PS_OS_MONDIDOPAY_PENDING'),
            'approved' => Configuration::get('PS_OS_MONDIDOPAY_APPROVED'),
            'authorized' => Configuration::get('PS_OS_MONDIDOPAY_AUTHORIZED'),
            'declined' => Configuration::get('PS_OS_MONDIDOPAY_DECLINED'),
            'failed' => Configuration::get('PS_OS_ERROR')
        ];
		$id_order_state = $statuses[$status];

		$order = new Order($order_id);
		if ((int)$order->current_state !== (int)$id_order_state) {
			// Set the order status
			$new_history = new OrderHistory();
			$new_history->id_order = (int)$order->id;
			$new_history->changeIdOrderState((int)$id_order_state, $order, true);
			$new_history->addWithemail(true);

			if (in_array($status, ['approved', 'authorized'])) {
				$this->module->confirmOrder($order_id, $transaction_data);
			}
		}

        header(sprintf('%s %s %s', 'HTTP/1.1', '200', 'OK'), true, '200');
        $this->module->log("Order was placed by WebHook. Order ID: {$order_id}. Transaction status: {$transaction_data['status']}");
        exit("Order was placed by WebHook. Order ID: {$order_id}. Transaction status: {$transaction_data['status']}");
	}
}
