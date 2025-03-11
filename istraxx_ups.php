<?php
/*
	 * @version $Id:
	 * @package VirtueMart
	 * @subpackage Plugins - shipment
	 * @author Valérie Isaksen, Patrick hohl
	 * @copyright Copyright (C) 2011 istraxx - All rights reserved.
	 * @license license.txt Proprietary License. This code belongs to alatak.net
	 * You are not allowed to distribute or sell this code. You bought only a license to use it for one virtuemart installation.
	 * You are not allowed to modify this code.
	 * STEP 1: register here: https://www.ups.com/upsdeveloperkit?loc=en_US&rt1
	 * https://www.ups.com/content/us/en/resources/sri/developer_instruct.html
	 * https://developerkitcommunity.ups.com/index.php/Main_Page
	 * */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;

class plgVmShipmentIstraxx_UPS extends vmPSPlugin
{
	public static $_this = false;
	private $_ups_id = '';
	private $_ups_name = '';
	protected $ups_rate = '';
	private $_ups_length = 0;
	private $_ups_width = 0;
	private $_ups_height = 0;
	private $_ups_packageCount = 0;
	private $_ups_rate_to_check = false;
	// var $from_zip = 0;
	// var $to_zip = 0;
	// var $shipping_pounds = '';
	// var $shipping_ounces = '';
	var $is_domestic;
	var $methods;

	/*
			  protected $serviceCodes = array(
			  '01' => 'UPS Next Day Air', '02' => 'UPS Second Day Air', '03' => 'UPS Ground',
			  '07' => 'UPS Worldwide Express', '08' => 'UPS Worldwide Expedited', '11' => 'UPS Standard',
			  '12' => 'UPS Three-Day Select', '13' => 'UPS Next Day Air Saver',
			  '14' => 'UPS Next Day Air Early AM', '54' => 'UPS Worldwide Express Plus',
			  '59' => 'UPS Second Day Air AM', '65' => 'UPS Saver'
			  );
			 */

	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$this->_loggable   = true;
		$this->_tablepkey  = 'id';
		$this->_tableId    = 'id';
		$this->tableFields = array_keys($this->getTableSQLFields());
		$varsToPush        = $this->getVarsToPush();

