<?php
/**
 * @version 1.5
 * @link  http://www.dioscouri.com
 * @copyright Copyright (C) 2007 Dioscouri Design. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * @author  Dioscouri Design
 * @package Tienda
 */

/** ensure this file is being included by a parent file */
defined( '_JEXEC' ) or die( 'Restricted access' );

class TiendaControllerCheckout extends TiendaController
{
	var $_order                = null; // a TableOrders() object
	var $initial_order_state   = 15; // pre-payment/orphan set in costructor
	var $billing_input_prefix  = 'billing_input_';
	var $shipping_input_prefix = 'shipping_input_';
	var $defaultShippingMethod = null; // set in constructor
	var $steps 				   = array(); // set in constructor
	var $current_step 		   = 0;

	/**
	 * constructor
	 */
	function __construct()
	{
		parent::__construct();
		$this->set('suffix', 'checkout');
		// create the order object
		JTable::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'tables' );
		$this->_order = JTable::getInstance('Orders', 'TiendaTable');
		$this->defaultShippingMethod = TiendaConfig::getInstance()->get('defaultShippingMethod', '2');
		$this->initial_order_state = TiendaConfig::getInstance()->get('initial_order_state', '15');
		// Default Steps
		$this->steps = array(
            'STEP_SELECTSHIPPINGMETHOD',
            'STEP_SELECTPAYMENTMETHOD',
            'STEP_REVIEWORDER',
            'STEP_CHECKOUTRESULTS'
        );
        $this->current_step = 0;
	}

	/**
	 * Gets this view's unique namespace for request & session variables
	 * (non-PHPdoc)
	 *
	 * @see tienda/site/TiendaController#getNamespace()
	 * @return unknown
	 */
	function getNamespace()
	{
		$app = JFactory::getApplication();
		$ns = $app->getName().'::'.'com.tienda.model.checkout';
		return $ns;
	}

	/**
	 * (non-PHPdoc)
	 *
	 * @see tienda/site/TiendaController#view()
	 */
	function display()
	{
		$user = JFactory::getUser();
		JRequest::setVar( 'view', $this->get('suffix') );
		$guest = JRequest::getVar( 'guest', '0' );
		
		if($guest == '1')
			$guest = true;
		else 
			$guest = false;

		// determine layout based on login status
		// Login / Register / Checkout as a guest
		if (empty($user->id) && !$guest)
		{
			// Display a form for selecting either to register or to login
			JRequest::setVar('layout', 'form');
		}
		// Checkout as a Guest
		else if($guest && TiendaConfig::getInstance()->get('guest_checkout_enabled'))
		{
			$order = &$this->_order;
			$order = $this->populateOrder(true);

			// now that the order object is set, get the orderSummary html
			$html = $this->getOrderSummary();

			// Get the current step
			$progress = $this->getProgress();


			// get address forms
			$billing_address_form = $this->getAddressForm( $this->billing_input_prefix, true );
			$shipping_address_form = $this->getAddressForm( $this->shipping_input_prefix, true );

			// now display the entire checkout page
			$view = $this->getView( 'checkout', 'html' );
			$view->set( 'hidemenu', false);
			$view->assign( 'order', $order );
			$view->assign( 'billing_address_form', $billing_address_form );
			$view->assign( 'shipping_address_form', $shipping_address_form );
			$view->assign( 'orderSummary', $html );
			$view->assign( 'progress', $progress );
			//$view->assign( 'default_billing_address', $default_billing_address );
			//$view->assign( 'default_shipping_address', $default_shipping_address );

			JRequest::setVar('layout', 'guest');
		}
		// Already Logged in, a traditional checkout
		else
		{
			$order = &$this->_order;
			$order = $this->populateOrder(false);

			// now that the order object is set, get the orderSummary html
			$html = $this->getOrderSummary();

			// Get the current step
			$progress = $this->getProgress();

			JModel::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'models' );
			$model = JModel::getInstance( 'addresses', 'TiendaModel' );
			$model->setState("filter_userid", JFactory::getUser()->id);
			$model->setState("filter_deleted", 0);
			$addresses = $model->getList();

			$billingAddress = $order->getBillingAddress();
			$shippingAddress = $order->getShippingAddress();
			
			// get address forms
			$billing_address_form = $this->getAddressForm( $this->billing_input_prefix );
			$shipping_address_form = $this->getAddressForm( $this->shipping_input_prefix );

			// get the default shipping and billing addresses, if possible
			$default_billing_address = $this->getAddressHtml( @$billingAddress->address_id );
			$default_shipping_address = $this->getAddressHtml( @$shippingAddress->address_id );

			// get all the enabled shipping plugins
			JLoader::import( 'com_tienda.helpers.plugin', JPATH_ADMINISTRATOR.DS.'components' );
			$plugins = TiendaHelperPlugins::getPluginsWithEvent( 'onGetShippingPlugins' );
				
			// now display the entire checkout page
			$view = $this->getView( 'checkout', 'html' );
			$view->set( 'hidemenu', false);
			$view->assign( 'order', $order );
			$view->assign( 'addresses', $addresses );
			$view->assign( 'billing_address', $billingAddress);
			$view->assign( 'shipping_address', $shippingAddress );
			$view->assign( 'billing_address_form', $billing_address_form );
			$view->assign( 'shipping_address_form', $shipping_address_form );
			$view->assign( 'orderSummary', $html );
			$view->assign( 'progress', $progress );
			$view->assign( 'default_billing_address', $default_billing_address );
			$view->assign( 'default_shipping_address', $default_shipping_address );
			$view->assign( 'plugins', $plugins );

			JRequest::setVar('layout', 'default');
		}
		
		parent::display();
	}
	
	/**
	 * Populate the order object with items and addresses, and calculate the order Totals
	 * @param $guest	guest mode?
	 * @return $order 	the populated order
	 */
	function populateOrder($guest = false)
	{	
		$order = $this->_order;
		// set the currency
		$order->currency_id = TiendaConfig::getInstance()->get( 'default_currencyid', '1' ); // USD is default if no currency selected
		// set the shipping method
		$order->shipping_method_id = $this->defaultShippingMethod;
		
		if (!$guest)
		{
			// set the order's addresses based on the form inputs
			// set to user defaults
			JLoader::import( 'com_tienda.helpers.user', JPATH_ADMINISTRATOR.DS.'components' );
			$billingAddress = TiendaHelperUser::getPrimaryAddress( JFactory::getUser()->id );
			$shippingAddress = TiendaHelperUser::getPrimaryAddress( JFactory::getUser()->id, 'shipping' );
			$order->setAddress( $billingAddress, 'billing' );
			$order->setAddress( $shippingAddress, 'shipping' );			

		}
		
		// get the items and add them to the order
		JLoader::import( 'com_tienda.helpers.carts', JPATH_ADMINISTRATOR.DS.'components' );
		$items = TiendaHelperCarts::getProductsInfo();
		foreach ($items as $item)
		{
			$order->addItem( $item );
		}
		
		// get the order totals
		$order->calculateTotals();
		
		return $order;
	}

	/**
	 * Get the progress bar
	 */
	function getProgress()
	{
		$view = $this->getView( 'checkout', 'html' );
		$view->set( '_controller', 'checkout' );
		$view->set( '_view', 'checkout' );
		$view->set( '_doTask', true);
		$view->set( 'hidemenu', true);
		$view->assign( 'steps', $this->steps );
		$view->assign( 'current_step', $this->current_step );
		$view->setLayout( 'progress' );

		// Get and Set Model
		$model = $this->getModel('checkout');
		$view->setModel( $model, true );

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();

		return $html;
	}

	/**
	 * Prepares data for and returns the html of the order summary layout.
	 * This assumes that $this->_order has already had its properties set
	 *
	 * @return unknown_type
	 */
	function getOrderSummary()
	{
		// get the order object
		$order = &$this->_order; // a TableOrders object (see constructor)

		$model = $this->getModel('carts');
		$view = $this->getView( 'checkout', 'html' );
		$view->set( '_controller', 'checkout' );
		$view->set( '_view', 'checkout' );
		$view->set( '_doTask', true);
		$view->set( 'hidemenu', true);
		$view->setModel( $model, true );
		$view->assign( 'state', $model->getState() );
		$view->assign( 'order', $order );
		$view->assign( 'orderitems', $order->getItems() );
		$view->assign( 'shipping_total', $order->getShippingTotal() );
		$view->setLayout( 'cart' );

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();

		return $html;
	}

	/**
	 * (non-PHPdoc)
	 * @see tienda/site/TiendaController#validate()
	 */
	function validate()
	{
		$response = array();
		$response['msg'] = '';
		$response['error'] = '';

		JLoader::import( 'com_tienda.helpers._base', JPATH_ADMINISTRATOR.DS.'components' );
		$helper = TiendaHelperBase::getInstance();

		// get elements from post
		$elements = json_decode( preg_replace('/[\n\r]+/', '\n', JRequest::getVar( 'elements', '', 'post', 'string' ) ) );

		// Test if elements are empty
		// Return proper message to user
		if (empty($elements))
		{
			// do form validation
			// if it fails check, return message
			$response['error'] = '1';
			$response['msg'] = $helper->generateMessage(JText::_("Error while validating the parameters"));
			echo ( json_encode( $response ) );
			return;
		}

		// convert elements to array that can be binded
		JLoader::import( 'com_tienda.helpers._base', JPATH_ADMINISTRATOR.DS.'components' );
		$helper = TiendaHelperBase::getInstance();
		$submitted_values = $helper->elementsToArray( $elements );

		$step = (!empty($submitted_values['step'])) ? strtolower($submitted_values['step']) : '';
		switch ($step)
		{
			case "selectshipping":
				// Validate the email address if it is a guest checkout!
				if((TiendaConfig::getInstance()->get('guest_checkout_enabled', '1')) && !empty($submitted_values['guest']) )
				{
					jimport('joomla.mail.helper');
					if(!JMailHelper::isEmailAddress($submitted_values['email_address'])){
						$response['msg'] = $helper->generateMessage( JText::_('Please insert a correct email address') );
						$response['error'] = '1';
						echo ( json_encode( $response ) );
						return;
					}
					JLoader::import( 'com_tienda.helpers.user', JPATH_ADMINISTRATOR.DS.'components' );
					if(TiendaHelperUser::emailExists($submitted_values['email_address'])){
						$response['msg'] = $helper->generateMessage( JText::_('This email address is already registered! Login to checkout as a user!') );
						$response['error'] = '1';
						echo ( json_encode( $response ) );
						return;
					}
				}
				$this->validateSelectShipping( $submitted_values );
				break;
			case "selectpayment":
				$this->validateSelectPayment( $submitted_values );
				break;
			default:
				$response['error'] = '1';
				$response['msg'] = $helper->generateMessage(JText::_("INVALID STEP IN CHECKOUT PROCESS"));
				echo ( json_encode( $response ) );
				break;
		}
		return;
	}

	/**
	 * Validates the select shipping method form
	 */
	function validateSelectShipping( $submitted_values )
	{
		$response = array();
		$response['msg'] = '';
		$response['error'] = '';

		JLoader::import( 'com_tienda.helpers._base', JPATH_ADMINISTRATOR.DS.'components' );
		$helper = TiendaHelperBase::getInstance();

		// fail if no shipping method selected
		if (empty($submitted_values['_checked']['shipping_method_id']))
		{
			$response['msg'] = $helper->generateMessage( JText::_('Please select shipping method') );
			$response['error'] = '1';
			echo ( json_encode( $response ) );
			return;
		}

		// fail if billing address is invalid
		if (!$this->validateAddress( $submitted_values, $this->billing_input_prefix , @$submitted_values['billing_address_id'] ))
		{
			$response['msg'] = $helper->generateMessage( JText::_( "BILLING ADDRESS ERROR" )." :: ".$this->getError() );
			$response['error'] = '1';
			echo ( json_encode( $response ) );
			return;
		}

		// fail if shipping address is invalid
		// if we're checking shipping and the sameasbilling is checked, then this is good
		$sameasbilling = (!empty($submitted_values['_checked']['sameasbilling']));
		if (!$sameasbilling && !$this->validateAddress( $submitted_values, $this->shipping_input_prefix, $submitted_values['shipping_address_id'] ))
		{
			$response['msg'] = $helper->generateMessage( JText::_( "SHIPPING ADDRESS ERROR" )." :: ".$this->getError() );
			$response['error'] = '1';
			echo ( json_encode( $response ) );
			return;
		}

		echo ( json_encode( $response ) );
		return;
		 
	}

	/**
	 * Validates the select payment form
	 */
	function validateSelectPayment( $submitted_values )
	{
		$response = array();
		$response['msg'] = '';
		$response['error'] = '';

		JLoader::import( 'com_tienda.helpers._base', JPATH_ADMINISTRATOR.DS.'components' );
		$helper = TiendaHelperBase::getInstance();

		// fail if no payment method selected
		if (empty($submitted_values['_checked']['payment_plugin']) )
		{
			$response['msg'] = $helper->generateMessage(JText::_('Please select payment method'));
			$response['error'] = '1';
		}
		    else
		{
			// Validate the results of the payment plugin
			$results = array();
			$dispatcher =& JDispatcher::getInstance();
			$results = $dispatcher->trigger( "onGetPaymentFormVerify", array( $submitted_values['_checked']['payment_plugin'], $submitted_values) );

			for ($i=0; $i<count($results); $i++)
			{
				$result = $results[$i];
				if (!empty($result->error))
				{
					$response['msg'] = $helper->generateMessage( $result->message );
					$response['error'] = '1';
				}
				else
				{
					// if here, all is OK
					$response['error'] = '0';
				}
			}
		}

		echo ( json_encode( $response ) );
		return;
	}

	/**
	 * Validates a submitted address inputs
	 */
	function validateAddress( $values, $prefix, $address_id )
	{
		$model = $this->getModel( 'Addresses', 'TiendaModel' );
		$table = $model->getTable();
		$addressArray = $this->getAddress( $address_id, $prefix, $values );

		// IS Guest Checkout?
		$user_id = JFactory::getUser()->id;
		if(TiendaConfig::getInstance()->get('guest_checkout_enabled', '1') && $user_id == 0)
		$addressArray['user_id'] = 9999; // Fake id for the checkout process
			
		$table->bind( $addressArray );
		if (!$table->check())
		{
			$this->setError( $table->getError() );
			return false;
		}
		return true;
	}

	/**
	 * Returns a selectlist of zones
	 * Called via Ajax
	 *
	 * @return unknown_type
	 */
	function getZones()
	{
		JLoader::import( 'com_tienda.library.select', JPATH_ADMINISTRATOR.DS.'components' );
		$html = '';
		$text = '';
			
		$country_id = JRequest::getVar('country_id');
		$prefix = JRequest::getVar('prefix');
		$html = TiendaSelect::zone( '', $prefix.'zone_id', $country_id );
			
		$response = array();
		$response['msg'] = $html;
		$response['error'] = '';

		// encode and echo (need to echo to send back to browser)
		echo ( json_encode($response) );

		return;
	}

	/**
	 * Prepare the review tmpl
	 *
	 * @return unknown_type
	 */
	function selectpayment()
	{
		$this->current_step = 1;
			
		// get the posted values
		$values = JRequest::get('post');

		// get the order object so we can populate it
		$order = &$this->_order; // a TableOrders object (see constructor)

		$user_id = JFactory::getUser()->id;
		// Guest Checkout
		$guest = false;
		if($user_id == 0 && TiendaConfig::getInstance()->get('guest_checkout_enabled', '1')){
			$email_address = $values['email_address'];
			$guest = true;
			$user_id = 9999;
		}

		$order->bind( $values );
		$order->user_id = $user_id;
		$order->shipping_method_id = $values['shipping_method_id'];
		$this->setAddresses( $values );

		// get the items and add them to the order
		JLoader::import( 'com_tienda.helpers.carts', JPATH_ADMINISTRATOR.DS.'components' );
		$items = TiendaHelperCarts::getProductsInfo();
		foreach ($items as $item)
		{
			$order->addItem( $item );
		}

		// get the order totals
		$order->calculateTotals();

		// now that the order object is set, get the orderSummary html
		$html = $this->getOrderSummary();
			
		$values = JRequest::get('post');

		//Set key information from post
		$billing_address_id     = (!empty($values['billing_address_id'])) ? $values['billing_address_id'] : 0;
		$shipping_address_id    = (!empty($values['shipping_address_id'])) ? $values['shipping_address_id'] : 0;
		$same_as_billing        = (!empty($values['sameasbilling'])) ? true : false;
		$shipping_method_id     = $values['shipping_method_id'];
		$customerNote           = $values['customer_note'];

		$progress = $this->getProgress();

		//Set display
		$view = $this->getView( 'checkout', 'html' );
		$view->setLayout('selectpayment');
		$view->set( '_doTask', true);

		//Get and Set Model
		$model = $this->getModel('checkout');
		$view->setModel( $model, true );

		//Get Addresses
		//$shippingAddressArray = $this->retrieveAddressIntoArray($shipping_address_id);
		//$billingAddressArray = $this->retrieveAddressIntoArray($billing_address_id);
		$billingAddressArray = $this->_billingAddressArray;
		$shippingAddressArray = $this->_shippingAddressArray;

		// save the addresses
		JTable::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'tables' );
		$billingAddress = JTable::getInstance('Addresses', 'TiendaTable');
		$shippingAddress = JTable::getInstance('Addresses', 'TiendaTable');

		// set the order billing address
		$billingAddress->load( $billing_address_id );
		$billingAddress->bind( $billingAddressArray );
		$billingAddress->user_id = $user_id;
		$billingAddress->save();

		$values['billing_address_id'] = $billingAddress->address_id;
		if ($same_as_billing)
		{
			$shipping_address_id = $values['billing_address_id'];
		}

		// set the order shipping address
		if (!$same_as_billing)
		{
			$shippingAddress->load( $shipping_address_id );
			$shippingAddress->bind( $shippingAddressArray );
			$shippingAddress->user_id = $user_id;
			$shippingAddress->save();
			$shipping_address_id = $shippingAddress->address_id;
		}
		$values['shipping_address_id'] = $shipping_address_id;

		$shippingMethodName = $this->getShippingMethod($shipping_method_id);

		//Assign Addresses and Shippping Method to view
		$view->assign('shipping_method_name',$shippingMethodName);
		$view->assign('shipping_method_id',$shipping_method_id);
		$view->assign('shipping_info',$shippingAddressArray);
		$view->assign('billing_info',$billingAddressArray);
		$view->assign('customer_note', $customerNote);
		$view->assign('values', $values);
		$view->assign('progress', $progress);
		$view->assign('guest', $guest);

		$view->set( 'hidemenu', false);
		$view->assign( 'order', $order );
		$view->assign( 'orderSummary', $html );

		// get all the enabled payment plugins
		JLoader::import( 'com_tienda.helpers.plugin', JPATH_ADMINISTRATOR.DS.'components' );
		$plugins = TiendaHelperPlugins::getPluginsWithEvent( 'onGetPaymentPlugins' );
		$view->assign('plugins', $plugins);

		$view->display();
		$this->footer();
	}

	/**
	 * Fires selected tienda payment plugin and captures output
	 * Returns via json_encode
	 *
	 * @return unknown_type
	 */
	function getPaymentForm()
	{
		// Use AJAX to show plugins that are available
		JLoader::import( 'com_tienda.library.json', JPATH_ADMINISTRATOR.DS.'components' );
		$values = JRequest::get('post');
		$html = '';
		$text = "";
		$user = JFactory::getUser();
		$element = JRequest::getVar( 'payment_element' );
		$results = array();
		$dispatcher    =& JDispatcher::getInstance();
		$results = $dispatcher->trigger( "onGetPaymentForm", array( $element, $values ) );

		for ($i=0; $i<count($results); $i++)
		{
			$result = $results[$i];
			$text .= $result;
		}

		$html = $text;

		// set response array
		$response = array();
		$response['msg'] = $html;

		// encode and echo (need to echo to send back to browser)
		echo json_encode($response);

		return;
	}
	
	/**
	 * Fires selected tienda shipping plugin and captures output
	 * Returns via json_encode
	 *
	 * @return unknown_type
	 */
	function getShippingRates()
	{
		// Use AJAX to show plugins that are available
		JLoader::import( 'com_tienda.library.json', JPATH_ADMINISTRATOR.DS.'components' );
		$guest = JRequest::getVar( 'guest', '0');
		if($guest == '1' && TiendaConfig::getInstance()->get('guest_checkout_enabled'))
			$guest = true;
		else
			$guest = false;
		
		$values = &$this->populateOrder($guest);
		$text = "";
		$user = JFactory::getUser();
		$element = JRequest::getVar( 'shipping_element' );
		$results = array();
		$dispatcher    =& JDispatcher::getInstance();
		$results = $dispatcher->trigger( "onGetShippingRates", array( $element, $values ) );

		for ($i=0; $i<count($results); $i++)
		{
			$text .= $results[$i];
		}

		// set response array
		$response = array();
		$response['msg'] = $text;

		// encode and echo (need to echo to send back to browser)
		echo json_encode($response);

		return;
	}

	/**
	 *
	 * @param $values
	 * @return unknown_type
	 */
	function setAddresses( $values )
	{
		$order = $this->_order; // a TableOrders object (see constructor)

		// Get the currency from the configuration
		$currency_id			= TiendaConfig::getInstance()->get( 'default_currencyid', '1' ); // USD is default if no currency selected
		$billing_address_id     = (!empty($values['billing_address_id'])) ? $values['billing_address_id'] : 0;
		$shipping_address_id    = (!empty($values['shipping_address_id'])) ? $values['shipping_address_id'] : 0;
		$shipping_method_id     = $values['shipping_method_id'];
		$same_as_billing        = (!empty($values['sameasbilling'])) ? true : false;
		$user_id                = JFactory::getUser()->id;
		$billing_input_prefix   = $this->billing_input_prefix;
		$shipping_input_prefix  = $this->shipping_input_prefix;

		// Guest checkout
		if($user_id == 0 && TiendaConfig::getInstance()->get('guest_checkout_enabled', '1')){
			$user_id = 9999;
		}

		$billing_zone_id = 0;
		$billingAddressArray = $this->getAddress( $billing_address_id, $billing_input_prefix, $values );
		if (array_key_exists('zone_id', $billingAddressArray))
		{
			$billing_zone_id = $billingAddressArray['zone_id'];
		}

		//SHIPPING ADDRESS: get shipping address from dropdown or form (depending on selection)
		$shipping_zone_id = 0;
		if ($same_as_billing)
		{
			$shippingAddressArray = $billingAddressArray;
		}
		else
		{
			$shippingAddressArray = $this->getAddress($shipping_address_id, $shipping_input_prefix, $values);
		}

		if (array_key_exists('zone_id', $shippingAddressArray))
		{
			$shipping_zone_id = $shippingAddressArray['zone_id'];
		}

		// keep the array for binding during the save process
		$this->_orderinfoBillingAddressArray = $this->filterArrayUsingPrefix($billingAddressArray, '', 'billing_', true);
		$this->_orderinfoShippingAddressArray = $this->filterArrayUsingPrefix($shippingAddressArray, '', 'shipping_', true);
		$this->_billingAddressArray = $billingAddressArray;
		$this->_shippingAddressArray = $shippingAddressArray;

		JTable::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'tables' );
		$billingAddress = JTable::getInstance('Addresses', 'TiendaTable');
		$shippingAddress = JTable::getInstance('Addresses', 'TiendaTable');

		// set the order billing address
		$billingAddress->bind( $billingAddressArray );
		$billingAddress->user_id = $user_id;
		$order->setAddress( $billingAddress, 'billing' );

		// set the order shipping address
		$shippingAddress->bind( $shippingAddressArray );
		$shippingAddress->user_id = $user_id;
		$order->setAddress( $shippingAddress, 'shipping' );

		return;
	}

	/**
	 *
	 * @param unknown_type $address_id
	 * @param unknown_type $input_prefix
	 * @param unknown_type $form_input_array
	 * @return unknown_type
	 */
	function getAddress( $address_id, $input_prefix, $form_input_array )
	{
		$addressArray = array();
		if (!empty($address_id))
		{
			$addressArray = $this->retrieveAddressIntoArray($address_id);
		}
		else
		{
			$addressArray = $this->filterArrayUsingPrefix($form_input_array, $input_prefix, '', false );
			// set the zone name
			$zone = JTable::getInstance('Zones', 'TiendaTable');
			$zone->load( $addressArray['zone_id'] );
			$addressArray['zone_name'] = $zone->zone_name;
			// set the country name
			$country = JTable::getInstance('Countries', 'TiendaTable');
			$country->load( $addressArray['country_id'] );
			$addressArray['country_name'] = $country->country_name;
		}
		return $addressArray;
	}

	/**
	 * Gets an address formatted for display
	 *
	 * @param int $address_id
	 * @return string html
	 */
	function getAddressHtml( $address_id )
	{
		$html = '';
		$model = JModel::getInstance( 'Addresses', 'TiendaModel' );
		$model->setId( $address_id );
		if ($item = $model->getItem())
		{
			$view   = $this->getView( 'addresses', 'html' );
			$view->set( '_controller', 'addresses' );
			$view->set( '_view', 'addresses' );
			$view->set( '_doTask', true);
			$view->set( 'hidemenu', true);
			$view->setModel( $model, true );
			$view->setLayout( 'view_inner' );
			$view->set('row', $item);

			ob_start();
			$view->display();
			$html = ob_get_contents();
			ob_end_clean();
		}

		return $html;
	}

	/**
	 * Gets an address form for display
	 *
	 * @param string $prefix
	 * @return string html
	 */
	function getAddressForm( $prefix, $guest = false )
	{
		$html = '';
		$model = $this->getModel( 'Addresses', 'TiendaModel' );
		$view   = $this->getView( 'checkout', 'html' );
		$view->set( '_controller', 'checkout' );
		$view->set( '_view', 'checkout' );
		$view->set( '_doTask', true);
		$view->set( 'hidemenu', true);
		$view->set( 'form_prefix', $prefix );
		$view->set( 'guest', $guest );
		$view->setModel( $model, true );
		$view->setLayout( 'form_address' );

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();

		return $html;
	}

	/**
	 *
	 * @param unknown_type $oldArray
	 * @param unknown_type $old_prefix
	 * @param unknown_type $new_prefix
	 * @param unknown_type $append
	 * @return unknown_type
	 */
	function filterArrayUsingPrefix( $oldArray, $old_prefix, $new_prefix, $append )
	{
		// create array with input form keys and values
		$address_input = array();

		foreach ($oldArray as $key => $value)
		{
			if (($append) || (strpos($key, $old_prefix) !== false))
			{
				$new_key = '';
				if ($append){$new_key = $new_prefix.$key;}
				else{
					$new_key = str_replace($old_prefix, $new_prefix, $key);
				}
				if (strlen($new_key)>0){
					$address_input[$new_key] = $value;
				}
			}
		}
		return $address_input;
	}

	/**
	 *
	 * @param $address_id
	 * @return unknown_type
	 */
	function retrieveAddressIntoArray( $address_id )
	{
		$model = JModel::getInstance( 'Addresses', 'TiendaModel' );
		$model->setId($address_id);
		$item = $model->getItem();
		return get_object_vars( $item );
	}

	/**
	 * Gets the selected shipping method
	 *
	 * @param $shipping_method_id
	 * @return unknown_type
	 */
	function getShippingMethod($shipping_method_id)
	{
		$model = JModel::getInstance( 'ShippingMethods', 'TiendaModel' );
		$model->setId($shipping_method_id);
		$item = $model->getItem();
		return $item->shipping_method_name;
	}

	/**
	 * Sets the selected shipping method
	 *
	 * @return unknown_type
	 */
	function setShippingMethod()
	{
		$elements = json_decode( preg_replace('/[\n\r]+/', '\n', JRequest::getVar( 'elements', '', 'post', 'string' ) ) );

		// convert elements to array that can be binded
		JLoader::import( 'com_tienda.helpers._base', JPATH_ADMINISTRATOR.DS.'components' );
		$helper = TiendaHelperBase::getInstance();
		$values = $helper->elementsToArray( $elements );

		// Assign the shipping method to the order object
		$shipping_method_id = @$values['_checked']['shipping_method_id'];

		// get the order object so we can populate it
		$order = &$this->_order; // a TableOrders object (see constructor)

		// bind what you can from the post
		$order->bind( $values );

		// set the currency
		$order->currency_id = TiendaConfig::getInstance()->get( 'default_currencyid', '1' ); // USD is default if no currency selected

		// set the shipping method
		$order->shipping_method_id = $shipping_method_id;

		// set the addresses
		$this->setAddresses( $values );

		// get the items and add them to the order
		JLoader::import( 'com_tienda.helpers.carts', JPATH_ADMINISTRATOR.DS.'components' );
		$items = TiendaHelperCarts::getProductsInfo();
		foreach ($items as $item)
		{
			$order->addItem( $item );
		}

		// get the order totals
		$order->calculateTotals();

		// now get the summary
		$html = $this->getOrderSummary();

		$response = array();
		$response['msg'] = $html;
		$response['error'] = '';

		// encode and echo (need to echo to send back to browser)
		echo json_encode($response);

		return;
	}

	/**
	 * This method occurs before payment is attempted
	 * and fires the onPrePayment plugin event
	 *
	 * @return unknown_type
	 */
	function preparePayment()
	{
		$this->current_step = 2;
		// verify that form was submitted by checking token
		JRequest::checkToken() or jexit( 'TiendaControllerCheckout::preparePayment - Invalid Token' );
			
		// 1. save the order to the table with a 'pre-payment' status

		// Get post values
		$values = JRequest::get('post');
		$user = JFactory::getUser();

		// Guest Checkout: Silent Registration!
		if (TiendaConfig::getInstance()->get('guest_checkout_enabled', '1') && $values['guest'] == '1')
		{
			JLoader::import( 'com_tienda.helpers.user', JPATH_ADMINISTRATOR.DS.'components' );
			$userHelper = TiendaHelperUser::getInstance('User', 'TiendaHelper');
				
			if ($userHelper->emailExists($values['email_address']))
			{
				// TODO user already existing!
				
			} 
                else
			{
				// by omitting password, a random Password will be used
				$details = array(
					'email' => $values['email_address'],
					'name' => $values['email_address'],
					'username' => $values['email_address']			
				);

				$msg = $this->getError();

				$user = $userHelper->createNewUser($details, $msg);

				if (empty($user->id))
				{
					// TODO what to do if creating new user failed?
				}

				$userHelper->login( 
				    array('username' => $user->username, 'password' => $user->password_clear) 
				);
			}
		}

		// Save the order with a pending status
		if (!$this->saveOrder($values))
		{
			// Output error message and halt
			JError::raiseNotice( 'Error Saving Order', $this->getError() );
			return false;
		}

		// Get Order Object
		$order = $this->_order;

		// Update the addresses' user id!
		$shippingAddress = $order->getShippingAddress();
		$billingAddress = $order->getBillingAddress();

		$shippingAddress->user_id = $user->id;
		$billingAddress->user_id = $user->id;

		if (!$shippingAddress->save())
		{
			// Output error message and halt
			JError::raiseNotice( 'Error Updating the Shipping Address', $shippingAddress->getError() );
			return false;
		}
		
		if (!$billingAddress->save())
		{
			// Output error message and halt
			JError::raiseNotice( 'Error Updating the Billing Address', $billingAddress->getError() );
			return false;
		}

		// Save an orderpayment with an Incomplete status
		JTable::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'tables' );
		$orderpayment = JTable::getInstance('OrderPayments', 'TiendaTable');
		$orderpayment->order_id = $order->order_id;
		$orderpayment->orderpayment_type = $values['payment_plugin']; // this is the payment plugin selected
		$orderpayment->transaction_status = JText::_( "Incomplete" ); // payment plugin updates this field onPostPayment
		$orderpayment->orderpayment_amount = $order->order_total; // this is the expected payment amount.  payment plugin should verify actual payment amount against expected payment amount
		if (!$orderpayment->save())
		{
			// Output error message and halt
			JError::raiseNotice( 'Error Saving Pending Payment Record', $orderpayment->getError() );
			return false;
		}

		// send the order_id and orderpayment_id to the payment plugin so it knows which DB record to update upon successful payment
		$values["order_id"]             = $order->order_id;
		$values["orderinfo"]            = $order->orderinfo;
		$values["orderpayment_id"]      = $orderpayment->orderpayment_id;
		$values["orderpayment_amount"]  = $orderpayment->orderpayment_amount;

		// IMPORTANT: Store the order_id in the user's session for the postPayment "View Invoice" link
		$mainframe =& JFactory::getApplication();
		$mainframe->setUserState( 'tienda.order_id', $order->order_id );

		// 2. perform payment process
		// this is the onPrePayment plugin event
		// in the case of offsite payment plugins (like Paypal), they will display an order summary (perhaps with ****** for CC number)
		// with a button that submits a form to the external site (button: "confirm order" or Paypal, MB, Alertpay, whatever)
		// the return url will point to the method that fires the onPostPayment plugin event:
		// target: index.php?option=com_tienda&view=checkout&task=confirmPayment&orderpayment_type=xxxxxx
		// in the case of onsite payment plugins, they will display an order summary (perhaps with ****** for CC number)
		// with a button that submits a form to the method that fires the onPostPayment plugin event ("confirm order")
		// target: index.php?option=com_tienda&view=checkout&task=confirmPayment&orderpayment_type=xxxxxx
		// onPostPayment, payment plugin to update order status with payment status

		$dispatcher    =& JDispatcher::getInstance();
		$results = $dispatcher->trigger( "onPrePayment", array( $values['payment_plugin'], $values ) );

		// Display whatever comes back from Payment Plugin for the onPrePayment
		$html = "";
		for ($i=0; $i<count($results); $i++)
		{
			$html .= $results[$i];
		}

		// get the order summary
		$summary = $this->getOrderSummary();

		// Get Addresses
		$shipping_address = $order->getShippingAddress();
		$billing_address = $order->getBillingAddress();
		$shippingAddressArray = $this->retrieveAddressIntoArray($shipping_address->id);
		$billingAddressArray = $this->retrieveAddressIntoArray($billing_address->id);
			
		$shippingMethodName = $this->getShippingMethod($order->shipping_method_id);

		$progress = $this->getProgress();

		// Set display
		$view = $this->getView( 'checkout', 'html' );
		$view->setLayout('prepayment');
		$view->set( '_doTask', true);
		$view->assign('order', $order);
		$view->assign('plugin_html', $html);
		$view->assign('progress', $progress);
		$view->assign('orderSummary', $summary);
		$view->assign('shipping_info', $shippingAddressArray);
		$view->assign('billing_info', $billingAddressArray);
		$view->assign('shipping_method_name',$shippingMethodName);

		// Get and Set Model
		$model = $this->getModel('checkout');
		$view->setModel( $model, true );
		$view->display();

		return;
	}

	/**
	 * This method occurs after payment is attempted,
	 * and fires the onPostPayment plugin event
	 *
	 * @return unknown_type
	 */
	function confirmPayment()
	{
		$this->current_step = 3;
		$orderpayment_type = JRequest::getVar('orderpayment_type');

		// Get post values
		$values = JRequest::get('post');

		$dispatcher =& JDispatcher::getInstance();
		$results = $dispatcher->trigger( "onPostPayment", array( $orderpayment_type, $values ) );

		// Display whatever comes back from Payment Plugin for the onPrePayment
		$html = "";
		for ($i=0; $i<count($results); $i++)
		{
			$html .= $results[$i];
		}

		// get the order_id from the session set by the prePayment
		$mainframe =& JFactory::getApplication();
		$order_id = $mainframe->getUserState( 'tienda.order_id' );
		$order_link = 'index.php?option=com_tienda&view=orders&task=view&id='.$order_id;

		$progress = $this->getProgress();

		// Set display
		$view = $this->getView( 'checkout', 'html' );
		$view->setLayout('postpayment');
		$view->set( '_doTask', true);
		$view->assign('order_link', $order_link );
		$view->assign('progress', $progress );
		$view->assign('plugin_html', $html);
			
		// Get and Set Model
		$model = $this->getModel('checkout');
		$view->setModel( $model, true );
		$view->display();

		return;
	}

	/**
	 * Saves the order to the database
	 *
	 * @param $values
	 * @return unknown_type
	 */
	function saveOrder($values)
	{
		$error = false;
		$order =& $this->_order; // a TableOrders object (see constructor)
		$order->bind( $values );
		$order->user_id = JFactory::getUser()->id;

		$order->ip_address = $_SERVER['REMOTE_ADDR'];
		$order->shipping_method_id = $values['shipping_method_id'];
		$this->setAddresses( $values );

		// Store the text verion of the currency for order integrity
		JLoader::import( 'com_tienda.helpers.order', JPATH_ADMINISTRATOR.DS.'components' );
		$order->order_currency = TiendaHelperOrder::currencyToParameters($order->currency_id);

		//get the items and add them to the order
		JLoader::import( 'com_tienda.helpers.carts', JPATH_ADMINISTRATOR.DS.'components' );
		$reviewitems = TiendaHelperCarts::getProductsInfo();

		foreach ($reviewitems as $reviewitem)
		{
			$order->addItem( $reviewitem );
		}
		$order->order_state_id = $this->initial_order_state;
		$order->calculateTotals();
		$order->getShippingTotal();
		$order->getOrderNumber();

		$model  = JModel::getInstance('Orders', 'TiendaModel');
		//TODO: Do Something with Payment Infomation
		if ( $order->save() )
		{
			$model->setId( $order->order_id );

			// save the order items
			if (!$this->saveOrderItems())
			{
				// TODO What to do if saving order items fails?
				$error = true;
			}
			
			// save the order vendors
			if (!$this->saveOrderVendors())
			{
				// TODO What to do if saving order vendors fails?
				$error = true;
			}

			// save the order info
			if (!$this->saveOrderInfo())
			{
				// TODO What to do if saving order info fails?
				$error = true;
			}

			// save the order history
			if (!$this->saveOrderHistory())
			{
				// TODO What to do if saving order history fails?
				$error = true;
			}
		}

		if ($error)
		{
			return false;
		}
		return true;
	}

	/**
	 * Saves each individual item in the order to the DB
	 *
	 * @return unknown_type
	 */
	function saveOrderItems()
	{
		JTable::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'tables' );
		$order =& $this->_order;
		$items = $order->getItems();
			
		if (empty($items) || !is_array($items))
		{
			$this->setError( "saveOrderItems:: ".JText::_( "Items Array is Invalid" ) );
			return false;
		}
			
		$error = false;
		$errorMsg = "";
		foreach ($items as $item)
		{
			$item->order_id = $order->order_id;

			if (!$item->save())
			{
				// track error
				$error = true;
				$errorMsg .= $item->getError();
			}
			else
			{
				// Save the attributes also
				if (!empty($item->orderitem_attributes))
				{
					$attributes = explode(',', $item->orderitem_attributes);
					foreach (@$attributes as $attribute)
					{
						unset($productattribute);
						unset($orderitemattribute);
						$productattribute = JTable::getInstance('ProductAttributeOptions', 'TiendaTable');
						$productattribute->load( $attribute );
						$orderitemattribute = JTable::getInstance('OrderItemAttributes', 'TiendaTable');
						$orderitemattribute->orderitem_id = $item->orderitem_id;
						$orderitemattribute->productattributeoption_id = $productattribute->productattributeoption_id;
						$orderitemattribute->orderitemattribute_name = $productattribute->productattributeoption_name;
						$orderitemattribute->orderitemattribute_price = $productattribute->productattributeoption_price;
						$orderitemattribute->orderitemattribute_prefix = $productattribute->productattributeoption_prefix;
						if (!$orderitemattribute->save())
						{
							// track error
							$error = true;
							$errorMsg .= $orderitemattribute->getError();
						}
					}
				}
			}
		}

		if ($error)
		{
			$this->setError( $errorMsg );
			return false;
		}
		return true;
	}

	/**
	 * Saves the order info to the DB
	 * @return unknown_type
	 */
	function saveOrderInfo()
	{
		$order =& $this->_order;
			
		JTable::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'tables' );
		$row = JTable::getInstance('OrderInfo', 'TiendaTable');
		$row->order_id = $order->order_id;
		$row->user_email = JFactory::getUser()->get('email');
		$row->bind( $this->_orderinfoBillingAddressArray );
		$row->bind( $this->_orderinfoShippingAddressArray );
			
		if (!$row->save())
		{
			$this->setError( $row->getError() );
			return false;
		}

		$order->orderinfo = $row;
		return true;
	}

	/**
	 * Adds an order history record to the DB for this order
	 * @return unknown_type
	 */
	function saveOrderHistory()
	{
		$order =& $this->_order;
			
		JTable::addIncludePath( JPATH_ADMINISTRATOR.DS.'components'.DS.'com_tienda'.DS.'tables' );
		$row = JTable::getInstance('OrderHistory', 'TiendaTable');
		$row->order_id = $order->order_id;
		$row->order_state_id = $order->order_state_id;

		$row->notify_customer = '1';
		$row->comments = JRequest::getVar('order_history_comments', '', 'post');

		if (!$row->save())
		{
			$this->setError( $row->getError() );
			return false;
		}
		return true;
	}
	
	/**
	 * Saves each vendor related to this order to the DB
	 * @return unknown_type
	 */
	function saveOrderVendors()
	{
		$order =& $this->_order;
		$items = $order->getVendors();

		if (empty($items) || !is_array($items))
		{
			// No vendors other than store owner, so just skip this
			//$this->setError( "saveOrderVendors:: ".JText::_( "Vendors Array is Invalid" ) );
			//return false;
			return true;
		}

		$error = false;
		$errorMsg = "";
		foreach ($items as $item)
		{
			if (empty($item->vendor_id))
			{
				continue;
			}
			$item->order_id = $order->order_id;
			if (!$item->save())
			{
				// track error
				$error = true;
				$errorMsg .= $item->getError();
			}
		}

		if ($error)
		{
			$this->setError( $errorMsg );
			return false;
		}
		return true;
	}
}