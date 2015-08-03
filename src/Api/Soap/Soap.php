<?php
/**
 * @package     Redcore
 * @subpackage  Api
 *
 * @copyright   Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Webservices\Api\Soap;

use Joomla\Webservices\Api\ApiBase;
use Joomla\Webservices\Webservices\Webservice;
use Joomla\Webservices\Api\Soap\Operation\Operation;
use Joomla\Webservices\Renderer\Soap as Document;
use Joomla\Webservices\Uri\Uri;
use Joomla\DI\Container;
use Joomla\Event\Event;
use Joomla\Event\EventImmutable;
use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;

/**
 * Class to represent a SOAP standard object.
 *
 * @since  1.2
 */
class Soap extends ApiBase  implements DispatcherAwareInterface
{
	/**
	 * @var    string  Operation that will be preformed with this Api call
	 * @since  1.2
	 */
	public $operation = 'soap';

	/**
	 * Webservice object
	 *
	 * @var    Webservice  Webservice object
	 * @since  1.4
	 */
	public $webservice = null;

	/**
	 * Container for WSDL file
	 *
	 * @var    \SimpleXMLElement  Generated Web service Description language file
	 * @since  1.4
	 */
	public $wsdl = null;

	/**
	 * Event Dispatcher Object
	 *
	 * @var    DispatcherInterface
	 * @since  __DEPLOY_VERSION__
	 */
	protected $dispatcher = null;

	/**
	 * Soap server response
	 *
	 * @var    string  XML Output from Soap server
	 * @since  1.4
	 */
	public $soapResponse = null;

	/**
	 * WSDL path
	 *
	 * @var    string  WSDL path
	 * @since  1.4
	 */
	public $wsdlPath = null;

	/**
	 * Method to instantiate the file-based api call.
	 *
	 * @param   Container  $container  The DIC object
	 * @param   Registry   $options    Optional custom options to load
	 *
	 * @throws  \Exception
	 * @since   1.4
	 */
	public function __construct(Container $container, Registry $options)
	{
		parent::__construct($container, $options);

		$this->setApi($this->options->get('api', 'soap'));

		$this->setDispatcher($container->get('Joomla\\Event\\Dispatcher'));

		$this->webservice = new Webservice($container, $options);
		$this->webservice->permissionCheck = 'joomla';

		// Init Environment
		$this->triggerFunction('setApiOperation');

		// Set initial status code
		$this->setStatusCode($this->statusCode);
	}

	/**
	 * Set Method for Api to be performed
	 *
	 * @return  $this
	 *
	 * @since   1.4
	 */
	public function setApiOperation()
	{
		$dataGet = $this->webservice->options->get('dataGet', array());
		$method = 'soap';

		if (isset($dataGet->wsdl))
		{
			$method = 'wsdl';
		}

		$this->operation = strtolower($method);

		return $this;
	}

	/**
	 * Execute the Api operation.
	 *
	 * @return  $this
	 *
	 * @since   1.4
	 * @throws  \Exception
	 */
	public function execute()
	{
		// We do not want some unwanted text to appear before output
		ob_start();

		try
		{
			$this->checkWSDL();

			switch ($this->operation)
			{
				case 'soap':
					$this->triggerFunction('apiSoap');
					break;

				case 'wsdl':
				default:
					$this->triggerFunction('apiWsdl');
			}

			$messages = $this->app->getMessageQueue();

			$executionErrors = ob_get_contents();
			ob_end_clean();
		}
		catch (\Exception $e)
		{
			$executionErrors = ob_get_contents();
			ob_end_clean();

			throw $e;
		}

		if (!empty($executionErrors))
		{
			$messages[] = array('message' => $executionErrors, 'type' => 'notice');
		}

		if (!empty($messages))
		{
			$this->webservice->resource->setData('_messages', $messages);
		}

		return $this;
	}

