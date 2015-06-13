<?php
/**
 * Application for Joomla Webservices.
 *
 * @package    Webservices
 * @copyright  Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Webservices;

use Joomla\Application\AbstractWebApplication;
use Joomla\Application\Web\WebClient;
use Joomla\Input\Input;

use Joomla\DI\Container;
use Joomla\DI\ContainerAwareTrait;
use Joomla\DI\ContainerAwareInterface;

use Joomla\Webservices\Api\Api;
use Joomla\Webservices\Api\ApiInterface;
use Joomla\Webservices\Api\Soap\SoapHelper;
use Joomla\Webservices\Api\Hal\HalHelper;

/**
 * Webservices bootstrap class
 *
 * @package     Red
 * @subpackage  System
 * @since       1.0
 */
class Application extends AbstractWebApplication implements ContainerAwareInterface
{
	use ContainerAwareTrait;

	/**
	 * Constructor.
	 *
	 * @param   Container  $container  DI Container
	 * @param   Input      $input      An optional argument to provide dependency injection for the application's
	 *                                 input object.  If the argument is a Input object that object will become
	 *                                 the application's input object, otherwise a default input object is created.
	 * @param   WebClient  $client     An optional argument to provide dependency injection for the application's
	 *                                 client object.  If the argument is a Web\WebClient object that object will become
	 *                                 the application's client object, otherwise a default client object is created.
	 *
	 * @since   1.0
	 */
	public function __construct(Container $container, Input $input = null, WebClient $client = null)
	{
		$config = $container->get('config');

		parent::__construct($input, $config, $client);

		$container->set('Joomla\\Webservices\\Application', $this)
			->alias('Joomla\\Application\\AbstractWebApplication', 'Joomla\\Webservices\\Application')
			->alias('Joomla\\Application\\AbstractApplication', 'Joomla\\Webservices\\Application')
			->alias('app', 'Joomla\\Webservices\\Application')
			->set('Joomla\\Input\\Input', $this->input)
			->set('Joomla\\DI\\Container', $container);

		$this->setContainer($container);
	}

	/**
	 * Method to run the application routines.  Most likely you will want to instantiate a controller
	 * and execute it, or perform some sort of task directly.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function doExecute()
	{
		$this->getContainer()->get('Joomla\\Language\\LanguageFactory')->getLanguage()->load('lib_webservices');

		/** @var \Joomla\Language\Text $text */
		$text = $this->getContainer()->get('Joomla\\Language\\LanguageFactory')->getText();

		$apiName = $this->input->getString('api');

		if ($this->isApiEnabled($apiName))
		{
			$input = $this->input;

			if (!empty($apiName))
			{
				try
				{
					$this->clearHeaders();
					$webserviceClient = $input->get->getString('webserviceClient', '');
					$optionName       = $input->get->getString('option', '');
					$optionName       = strpos($optionName, 'com_') === 0 ? substr($optionName, 4) : $optionName;
					$viewName         = $input->getString('view', '');
					$version          = $input->getString('webserviceVersion', '');

					// This is deprecated in favor of Plugin trigger
					//$token = $input->getString(JBootstrap::getConfig('oauth2_token_param_name', 'access_token'), '');
					$token   = '';
					$apiName = ucfirst($apiName);
					$method  = strtoupper($input->getMethod());
					$task    = HalHelper::getTask();
					$data    = Api::getPostedData($this->getContainer());
					$dataGet = $input->get->getArray();

					if (empty($webserviceClient))
					{
						$webserviceClient = $this->isAdmin() ? 'administrator' : 'site';
					}

					$options = array(
						'api'               => $apiName,
						'optionName'        => $optionName,
						'viewName'          => $viewName,
						'webserviceVersion' => $version,
						'webserviceClient'  => $webserviceClient,
						'method'            => $method,
						'task'              => $task,
						'data'              => $data,
						'dataGet'           => $dataGet,
						'accessToken'       => $token,
						'format'            => $input->getString('format', $this->get('webservices_default_format', 'json')),
						'id'                => $input->getString('id', ''),
						'absoluteHrefs'     => $input->get->getBool('absoluteHrefs', true),
					);

					$apiClass = 'Joomla\\Webservices\\Api\\' . $apiName . '\\' . $apiName;

					if (!class_exists($apiClass) || !($apiClass instanceof ApiInterface))
					{
						throw new \RuntimeException($text->sprintf('LIB_WEBSERVICES_API_UNABLE_TO_LOAD_API', $options['api']));
					}

					try
					{
						/** @var \Joomla\Webservices\Api\ApiBase $api */
						$api = new $apiClass($this->getContainer(), $options);
					}
					catch (\RuntimeException $e)
					{
						throw new \RuntimeException($text->sprintf('LIB_WEBSERVICES_API_UNABLE_TO_CONNECT_TO_API', $e->getMessage()));
					}

					// Run the api task
					$api->execute();

					// Display output
					$api->render();
				}
				catch (\Exception $e)
				{
					$code = $e->getCode() > 0 ? $e->getCode() : 500;

					// Set the server response code.
					$this->header('Status: ' . $code, true, $code);

					if (strtolower($apiName) == 'soap')
					{
						$this->setBody(SoapHelper::createSoapFaultResponse($e->getMessage()));
					}
					else
					{
						// Check for defined constants
						if (!defined('JSON_UNESCAPED_SLASHES'))
						{
							define('JSON_UNESCAPED_SLASHES', 64);
						}

						// An exception has been caught, echo the message and exit.
						$this->setBody(json_encode(array('message' => $e->getMessage(), 'code' => $e->getCode(), 'type' => get_class($e)), JSON_UNESCAPED_SLASHES));
					}
				}
			}
		}
	}

	/**
	 * Checks if given api name is currently install and enabled on this server
	 *
	 * @param   string  $apiName  Api name
	 *
	 * @return bool
	 */
	private function isApiEnabled($apiName)
	{
		$apiName = strtolower($apiName);

		return ($this->get('enable_webservices', 0) == 1 && $apiName == 'hal')
		|| ($this->get('enable_soap', 0) == 1 && $apiName == 'soap');
	}

	/**
	 * Checks whether we are in the site or admin of the Joomla CMS.
	 *
	 * @return  bool
	 * @todo    Implement a check here
	 */
	public function isAdmin()
	{
		return true;
	}
}