		$this->addVarsToPushCore($varsToPush, 0);

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
		//        $this->setConvertable(array('min_amount','max_amount','shipment_cost','package_fee','free_shipment'));
	}

	// TODO Add field for package tracking ?
	function getTableSQLFields()
	{
		$SQLfields = array(
			'id'                           => 'int(1) unsigned NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'          => 'int(11) UNSIGNED',
			'order_number'                 => 'char(64)',
			'virtuemart_shipmentmethod_id' => 'mediumint(1) UNSIGNED',
			'shipment_name'                => 'varchar(5000)',
			'weight'                       => 'mediumint(1)',
			'weight_unit'                  => 'char(3) DEFAULT \'KG\' ',
			'shipment_cost'                => 'decimal(10,2)',
			'shipment_currency'            => 'char(5)',
			'tax_id'                       => 'smallint(1)'
		);

		return $SQLfields;
	}

	public function plgVmDisplayListFEShipment(VirtueMartCart $cart, $selected, &$htmlIn)
	{
		if ($this->getPluginMethods($cart->vendorId) === 0)
		{
			if (empty($this->_name))
			{
				$app = JFactory::getApplication();
				$app->enqueueMessage(JText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));

				return false;
			}
			else
			{
				return false;
			}
		}

		$ups_session_rates = array();
		foreach ($this->methods as $method)
		{
			$responses = $this->_getUPSrates($cart, $method);
			if ($responses)
			{
				$ups_rates = array();
				foreach ($responses as $response)
				{
					$idx                                         = $response['Service']['Code'];
					$ups_rates[$idx]['id']                       = $response['Service']['Code'];
					$ups_rates[$idx]['code3']                    = $response['TotalCharges']['CurrencyCode'];
					$ups_rates[$idx]['rate']                     = $response['TotalCharges']['MonetaryValue'];
					$ups_rates[$idx]['GuaranteedDaysToDelivery'] = isset($response['GuaranteedDaysToDelivery']) ? $response['GuaranteedDaysToDelivery'] : 0;
					$ups_session_rates                           = array_merge($ups_session_rates, $ups_rates);
				}
				$this->_setUpsIntoSession($ups_rates);
				$this->_getResponseUPSHtml($method, $ups_rates, $selected, $htmlIn);

			}
			else
			{
				vmdebug('plgVmDisplayListFEShipment', 'no _getUPSrates found error');
			}
		}
		//$this->_setUpsIntoSession ($ups_session_rates);


		return true;
	}

	/**
	 * Get the total weight for the order, based on which the proper shipping rate
	 * can be selected.
	 *
	 * @param   object  $cart  Cart object
	 *
	 * @return float Total weight for the order
	 */
	protected function getOrderWeight(VirtueMartCart $cart, $to_weight_unit)
	{

		$weight         = 0;
		$to_weight_unit = substr($to_weight_unit, 0, 2);
		foreach ($cart->products as $product)
		{
			$weight += (ShopFunctions::convertWeigthUnit($product->product_weight, $product->product_weight_uom, $to_weight_unit) * $product->quantity);
		}

		return $weight;
	}

	/**
	 * This event is fired after the shipping method has been selected. It can be used to store
	 * additional shipper info in the cart.
	 *
	 * @param   object   $cart      Cart object
	 * @param   integer  $selected  ID of the shipper selected
	 *
	 * @return boolean True on succes, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 * @author ValУЉrie Isaksen
	 */
	public function plgVmOnSelectCheckShipment(VirtueMartCart &$cart)
	{

		if (!$this->selectedThisByMethodId($cart->virtuemart_shipmentmethod_id))
		{
			return null; // Another method was selected, do nothing
		}

		if (!$this->getVmPluginMethod($cart->virtuemart_shipmentmethod_id))
		{
			return null; // Another method was selected, do nothing
		}

		$this->_getUpsFromSession(); //get Rates
		// Only the code/ID rate is used for control and save;

		$app = JFactory::getApplication();

		$ups_rate = $app->getUserStateFromRequest("virtuemart_ups_rate", 'ups_rate', 0);
		if (!$ups_rate)
		{
			return null;
			/*
			if (isset($this->ups_rates)) {
				return TRUE;
			} else {
				return NULL;
			}
			*/
		}
		vmdebug('$ups_rate session', $ups_rate, $_REQUEST);
		// THe shipment must be recontrolled here before add to session !
		// Now we must get price from session !
		$no_price = array();

		if (!isset($this->ups_rates[$ups_rate]))
		{
			return null;
		}
		// write result in session
		$this->_setUpsIntoSession($this->ups_rates[$ups_rate], $cart);

		return true;
	}

	function _setUpsIntoSession($ups_rates = null)
	{

		vmdebug('_setUpsIntoSession session', $ups_rates);
		$session = JFactory::getSession();

		if ($ups_rates === null)
		{
			$ups_rates[$this->_ups_rate]['id']                       = $this->ups_rates[$this->ups_rate]['id'];
			$ups_rates[$this->_ups_rate]['code3']                    = $this->ups_rates[$this->ups_rate]['code3'];
			$ups_rates[$this->_ups_rate]['rate']                     = $this->ups_rates[$this->ups_rate]['rate'];
			$ups_rates[$this->_ups_rate]['GuaranteedDaysToDelivery'] = $this->ups_rates[$this->ups_rate]['GuaranteedDaysToDelivery'];
		}

		$session->set('ups_rates', json_encode($ups_rates), 'vm');
	}

	function _getUpsFromSession()
	{

		$session    = JFactory::getSession();
		$sessionUps = $session->get('ups_rates', 0, 'vm');

		if (!empty($sessionUps))
		{
			$sessionUpsData  = json_decode($sessionUps, true);
			$this->ups_rates = $sessionUpsData;
		}
		vmdebug('_getUpsFromSession session', $this->ups_rates);
	}

	/**
	 * This is for checking the input data of the shipment method within the checkout
	 *
	 * @author Valerie Cartan Isaksen
	 */
	function plgVmOnCheckoutCheckDataShipment(VirtueMartCart $cart)
	{

		if (!$this->selectedThisByMethodId($cart->virtuemart_shipmentmethod_id))
		{
			return null; // Another method was selected, do nothing
		}

		$this->_getUpsFromSession();
		if ($this->ups_rates)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/*
		 * @param $plugin plugin
		 */

	function renderPluginName($method)
	{

		$this->_getUpsFromSession();

		$app      = JFactory::getApplication();
		$ups_rate = $app->getUserStateFromRequest("virtuemart_ups_rate", 'ups_rate', 0);
		if (!isset($this->ups_rates['id']) and empty($this->ups_rates['id']))
		{
			$this->ups_rates = $this->ups_rates[$ups_rate];
		}
		else
		{

		}
		$vendorId          = 1;
		$vendorAddress     = "";
		$wharehouseAddress = "";
		if (!self::getVendorWhareHouseAddress($method, $vendorId, $vendorAddress, $wharehouseAddress))
		{
			vmdebug('renderPluginName getVendorWhareHouseAddress vendor invalid for this shipment method');

			return null; // vendor invalid for this shipment method
		}
		if ($wharehouseAddress->country_2_code == 'CA')
		{
			$prefix = 'VMSHIPMENT_ISTRAXX_UPS_SERVICES_CA_';
		}
		else
		{
			$prefix = 'VMSHIPMENT_ISTRAXX_UPS_SERVICES_';
		}
		vmdebug('UPS renderPluginName', $ups_rate, $this->ups_rates);

		return $this->renderByLayout('shipment_name', array(
				'shipment_name'  => $method->shipment_name,
				'shipment_desc'  => $method->shipment_desc,
				'shipment_logos' => $method->shipment_logos,
				'shipment_rate'  => $this->ups_rates,
				'service_prefix' => $prefix,
			)
		);
	}

	//TODO Find a way to stop the request on server if we have more then 1 result !!!
	function plgVmOnCheckAutomaticSelectedShipment(VirtueMartCart $cart, array $cart_prices, &$shipCounter)
	{

		if ($shipCounter > 1)
		{
			return 0;
		}

		if ($this->getPluginMethods($cart->vendorId) === 0)
		{
			return null;
		}
		// return;

		$nb                           = 0;
		$response                     = array();
		$virtuemart_shipmentmethod_id = 0;
		$idname                       = $this->_idName;
		foreach ($this->methods as $method)
		{
			$response    = $this->_getUPSrates($cart, $method);
			$nb          = count($response);
			$shipCounter = $shipCounter + $nb;
			if ($shipCounter > 1)
			{
				return 0;
			}
			$virtuemart_shipmentmethod_id = $method->$idname;
		}
		if ($nb == 1)
		{
			if (!empty ($response))
			{
				$autoresponse                          = reset($response);
				$ups_rates['id']                       = $autoresponse['Service']['Code'];
				$ups_rates['code3']                    = $autoresponse['TotalCharges']['CurrencyCode'];
				$ups_rates['rate']                     = $autoresponse['TotalCharges']['MonetaryValue'];
				$ups_rates['GuaranteedDaysToDelivery'] = isset($autoresponse['GuaranteedDaysToDelivery']) ? $autoresponse['GuaranteedDaysToDelivery'] : 0;

				$this->ups_rates = $autoresponse['Service']['Code'];
				$this->_setUpsIntoSession($ups_rates);
				$return = $virtuemart_shipmentmethod_id;
			}
			else
			{
				return 0;
			}
		}
		else
		{
			$return = 0;
		}

		return $return;
	}

	/*
		 * plgVmonSelectedCalculatePrice
		 * Calculate the price (value, tax_id) of the selected method
		 * It is called by the calculator
		 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
		 * @author Valerie Isaksen
		 * @cart: VirtueMartCart the current cart
		 * @cart_prices: array the new cart prices
		 * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
		 *
		 *
		 */

	public function plgVmOnSelectedCalculatePriceShipment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
	{

		if (!($method = $this->getVmPluginMethod($cart->virtuemart_shipmentmethod_id)))
		{
			return null; // Another method was selected, do nothing
		}

		if (!$this->selectedThisElement($method->shipment_element))
		{
			return false;
		}

		$this->_getUpsFromSession();
		$this->_ups_rate_to_check = true; // must recalculate ?
		// TODO CHeck do not work

		if (!$result = $this->checkConditions($cart, $method, $cart_prices))
		{
			vmdebug('NO valid shipment found', $result);

			return false;
		}

		$cart_prices['shipmentTax']   = 0;
		$cart_prices['shipmentValue'] = 0; //$ups_rate;
		$cart_prices_name             = $this->renderPluginName($method);
		//vmdebug('plgVmOnSelectedCalculatePriceShipment', $cart_prices, $cart_prices_name);
		$this->setCartPrices($cart, $cart_prices, $method);

		return true;
	}

	/*
		 * update the plugin cart_prices
		 *
		 * @author Valérie Isaksen
		 *
		 * @param $cart_prices: $cart_prices['salesPricePayment'] and $cart_prices['paymentTax'] updated. Displayed in the cart.
		 * @param $value :   fee
		 * @param $tax_id :  tax id
		 */

	function setCartPrices(VirtueMartCart $cart, &$cart_prices, $method, $progressive = true)
	{
		// $ups_rate = current($this->ups_rates); //print_r($cart_prices);
		if (!isset($this->ups_rates['rate']))
		{
			return;
		}

		$cart_prices['shipmentValue'] = $this->ups_rates['rate']; // the price has been converted In cart currency already
		$cart_prices['shipmentTax']   = 0;
		$taxrules                     = array();
		if (!empty($method->tax_id))
		{
			$db = JFactory::getDBO();
			$q  = 'SELECT * FROM #__virtuemart_calcs WHERE `virtuemart_calc_id`="' . $method->tax_id . '" ';
			$db->setQuery($q);
			$taxrules = $db->loadAssocList();
		}
		if (!class_exists('calculationHelper'))
		{
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'calculationh.php');
		}
		$calculator = calculationHelper::getInstance();
		if (count($taxrules) > 0)
		{
			$cart_prices['salesPriceShipment'] = round($calculator->executeCalculation($taxrules, $cart_prices['shipmentValue']), 4);
			$cart_prices['shipmentTax']        = round($cart_prices['salesPriceShipment'] - $cart_prices['shipmentValue'], 4);
		}
		else
		{
			$cart_prices['salesPriceShipment'] = $cart_prices['shipmentValue'];
			$cart_prices['shipmentTax']        = 0;
		}
	}


	/**
	 * This event is fired after the order has been stored; it gets the shipping method-
	 * specific data.
	 *
	 * @param   int     $order_id   The order_id being processed
	 * @param   object  $cart       the cart
	 * @param   array   $priceData  Price information for this order
	 *
	 * @return mixed Null when this method was not selected, otherwise true
	 * @author Valerie Isaksen
	 */
	function plgVmConfirmedOrder(VirtueMartCart $cart, $order)
	{

		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_shipmentmethod_id)))
		{
			return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->shipment_element))
		{
			return false;
		}
		$values['order_number']  = $order['details']['BT']->order_number;
		$values['shipment_id']   = $order['details']['BT']->virtuemart_shipmentmethod_id;
		$values['shipment_name'] = $this->renderPluginName($method);
		$values['weight']        = $this->getOrderWeight($cart, $method->weight_unit);
		$values['weight_unit']   = $method->weight_unit;
		// $this->_getUpsFromSession();
		$values['shipment_cost'] = $this->ups_rates['rate'];
		$values['tax_id']        = $method->tax_id;
		$this->storePSPluginInternalData($values);
		$session = JFactory::getSession();
		$session->clear('ups_rates', 'vm');

		return true;
	}

	/**
	 * This method is fired when showing the order details in the backend.
	 * It displays the shipper-specific data.
	 * NOTE, this plugin should NOT be used to display form fields, since it's called outside
	 * a form! Use plgVmOnUpdateOrderBE() instead!
	 *
	 * @param   integer  $virtuemart_order_id  The order ID
	 * @param   integer  $vendorId             Vendor ID
	 * @param   object   $_shipInfo            Object with the properties 'carrier' and 'name'
	 *
	 * @return mixed Null for shippers that aren't active, text (HTML) otherwise
	 * @author Valerie Isaksen
	 */
	public function plgVmOnShowOrderBEShipment($virtuemart_order_id, $virtuemart_shipmentmethod_id)
	{
		if (!($this->selectedThisByMethodId($virtuemart_shipmentmethod_id)))
		{
			return null;
		}
		$html = $this->getOrderShipmentHtml($virtuemart_order_id);

		return $html;
	}

	function getOrderShipmentHtml($virtuemart_order_id)
	{
		$db = JFactory::getDBO();
		$q  = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);
		if (!($shipinfo = $db->loadObject()))
		{
			return '';
		}

		if (!class_exists('CurrencyDisplay'))
		{
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}

		$currency   = CurrencyDisplay::getInstance();
		$tax        = ShopFunctions::getTaxByID($shipinfo->tax_id);
		$taxDisplay = is_array($tax) ? $tax['calc_value'] . ' ' . $tax['calc_value_mathop'] : $shipinfo->tax_id;
		$taxDisplay = ($taxDisplay == -1) ? JText::_('COM_VIRTUEMART_PRODUCT_TAX_NONE') : $taxDisplay;

		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('ISTRAXX_UPS_SHIPPING_NAME', $shipinfo->shipment_name);
		$html .= $this->getHtmlRowBE('ISTRAXX_UPS_WEIGHT', $shipinfo->weight . ' ' . ShopFunctions::renderWeightUnit($shipinfo->weight_unit));
		$html .= $this->getHtmlRowBE('ISTRAXX_UPS_COST', $currency->priceDisplay($shipinfo->shipment_cost, '', false));
		//$html .= $this->getHtmlRowBE('WEIGHT_COUNTRIES_PACKAGE_FEE', $currency->priceDisplay($shipinfo->shipment_package_fee, '', false));
		$html .= $this->getHtmlRowBE('ISTRAXX_UPS_TAX', $taxDisplay);
		$html .= '</table>' . "\n";

		return $html;
	}

	function _getShippingCost($method, VirtueMartCart $cart)
	{
		$value           = $this->getShippingValue($method, $cart->pricesUnformatted);
		$shipping_tax_id = $this->getShippingTaxId($method, $cart);
		$tax             = ShopFunctions::getTaxByID($shipping_tax_id);
		$taxDisplay      = is_array($tax) ? $tax['calc_value'] . ' ' . $tax['calc_value_mathop'] : $shipping_tax_id;
		$taxDisplay      = ($taxDisplay == -1) ? JText::_('COM_VIRTUEMART_PRODUCT_TAX_NONE') : $taxDisplay;
	}

	function getShippingValue($method, $cart_prices)
	{

		$free_shipping = $method->free_shipping;
		if ($free_shipping && $cart_prices['salesPrice'] >= $free_shipping)
		{
			return 0;
		}
		else
		{
			return $method->rate_value + $method->package_fee;
		}
	}

	function getShippingTaxId($method)
	{

		return $method->shipping_tax_id;
	}

	function checkShippingConditions($cart, $method)
	{

		$response = $this->_getUPSrates($cart, $method);

		return count($response);
	}

	/*
		 * getSelectableShipping
		 * This method returns the number of shipping methods valid
		 * @param VirtueMartCart cart: the cart object
		 * @param $virtuemart_shipment_id
		 *
		 */

	function getSelectableShipping(VirtueMartCart $cart, &$virtuemart_shipment_id)
	{

		// vmdebug('getSelectableShipping selected','UPS');
		$nbShipper = 0;
		if ($this->getShippers($cart->vendorId) === false)
		{
			return false;
		}

		foreach ($this->shippers as $method)
		{
			$nbShipper              += $this->checkShippingConditions($cart, $method);
			$virtuemart_shipment_id = $method->virtuemart_shipment_id;
		}

		return $nbShipper;
	}

	// Does the condition  fith to display it ?
	protected function checkConditions($cart, $method, $cart_prices)
	{

		$orderWeight = $this->getOrderWeight($cart, $method->weight_unit);
		$address     = $cart->STsameAsBT == 1 ? $cart->BT : $cart->ST;

		// $countries = array();
		// if (!empty($method->countries_domestic)) {
		// if (!is_array($method->countries_domestic)) {
		// $method->countries_domestic = (array) $method->countries_domestic;
		// }
		// }
		// if (!empty($method->countries_intl)) {
		// if (!is_array($method->countries_intl)) {
		// $method->countries_intl = (array) $method->countries_intl;
		// }
		// } else {
		// $method->countries_intl = array();
		// }
		// $countries = array_merge((array)$method->countries_domestic, (array)$method->countries_intl);
		if ($method->countries_domestic)
		{
			$db    = JFactory::getDBO();
			$query = 'SELECT virtuemart_country_id';
			$query .= ' FROM `#__virtuemart_countries`';
			$query .= ' WHERE  ';
			$query .= '`country_2_code` IN ( "' . implode('","', $method->countries_domestic) . '" )';
			// foreach ($countries as country) {
			// $query.= '`country_2_code` =\'' . $country . '\'' . $or;
			// }

			$db->setQuery($query);
			$countries_id = $db->loadResultArray();
		}
		else
		{
			$countries_id = array();
		}

		// probably did not gave his BT:ST address
		if (!is_array($address))
		{
			$address                          = array();
			$address['zip']                   = 0;
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id']))
		{
			$address['virtuemart_country_id'] = 0;
		}
		if (in_array($address['virtuemart_country_id'], $countries_id) || count($countries_id) == 0)
		{

			if ($responses = $this->_getUPSrates($cart, $method))
			{

				if (!$responses)
				{
					vmdebug('no UPS responses', $responses);

					return 0;
				}
				//We must provide here an array

				foreach ($responses as $response)
				{
					$idx                                         = $response['Service']['Code'];
					$ups_rates[$idx]['id']                       = $response['Service']['Code'];
					$ups_rates[$idx]['code3']                    = $response['TotalCharges']['CurrencyCode'];
					$ups_rates[$idx]['rate']                     = $response['TotalCharges']['MonetaryValue'];
					$ups_rates[$idx]['GuaranteedDaysToDelivery'] = isset($response['GuaranteedDaysToDelivery']) ? $response['GuaranteedDaysToDelivery'] : 0;
				}
				if (isset($this->ups_rates['id']))
				{
					if (array_key_exists($this->ups_rates['id'], $ups_rates))
					{
						$ups_rates = $ups_rates[$this->ups_rates['id']];
						$this->_setUpsIntoSession($ups_rates);

						return 1;
					}
				}
				$this->_setUpsIntoSession($ups_rates);
			}

			return count($responses);
		}

		return false;
	}

	function _weightCond($orderWeight, $method)
	{

		return true;
	}

	function _getUPSrates($cart, $method)
	{

		if (count($cart->products) == 0)
		{
			vmdebug('no products error', $cart->products);

			return null;
		}

		$to_address = $cart->STsameAsBT == 1 ? $cart->BT : $cart->ST;
		if (empty($to_address['zip']) || !isset($to_address['virtuemart_country_id']))
		{
			vmdebug('adress error', $to_address);

			return null;
		}

		// $order_weight = 0;
		// $packageCount = 0;
		// $packages = array();
		// CONTROL Country
		if ($method->countries_domestic)
		{
			$db    = JFactory::getDBO();
			$query = 'SELECT virtuemart_country_id';
			$query .= ' FROM `#__virtuemart_countries`';
			$query .= ' WHERE  ';
			$query .= '`country_2_code` IN ( "' . implode('","', $method->countries_domestic) . '" )';
			// foreach ($countries as country) {
			// $query.= '`country_2_code` =\'' . $country . '\'' . $or;
			// }

			$db->setQuery($query);
			$countries_id = $db->loadColumn();
		}
		else
		{
			$countries_id = array();
		}

		if (!in_array($to_address['virtuemart_country_id'], $countries_id) and count($countries_id) != 0)
		{
			vmdebug('countries_id error', $to_address['virtuemart_country_id'], $countries_id);

			return null;
		}

		$response = $this->_getUPSResponse($method, $cart);

		return $response;
	}

	function _getUPSResponse($method, $cart)
	{

		if ($this->_getRequestXML($method, $cart))
		{
			$this->_sendRequestXML($method);

			return $this->_handleResponseXML($method, $cart);
		}
		else
		{
			return null;
		}
	}

	/**
	 *
	 *
	 * @param   type  $method
	 * @param   type  $cart
	 */
	function _getRequestXML($method, $cart)
	{

		$this->_accessRequestXML($method);

		return $this->_RatingServiceSelectionRequestXML($method, $cart);
	}

	function _accessRequestXML($method)
	{

		$this->_xml_request = '<?xml version="1.0"?>
	<AccessRequest xml:lang="en-US">
		<AccessLicenseNumber>' . $method->key . '</AccessLicenseNumber>
		<UserId>' . $method->account . '</UserId>
		<Password>' . $method->password . '</Password>
	</AccessRequest>';
	}

	function _RatingServiceSelectionRequestXML($method, $cart)
	{

		if (!$to_address = $this->getValidShopperAdress($cart))
		{
			return null; // shopper invalid for this shipment method
		}
		if (@$method->vendor_id)
		{
			$vendorId = $method->vendor_id;
		}
		else
		{
			$vendorId = $cart->vendorId;
		}
		$vendorAddress     = new stdClass();
		$wharehouseAddress = new stdClass();
		if (!self::getVendorWhareHouseAddress($method, $vendorId, $vendorAddress, $wharehouseAddress))
		{
			return null; // vendor invalid for this shipment method
		}
		$order_weight   = $order_Length = $order_Width = $order_Height = 0;
		$to_weight_unit = substr($method->weight_unit, 0, 2); // remove "s"
		foreach ($cart->products as $product)
		{
			vmdebug("product->product_weight", $product->product_weight);
			$order_weight += (ShopFunctions::convertWeigthUnit($product->product_weight, $product->product_weight_uom, $to_weight_unit) * $product->quantity);
			vmdebug("order_weight", $order_weight);
			/*
			if ($method->send_dimensions == 1) {
				$order_Length += (ShopFunctions::convertWeigthUnit ($product->product_weight, $product->product_weight_uom, $to_weight_unit) * $product->quantity);
			}
			*/
		}
		if ($order_weight == 0)
		{
			vmdebug('UPS: Total weigth is 0.');

			return false;
		}
		if ($order_weight < 0.1)
		{
			$order_weight = 0.1;
		}

		$order_weight = round($order_weight, 1);
		vmdebug("order_weight after rounding", $order_weight);
		if ($method->pickup_type == 11 and empty($method->customer_classification))
		{
			vmError('UPS: ' . JText::_('VMSHIPMENT_ISTRAXX_UPS_ERROR_PICKUP_CUSTOMER_CLASSIFICATION'),
				'UPS: ' . JText::_('VMSHIPMENT_ISTRAXX_UPS_ERROR_PICKUP_CUSTOMER_CLASSIFICATION') . '<br />' . JText::_
				('VMSHIPMENT_ISTRAXX_UPS_ERROR_FE'));

			return false;
		}
		if (empty($method->shipper_number) and $method->negociated_rates)
		{
			vmError('UPS: ' . JText::_('VMSHIPMENT_ISTRAXX_UPS_ERROR_PICKUP_CUSTOMER_CLASSIFICATION'),
				'UPS: ' . JText::_('VMSHIPMENT_ISTRAXX_UPS_ERROR_PICKUP_CUSTOMER_CLASSIFICATION') . '<br />' . JText::_
				('VMSHIPMENT_ISTRAXX_UPS_ERROR_FE'));

			return false;
		}
		$request_title       = "Rating"; // Custom tile(here for rating)
		$request_description = "Rating compare"; // custom Desc(here for rating)
		/*
			 * Comparing Rates :value “Shop” in the Request/RequestOption element.
			 * Finding the Rate for one service :
		 * value “Rate” If the request does not provide an option, the server defaults to rate behavior.
		* Rate = The server rates and validates the shipment. This is the default behavior if an option is not provided.
		Shop = The server validates the shipment, and returns rates for all UPS products from the ShipFrom to the ShipTo addresses.
			 */
		if ($this->_ups_rate_to_check === true)
		{
			$request_option = 'shop';
		}
		else
		{
			$request_option = 'shop';
		}
		$this->_xml_request .= "
	<?xml version=\"1.0\"?>
	<RatingServiceSelectionRequest xml:lang=\"en-US\">
	<Request>
	<TransactionReference>
	<CustomerContext>" . $request_title . "</CustomerContext>
	<XpciVersion>1.0001</XpciVersion>
	</TransactionReference>
	<RequestAction>rate</RequestAction>
	<RequestOption>" . $request_option . "</RequestOption>
	</Request>
	  <PickupType>
	  <Code>" . $method->pickup_type . "</Code>
	  </PickupType>";
		if ($method->pickup_type == 11)
		{
			$this->_xml_request .= "
		<CustomerClassification>
		        <Code>" . $method->customer_classification . "</Code>
		</CustomerClassification> ";
		}
		$this->_xml_request .= "<Shipment>
		<Description>" . $request_description . "</Description>";

		if ($this->_ups_rate_to_check === true)
		{
			if (!empty($this->ups_rates['id']))
			{
				$this->_xml_request .= "
		<Service>
			<Code>
				" . $this->ups_rates['id'] . "
			</Code>
		</Service>";
			}
		}

		$this->_xml_request .= '
		<Shipper>
			<Address>
			<Name>' . $vendorAddress->company . '</Name>
			  <PhoneNumber>' . $vendorAddress->phone_1 . '</PhoneNumber>';
		if ($method->negociated_rates)
		{
			$this->_xml_request .= '
			   <ShipperNumber>' . $method->shipper_number . '</ShipperNumber>';
		}
		$this->_xml_request .= '
		<AddressLine1>' . $vendorAddress->address_1 . '</AddressLine1>';
		if (!empty($vendorAddress->address_2))
		{
			$this->_xml_request .= '
			<AddressLine2>' . $vendorAddress->address_2 . '</AddressLine2>';
		}
		$this->_xml_request .= '
			   <City>' . $vendorAddress->city . '</City>
			   <StateProvinceCode>' . $vendorAddress->state_name . '</StateProvinceCode>
				<PostalCode>' . $vendorAddress->zip . '</PostalCode>
				<CountryCode>' . $vendorAddress->country_2_code . '</CountryCode>
			</Address>
		</Shipper>
		<ShipTo>
			<Address>
				<PostalCode>' . $to_address['zip'] . '</PostalCode>
				<CountryCode>' . $to_address['country_2_code'] . '</CountryCode>';
		if (isset($method->negociated_rates) and $method->negociated_rates)
		{
			$this->_xml_request .= '
			 <StateProvinceCode>' . $to_address['state_name'] . '</StateProvinceCode>';
		}
		if (isset($method->destination_type))
		{
			if ($method->destination_type == "residential" or ($method->destination_type == "auto" and empty($to_address['company'])))
			{
				$this->_xml_request .= '
			        <ResidentialAddressIndicator/>';
			}
		}
		else
		{
			$this->_xml_request .= '
			        <ResidentialAddressIndicator/>';
		}
		$this->_xml_request .= '
			</Address>
		</ShipTo>
		<ShipFrom>
			<Address>
				<PostalCode>' . $wharehouseAddress->zip . '</PostalCode>
				<CountryCode>' . $wharehouseAddress->country_2_code . '</CountryCode>';
		if (isset($method->negociated_rates) and $method->negociated_rates)
		{
			$this->_xml_request .= '
				<StateProvinceCode>' . $wharehouseAddress->state_name . '</StateProvinceCode>';
		}
		$this->_xml_request .= '
			</Address>
		</ShipFrom>
		<Package>
			<PackagingType>
				<Code>' . $method->packaging . '</Code>
				<Description>' . $method->packaging . ' Description</Description>
			</PackagingType>
			<PackageWeight>
				<UnitOfMeasurement>
					<Code>' . $method->weight_unit . '</Code>
				</UnitOfMeasurement>
				<Weight>' . $order_weight . '</Weight>
			</PackageWeight>';
		if ($method->insured_value)
		{
			$this->_xml_request .= '
			<PackageServiceOptions>
						<InsuredValue>
							<CurrencyCode>' . shopFunctions::getCurrencyByID($cart->pricesCurrency, 'currency_code_3') . '</CurrencyCode>
							<MonetaryValue>' . $cart->pricesUnformatted['salesPrice'] . '</MonetaryValue>
						</InsuredValue>
					</PackageServiceOptions>';
		}
		$this->_xml_request .= '
		</Package>';
		if (isset($method->negociated_rates) and $method->negociated_rates)
		{
			$this->_xml_request .= '
			<RateInformation>
				<NegotiatedRatesIndicator/>
			</RateInformation>';
		}
		// Used after customer selection
		// <ShipmentServiceOptions>
		// <OnCallAir>
		// <Schedule>
		// <PickupDay>02</PickupDay>
		// <Method>02</Method>
		// </Schedule>
		// </OnCallAir>
		// </ShipmentServiceOptions>
		$this->_xml_request .= '
		</Shipment>
	</RatingServiceSelectionRequest>';

		return true;
	}

	function _sendRequestXML($method)
	{

		//$upsUrl = 'https://www.ups.com/ups.app/xml/Rate';
		$ch = curl_init($this->getServer($method));
		// curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $this->_xml_request);
		$this->_xml_response = curl_exec($ch);
		vmDebug('UPS: _sendRequestXML Request ', "<textarea cols='80' rows='15'>" . $this->_xml_request . "</textarea>");
		vmDebug('UPS: _sendRequestXML Response', "<textarea cols='80' rows='15'>" . $this->_xml_response . "</textarea>");

		curl_close($ch); /// close the curl session
	}

	function _handleResponseXML($method, $cart)
	{

		$response = array();
		//vmdebug('UPS: _handleResponseXML',"<textarea cols='80' rows='15'>".$this->_xml_response."</textarea>");
		$document_xml = new DomDocument();
		if (!($result = $document_xml->loadXML($this->_xml_response)))
		{ // Load answer
			vmdebug('_handleResponseXML, No XML Response');

			return null;
		}
		if (strstr($this->_xml_response, "Failure"))
		{
			$error      = true;
			$html       = "<span class=\"message\">" . JText::_('VMSHIPMENT_ISTRAXX_UPS_RESPONSE_ERROR') . "</span><br/>";
			$error_code = $document_xml->getElementsByTagName("ErrorCode");
			$error_code = $error_code->item(0);
			$error_code = $error_code->nodeValue;
			// $html .= "<strong>" . JText::_('VMSHIPMENT_ISTRAXX_UPS_REPONSE_ERROR_CODE') . '</strong> ' . $error_code . "<br/>";
			$error_desc = $document_xml->getElementsByTagName("ErrorDescription");
			$error_desc = $error_desc->item(0);
			$error_desc = $error_desc->nodeValue;
			$html       .= "<strong>" . JText::_('VMSHIPMENT_ISTRAXX_UPS_RESPONSE_ERROR_DESCRIPTION') . '</strong> ' . $error_desc . "<br/>";

			vmdebug('UPS _handleResponse XML ERROR:', $html);
			//vmAdminInfo ('UPS:' . $html);

			// vmDebug('UPS: _handleResponseXML', $shipment);

			return null;
		}
		// Only do the array if it's not in error
		$shipment = $this->dom_to_array($document_xml);

		if (!$shipment)
		{
			//vmAdminInfo ('UPS: _handleResponseXML No shipment' . $shipment);
			return null;
		}

		if (!class_exists('CurrencyDisplay'))
		{
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		$currency            = CurrencyDisplay::getInstance();
		$cartCurrency_code_3 = $currency->ensureUsingCurrencyCode($cart->pricesCurrency);
		$ratedShipment       = reset($shipment['RatingServiceSelectionResponse']['RatedShipment']);

		if ($cartCurrency_code_3 != @$ratedShipment['TotalCharges']['CurrencyCode'])
		{
			$currencyId    = $currency->getCurrencyIdByField(@$ratedShipment['TotalCharges']['CurrencyCode']);
			$converterRate = $currency->convertCurrencyTo($currencyId, 1, false); //TODO false or true ???
		}
		else
		{
			$converterRate = 1;
		}

		if (!empty($shipment['RatingServiceSelectionResponse']['RatedShipment']['Service']['Code']))
		{
			$shipment['RatingServiceSelectionResponse']['RatedShipment'] = array($shipment['RatingServiceSelectionResponse']['RatedShipment']);
		}

		foreach ($shipment['RatingServiceSelectionResponse']['RatedShipment'] as $key => $value)
		{
			if (!empty($value['Service']['Code']))
			{
				if (!in_array(@$value['Service']['Code'], $method->services))
				{
					$servicesNotConfigurated[$value['Service']['Code']] = JText::_('VMSHIPMENT_ISTRAXX_UPS_SERVICES_' . $value['Service']['Code']);

					unset($shipment['RatingServiceSelectionResponse']['RatedShipment'][$key]);
					continue;
				}
				else
				{
					// Price Converted
					$value['TotalCharges']['MonetaryValue'] = $value['TotalCharges']['MonetaryValue'] / $converterRate;
				}
			}
		}
		if (!$shipment['RatingServiceSelectionResponse']['RatedShipment'])
		{
			$servicesNotConfiguratedHtml = "<ul>";
			foreach ($servicesNotConfigurated as $key => $value)
			{
				$servicesNotConfiguratedHtml .= "<li>" . $value . " (" . $key . ") </li>";
			}
			$servicesNotConfiguratedHtml .= "</ul>";
			$servicesConfiguratedHtml    = "<ul>";
			if ($method->countryfrom == 'CA')
			{
				$prefix = 'VMSHIPMENT_ISTRAXX_UPS_SERVICES_CA_';
			}
			else
			{
				$prefix = 'VMSHIPMENT_ISTRAXX_UPS_SERVICES_';
			}
			foreach ($method->services as $serviceCode)
			{
				$serviceText              = JText::_($prefix . $serviceCode);
				$servicesConfiguratedHtml .= "<li>" . $serviceText . " (" . $serviceCode . ") </li>";
			}
			$servicesConfiguratedHtml .= "</ul>";
			vmdebug('UPS: The delivery services configurated in the shipment method do no match the Delivery Services returned by UPS.<br />The Delivery Services and Service codes returned by UPS are:' . $servicesNotConfiguratedHtml . 'Delivery Services and Service codes configurated  are:' . $servicesConfiguratedHtml);
			vmdebug('UPS: No RatedShipment: rates received for', $servicesNotConfigurated);

		}

		return $shipment['RatingServiceSelectionResponse']['RatedShipment'];
	}

	function _getResponseUPSHtml($method, $responses, $selectedShipment, &$htmlIn)
	{

		$vendorId          = 1;
		$vendorAddress     = "";
		$wharehouseAddress = "";
		if (!self::getVendorWhareHouseAddress($method, $vendorId, $vendorAddress, $wharehouseAddress))
		{
			return null; // vendor invalid for this shipment method
		}
		if ($wharehouseAddress->country_2_code == 'CA')
		{
			$prefix = 'VMSHIPMENT_ISTRAXX_UPS_SERVICES_CA_';
		}
		else
		{
			$prefix = 'VMSHIPMENT_ISTRAXX_UPS_SERVICES_';
		}
		if (!is_array($responses))
		{
			vmdebug('not an array', $responses);

			return $responses;
		}

		if (!class_exists('CurrencyDisplay'))
		{
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		//JHTML::script('ups.js', 'components/com_virtuemart/assets/js/', true);
		$currency = CurrencyDisplay::getInstance();
		//$html = '<div id="ups" data-shipment="' . $method->$pluginmethod_id . '">';
		// @patrick faux
		if ($selectedShipment == $method->virtuemart_shipmentmethod_id)
		{
			$checked = 'checked';
		}
		else
		{
			$checked = '';
		}
		$checked  = '';
		$html     = array();
		$i        = 0;
		$taxrules = array();
		if (!empty($method->tax_id))
		{
			$db = JFactory::getDBO();
			$q  = 'SELECT * FROM #__virtuemart_calcs WHERE `virtuemart_calc_id`="' . $method->tax_id . '" ';
			$db->setQuery($q);
			$taxrules = $db->loadAssocList();
		}
		if (!class_exists('calculationHelper'))
		{
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'calculationh.php');
		}
		$calculator = calculationHelper::getInstance();

		foreach ($responses as $response)
		{
			if (count($taxrules) > 0)
			{
				$salesPriceShipment = round($calculator->executeCalculation($taxrules, $response['rate']), 4);
				$shipmentTax        = round($salesPriceShipment - $response['rate'], 4);
			}
			else
			{
				$salesPriceShipment = $response['rate'];
				$shipmentTax        = 0;
			}
			$shipmentCostDisplay = $currency->priceDisplay($salesPriceShipment + $shipmentTax);
			if ($response['GuaranteedDaysToDelivery'])
			{
				$htmlDelivery = '<br/><span class="ups-days">' . JTEXT::sprintf('VMSHIPMENT_ISTRAXX_UPS_DAYS_GUARANTEE', $response['GuaranteedDaysToDelivery']) . '</span>';
			}
			else
			{
				$htmlDelivery = '';
			}

			$service = htmlspecialchars_decode(JText::_($prefix . $response['id'])); //) htmlspecialchars_decode($this->serviceCodes[$service]);
			$name    = json_encode($response);
			$id      = 'ups_id_' . $method->virtuemart_shipmentmethod_id . '_' . $i;
			$id      = 'ups_id_' . $i;

			$html [] = '<input type="radio" name="virtuemart_shipmentmethod_id" class="js-change-ups" data-ups=\'' . $name . '\' id="' . $id . '"   value="' . $method->virtuemart_shipmentmethod_id . '" ' . $checked . '>
	     <label  for="' . $id . '" >
			<span class="' . $this->_type . '"> ' . $service . ' (' . $shipmentCostDisplay . ')</span>
			' . $htmlDelivery . '
	     </label><br />

		 ';
			$i++;
		}

		$html[0]   = '
	     <input type="hidden" name="ups_rate" id="ups_rate" value="' . $response['id'] . '" />' . $html[0];
		$htmlIn [] = $html;

		$js  = '
 jQuery(document).ready(function( $ ) {
	jQuery("input.js-change-ups").click( function(){
		ups = jQuery(this).data("ups");
		if (ups !== undefined ) {
			jQuery("#ups_rate").val(ups.id) ;
		}
	});
 });
 ';
		$doc = JFactory::getDocument();
		$doc->addScriptDeclaration($js);

		return true;
	}

	function getUserName($method)
	{

		return $method->username;
	}

	function getPassword($method)
	{

		return;
	}

	function getServer($method)
	{

		return $server = "https://www.ups.com/ups.app/xml/Rate";
	}

	function getValidShopperAdress($cart)
	{

		$to_address = $cart->STsameAsBT == 1 ? $cart->BT : $cart->ST;
		if (empty($to_address['zip']) or empty($to_address['virtuemart_country_id']))
		{
			return null;
		}
		$to_address['state_name'] = shopFunctions::getStateByID($to_address['virtuemart_state_id']);
		if ($to_address['country_2_code'] = shopFunctions::getCountryByID($to_address['virtuemart_country_id'], 'country_2_code'))
		{
			return $to_address;
		}

		return null;
	}

	static function getVendorWharehouseAddress($method, $vendorId, &$vendorAddress, &$wharehouseAddress)
	{

		$vendormodel                   = VmModel::getModel('vendor');
		$vendorAddress                 = $vendormodel->getvendorAdressBT($vendorId);
		$vendorAddress->country_2_code = shopFunctions::getCountryByID($vendorAddress->virtuemart_country_id, 'country_2_code');
		$vendorAddress->state_name     = shopFunctions::getStateByID($vendorAddress->virtuemart_state_id, 'state_name');
		if (!($vendorAddress->country_2_code && $vendorAddress->zip))
		{
			vmError('UPS: you must configure your shop with a ZIP code and Country');

			return false;
		}
		if ($method->ups_address == 'shop')
		{
			$wharehouseAddress = $vendorAddress;
		}
		else
		{
			$wharehouseAddress             = new stdClass();
			$wharehouseAddress->zip        = $method->shipper_shipfrom;
			$wharehouseAddress->city       = $method->shipper_cityfrom;
			$wharehouseAddress->company    = $method->shipper_companyfrom;
			$wharehouseAddress->phone_1    = $method->shipper_phonefrom;
			$wharehouseAddress->address_1  = $method->shipper_address1from;
			$wharehouseAddress->address_2  = $method->shipper_address2from;
			$wharehouseAddress->state_name = $method->shipper_statefrom;
			if (is_array($method->countryfrom))
			{
				$wharehouseAddress->country_2_code = $method->countryfrom[0];
			}
			else
			{
				$wharehouseAddress->country_2_code = $method->countryfrom;
			}
		}
		vmDebug('getVendorWharehouseAddress', $vendorAddress->country_2_code, $vendorAddress->state_name, $wharehouseAddress->country_2_code, $wharehouseAddress->state_name);

		return true;
	}

	function getCosts(VirtueMartCart $cart, $method, $cart_prices)
	{
		// TODO get te rel UPS values
		if ($method->free_shipment && $cart_prices['salesPrice'] >= $method->free_shipment)
		{
			return 0;
		}
		else
		{
			return $method->cost + $method->package_fee;
		}
	}

	function plgVmDeclarePluginParamsShipment($name, $id, &$data)
	{
		return $this->declarePluginParams('shipment', $name, $id, $data);
	}

	function plgVmSetOnTablePluginParamsShipment($name, $id, &$table)
	{
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	/**
	 * Converts a DOM element into an array
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 *
	 * @author Patrick
	 *
	 */
	function dom_to_array($root)
	{

		$result = array();

		if ($root->hasAttributes())
		{
			$attrs = $root->attributes;

			foreach ($attrs as $i => $attr)
			{
				$result[$attr->name] = $attr->value;
			}
		}

		$children = $root->childNodes;

		if ($children->length == 1)
		{
			$child = $children->item(0);

			if ($child->nodeType == XML_TEXT_NODE)
			{
				$result['_value'] = $child->nodeValue;

				if (count($result) == 1)
				{
					return $result['_value'];
				}
				else
				{
					return $result;
				}
			}
		}

		$group = array();

		for ($i = 0; $i < $children->length; $i++)
		{
			$child = $children->item($i);

			if (!isset($result[$child->nodeName]))
			{
				$result[$child->nodeName] = $this->dom_to_array($child);
			}
			else
			{
				if (!isset($group[$child->nodeName]))
				{
					$tmp                      = $result[$child->nodeName];
					$result[$child->nodeName] = array($tmp);
					$group[$child->nodeName]  = 1;
				}

				$result[$child->nodeName][] = $this->dom_to_array($child);
			}
		}

		return $result;
	}

	protected function getVmPluginCreateTableSQL()
	{
		return $this->createTableSQL('Shipment UPS Table');
	}

	public function plgVmonShowOrderPrint($order_number, $method_id)
	{
		return $this->onShowOrderPrint($order_number, $method_id);
	}

	public function plgVmSetOnTablePluginShipment(&$data, &$table)
	{
		$name = $data['shipment_element'];
		$id = $data['shipment_jplugin_id'];

		return $this->setOnTablePluginParams($name, $id, $table);
	}

	public function plgVmOnShowOrderFEShipment($virtuemart_order_id, $virtuemart_shipmentmethod_id, &$shipment_name)
	{
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_shipmentmethod_id, $shipment_name);
	}

	public function plgVmDeclarePluginParamsShipmentVM3(&$data)
	{
		return $this->declarePluginParams('shipment', $data);
	}

	function plgVmOnStoreInstallShipmentPluginTable($jplugin_id)
	{
		if ($jplugin_id != $this->_jid)
		{
			return false;
		}
		$method = $this->getPluginMethod(JRequest::getInt('virtuemart_shipmentmethod_id'));
		// # 11 - Suggested Retail Rates (UPS Store)

		if ($method->pickup_type == 11 and empty($method->customer_classification))
		{
			VmError('VMSHIPMENT_ISTRAXX_UPS_ERROR_PICKUP_CUSTOMER_CLASSIFICATION');
		}

		if ($method->negociated_rates and empty($method->shipper_number))
		{
			VmError(JText::_('VMSHIPMENT_ISTRAXX_UPS_ERROR_SHIPPER_NEGOCIATED'));
		}

		return $this->onStoreInstallPluginTable($jplugin_id);
	}
}