	/**
	 * Set the dispatcher to use.
	 *
	 * @param   DispatcherInterface  $dispatcher  The dispatcher to use.
	 *
	 * @return  DispatcherAwareInterface  This method is chainable.
	 *
	 * @since   1.0
	 */
	public function setDispatcher(DispatcherInterface $dispatcher)
	{
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Main Soap server
	 *
	 * @return  string  Full URL to the webservice
	 *
	 * @since   1.4
	 */
	public function apiSoap()
	{
		$uri = Uri::getInstance();

		// Get the URL to the Wsdl file by stripping the www directory from the file path
		$wsdlUriPath = str_replace('www/', '', $this->wsdlPath);
		$wsdl = $uri->root() . $wsdlUriPath;

		$params = array(
			'uri' => $wsdl,
			'soap_version' => SOAP_1_2,
		);
		$operation = new Operation($this->webservice);
		$server = new \SoapServer($wsdl, $params);
		$server->setObject($operation);

		ob_start();
		$server->handle();
		$response = ob_get_contents();
		ob_end_clean();

		$this->soapResponse = str_replace('&', '&amp;', str_replace('&amp;', '&', $response));
	}

	/**
	 * Returns Wsdl file
	 *
	 * @return  \SimpleXMLElement  WSDL file in xml format
	 *
	 * @since   1.4
	 */
	public function apiWsdl()
	{
		try
		{
			$content = @file_get_contents(JPATH_API . '/' . $this->wsdlPath);

			if (is_string($content))
			{
				$this->wsdl = new \SimpleXMLElement($content);
			}
			else
			{
				unlink(JPATH_API . '/' . $this->wsdlPath);
				$this->checkWSDL();

				return $this->apiWsdl();
			}
		}
		catch (\Exception $e)
		{
			unlink(JPATH_API . '/' . $this->wsdlPath);
			$this->checkWSDL();

			return $this->apiWsdl();
		}

		return $this->wsdl;
	}

	/**
	 * Checks and ensures that a static WSDL file exist and is in place
	 *
	 * @return  string  WSDL path
	 *
	 * @since   1.4
	 */
	public function checkWSDL()
	{
		try
		{
			// Wet wsdl from webservice location
			$this->wsdlPath = SoapHelper::getWsdlFilePath(
				$this->webservice->client,
				strtolower($this->webservice->webserviceName),
				$this->webservice->webserviceVersion,
				$this->webservice->webservicePath
			);

			if (is_readable(JPATH_API . '/' . $this->wsdlPath))
			{
				return $this->wsdlPath;
			}
		}
		catch (\Exception $e)
		{
		}

		// Something went wrong, we are going to generate it on the fly
		$this->wsdl = SoapHelper::generateWsdl($this->webservice->configuration, $this->wsdlPath);
		$this->wsdl->asXML(JPATH_API . '/' . $this->wsdlPath);

		return $this->wsdlPath;
	}

	/**
	 * Method to send the application response to the client.  All headers will be sent prior to the main
	 * application output data.
	 *
	 * @return  void
	 *
	 * @since   1.4
	 */
	public function render()
	{
		$documentOptions = array(
			'absoluteHrefs' => $this->webservice->options->get('absoluteHrefs', false),
			'documentFormat' => 'xml',
		);

		if ($this->operation == 'wsdl')
		{
			// Needed for formatting
			$dom = dom_import_simplexml($this->wsdl)->ownerDocument;
			$dom->preserveWhiteSpace = false;
			$dom->formatOutput = true;
			$body = $dom->saveXML();
		}
		else
		{
			// Add error faults if they exist
			if ($this->webservice->statusCode >= 300)
			{
				$this->setStatusCode(500);
				$body = SoapHelper::createSoapFaultResponse($this->webservice->statusCode . ' ' . $this->webservice->statusText);
			}
			else
			{
				$body = $this->getBody();
			}
		}

		$soapDocument = new Document($this->getContainer(), $documentOptions, ($this->operation == 'wsdl' ? 'xml' : 'soap+xml'));

		$body = $this->triggerFunction('prepareBody', $body);

		// Push results into the document.
		$this->app->setBody(
			$soapDocument
				->setApiObject($this)
				->setBuffer($body)
				->render(false)
		);
	}

	/**
	 * Method to fill response with requested data
	 *
	 * @return  string  Api call output
	 *
	 * @since   1.4
	 */
	public function getBody()
	{
		return $this->soapResponse;
	}

	/**
	 * Prepares body for response
	 *
	 * @param   string  $message  The return message
	 *
	 * @return  string	The message prepared
	 *
	 * @since   1.4
	 */
	public function prepareBody($message)
	{
		return $message;
	}

	/**
	 * Calls method from method from this class,
	 * Additionally it Triggers plugin call for specific function in a format JApiSoapFunctionName
	 *
	 * @param   string  $functionName  Field type.
	 *
	 * @return mixed Result from callback function
	 */
	public function triggerFunction($functionName)
	{
		$args = func_get_args();

		// Remove function name from arguments
		array_shift($args);

		// PHP 5.3 workaround
		$temp = array();

		foreach ($args as &$arg)
		{
			$temp[] = &$arg;
		}

		// We will add this instance of the object as last argument for manipulation in plugin and helper
		$temp[] = &$this;

		$event = new Event('JApiSoapBefore' . $functionName, $temp);
		$result = $this->dispatcher->triggerEvent($event);

		//if ($result)
		//{
		//	return $result;
		//}

		// Checks if that method exists in helper file and executes it
		$result = call_user_func_array(array($this, $functionName), $temp);

		$event = new EventImmutable('JApiSoapAfter' . $functionName, $temp);
		$this->dispatcher->triggerEvent($event);

		return $result;
	}
}
