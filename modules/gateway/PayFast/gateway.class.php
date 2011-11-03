<?php
/**
 * gateway.class.php
 *
 * Gateway Class for PayFast
 * 
 * Copyright (c) 2009-2011 PayFast (Pty) Ltd
 * 
 * LICENSE:
 * 
 * This payment module is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation; either version 3 of the License, or (at
 * your option) any later version.
 * 
 * This payment module is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser General Public
 * License for more details.
 * 
 * @author     Jonathan Smit
 * @copyright  Portions Copyright Devellion Limited 2005
 * @copyright  2009-2011 PayFast (Pty) Ltd
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://www.payfast.co.za/help/cube_cart
 */

class Gateway {
	private $_config;
	private $_module;
	private $_basket;

	// {{{
	/**
	 * __construct
     */
	public function __construct( $module = false, $basket = false )
	{
		$this->_config	=& $GLOBALS['config'];
		$this->_session	=& $GLOBALS['user'];

		$this->_module	= $module;
		$this->_basket =& $GLOBALS['cart']->basket;
	}
	// }}}
	
	##################################################

	// {{{
	/**
	 * transfer
     */
	public function transfer()
	{
		$transfer	= array(
			'action'	=> ($this->_module['testMode']) ? 'https://sandbox.payfast.co.za/eng/process' : 'https://www.payfast.co.za/eng/process',
			'method'	=> 'post',
			'target'	=> '_self',
			'submit'	=> 'auto',
			);
		
		return $transfer;
	}
	// }}}
	// {{{
	/**
	 * repeatVariables
     */
	public function repeatVariables() {
		return false;
	}
	// }}}
	// {{{
	/**
	 * fixedVariables
     */
	public function fixedVariables() {
        // Include PayFast common file
        define( 'PF_DEBUG', ( $this->_module['debug_log'] ? true : false ) );
        include_once( 'payfast_common.inc' );
    
        // Use appropriate merchant identifiers
        // Live
        if( $this->_module['testMode'] == 0 )
        {
            $merchantId = $this->_module['merchant_id']; 
            $merchantKey = $this->_module['merchant_key'];
        }
        // Sandbox
        else
        {
            $merchantId = '10000100'; 
            $merchantKey = '46f0cd694581a';
        }

        // Create description
        $description = '';
        foreach( $this->_basket['contents'] as $item )
            $description .= $item['quantity'] .' x '. $item['name'] .' @ '.
                number_format( $item['price']/$item['quantity'], 2, '.', ',' ) .'ea = '.
                number_format( $item['price'], 2, '.', ',' ) .'; ';  
        $description .= 'Shipping = '. $this->_basket['shipping']['value'] .'; ';
        $description .= 'Tax = '. $this->_basket['total_tax'] .'; ';
        $description .= 'Total = '. $this->_basket['total'];

		$hidden	= array(
            //// Merchant details
            'merchant_id' => $merchantId,
            'merchant_key' => $merchantKey,

            //// Customer details
        	'name_first' => substr( trim( $this->_basket['billing_address']['first_name'] ), 0, 100 ),
        	'name_last' => substr( trim( $this->_basket['billing_address']['last_name'] ), 0, 100 ),
            'email_address' => substr( trim( $this->_basket['billing_address']['email'] ), 0, 255 ),

            //// Item details
    		'item_name' => $GLOBALS['config']->get('config', 'store_name') .' Purchase, Order #'. $this->_basket['cart_order_id'],
    		'item_description' => substr( trim( $description ), 0, 255 ),
            'amount' => number_format($this->_basket['total'], 2, '.', '' ),
    		'm_payment_id' => $this->_basket['cart_order_id'],
    		'currency_code' => $GLOBALS['config']->get('config', 'default_currency'),
            
            // Other details
            'user_agent' => PF_USER_AGENT,

			## ITN and Return URLs
            // Create URLs
        	'return_url' => $GLOBALS['storeURL'].'/index.php?_a=complete',
        	'cancel_url' => $GLOBALS['storeURL'].'/index.php?_a=gateway',
            'notify_url' => $GLOBALS['storeURL'] .'/index.php?_g=rm&amp;type=gateway&amp;cmd=call&amp;module=PayFast',
		);
		return $hidden;
	}
	// }}}
	// {{{
	/**
	 * call
     */
	public function call() {
        // Include PayFast common file
        define( 'PF_DEBUG', ( $this->_module['debug_log'] ? true : false ) );
        include_once( 'payfast_common.inc' );
        
        // Variable Initialization
        $pfError = false;
        $pfNotes = array();
        $pfData = array();
        $pfHost = ( ( $this->_module['testMode'] == 1 ) ? 'sandbox' : 'www' ) .'.payfast.co.za';
        $orderId = '';
        $pfParamString = '';
        
        $pfErrors = array();
        
        pflog( 'PayFast ITN call received' );
        
        //// Set debug email address
        $pfDebugEmail = ( strlen( $this->_module['debug_email'] ) > 0 ) ?
            $this->_module['debug_email'] : $this->_config->get('config', 'masterEmail');
        
        //// Notify PayFast that information has been received
        if( !$pfError )
        {
            header( 'HTTP/1.0 200 OK' );
            flush();
        }
        
        //// Get data sent by PayFast
        if( !$pfError )
        {
            pflog( 'Get posted data' );
        
            // Posted variables from ITN
            $pfData = pfGetData();
        
            pflog( 'PayFast Data: '. print_r( $pfData, true ) );
        
            if( $pfData === false )
            {
                $pfError = true;
                $pfNotes[] = PF_ERR_BAD_ACCESS;
            }
        }
        
        //// Verify security signature
        if( !$pfError )
        {
            pflog( 'Verify security signature' );
        
            // If signature different, log for debugging
            if( !pfValidSignature( $pfData, $pfParamString ) )
            {
                $pfError = true;
                $pfNotes[] = PF_ERR_INVALID_SIGNATURE;
            }
        }
        
        //// Verify source IP (If not in debug mode)
        if( !$pfError && !PF_DEBUG )
        {
            pflog( 'Verify source IP' );
            
            if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
            {
                $pfError = true;
                $pfNotes[] = PF_ERR_BAD_SOURCE_IP;
            }
        }
        
        //// Retrieve order from CubeCart
        if( !$pfError )
        {
            pflog( 'Get order' );
        
            $orderId = $pfData['m_payment_id'];
			$order				= Order::getInstance();
			$order_summary		= $order->getSummary($orderId);

            pflog( 'Order ID = '. $orderId );
        }
        
        //// Verify data
        if( !$pfError )
        {
            pflog( 'Verify data received' );
        
            if( $config['proxy'] == 1 )
                $pfValid = pfValidData( $pfHost, $pfParamString, $config['proxyHost'] .":". $config['proxyPort'] );
            else
                $pfValid = pfValidData( $pfHost, $pfParamString );
        
            if( !$pfValid )
            {
                $pfError = true;
                $pfNotes[] = PF_ERR_BAD_ACCESS;
            }
        }
        
        //// Check status and update order & transaction table
        if( !$pfError )
        {
            pflog( 'Check status and update order' );
        
            $success = true;
        
        	// Check the payment_status is Completed
        	if( $pfData['payment_status'] !== 'COMPLETE' )
            {
        		$success = false;
        
        		switch( $pfData['payment_status'] )
                {
            		case 'FAILED':
                        $pfNotes = PF_MSG_FAILED;
            			break;
        
        			case 'PENDING':
                        $pfNotes = PF_MSG_PENDING;
            			break;
        
        			default:
                        $pfNotes = PF_ERR_UNKNOWN;
            			break;
        		}
        	}
        
        	// Check if the transaction has already been processed
        	// This checks for a "transaction" in CubeCart of the same status (status)
            // for the same order (order_id) and same payfast payment id (trans_id)
			$trnId	= $GLOBALS['db']->select('CubeCart_transactions', array('id'), array('trans_id' => $pfData['pf_payment_id']));
        
        	if( $trnId == true )
            {
        		$success = false;
        		$pfNotes[] = PF_ERR_ORDER_PROCESSED;
        	}
        
        	// Check PayFast amount matches order amount
            if( !pfAmountsEqual( $pfData['amount_gross'], $order_summary['total'] ) )
            {
        		$success = false;
        		$pfNotes[] = PF_ERR_AMOUNT_MISMATCH;
        	}
        
            // If transaction is successful and correct, update order status
        	if( $success == true )
            {
        		$pfNotes[] = PF_MSG_OK;
				$order->paymentStatus(Order::PAYMENT_SUCCESS, $orderId);
				$order->orderStatus(Order::ORDER_PROCESS, $orderId);
        	}
        }
        
        //// Insert transaction entry
        // This gets done for every ITN call no matter whether successful or not.
        // The notes field is used to provide feedback to the user.
        pflog( 'Create transaction data and save' );
        
        $pfNoteMsg = '';
        if( sizeof( $pfNotes ) > 1 )
            foreach( $pfNotes as $note )
                $pfNoteMsg .= $note ."; ";
        else
            $pfNoteMsg .= $pfNotes[0];
        
        $transData = array();
        $transData['customer_id'] = $order_summary['customer_id'];
        $transData['gateway']     = "PayFast ITN";
        $transData['trans_id']    = $pfData['pf_payment_id'];
        $transData['order_id']    = $orderId;
        $transData['status']      = $pfData['payment_status'];
        $transData['amount']      = $pfData['amount_gross'];
        $transData['notes']       = $pfNoteMsg;
        
        pflog( "Transaction log data: \n". print_r( $transData, true ) );
        
        $order->logTransaction($transData);
        
        // Close log
        pflog( '', true );
	}
	// }}}
	// {{{
	/**
	 * process
     */
	public function process()
	{
		## We're being returned from PayFast - This function can do some pre-processing, but must assume NO variables are being passed around
		## The basket will be emptied when we get to _a=complete, and the status isn't Failed/Declined

		## Redirect to _a=complete, and drop out unneeded variables
		httpredir( currentPage( array( '_g', 'type', 'cmd', 'module' ), array( '_a' => 'complete' ) ) );
	}
	// }}}
	// {{{
	/**
	 * form
     */
	public function form()
	{
		return false;
	}
	// }}}
}