<?php
/**
 * Integration class for Joomla! CMS 3.x
 *
 * @package    Webservices
 * @copyright  Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Webservices\Integrations\Joomla;

use Joomla\DI\ContainerAwareInterface;
use Joomla\DI\Container;
use Joomla\DI\ContainerAwareTrait;
use Joomla\Registry\Registry;
use Joomla\Webservices\Integrations\IntegrationInterface;
use Joomla\Webservices\Integrations\Joomla\Authorisation\Authorise;
use Joomla\Webservices\Integrations\AuthorisationInterface;
use Joomla\Webservices\Webservices\Webservice;
use Joomla\Webservices\Xml\XmlHelper;
use Joomla\Webservices\Webservices\ConfigurationHelper;
use Joomla\Authentication\AuthenticationStrategyInterface;

/**
 * Integration class for Joomla! CMS 3.x
 *
 * @package Joomla\Webservices\Integrations\Joomla
 */
class Joomla implements ContainerAwareInterface, IntegrationInterface
{
	use ContainerAwareTrait;

	/**
	 * The webservice object
	 *
	 * @var  Webservice
	 */
	private $webservice;

	/**
	 * Helper class object
	 *
	 * @var    object
	 * @since  __DEPLOY_VERSION__
	 */
	public $apiHelperClass = null;

	/**
	 * Dynamic model class object
	 *
	 * @var    object
	 * @since  __DEPLOY_VERSION__
	 */
	public $apiDynamicModelClass = null;

	/**
	 * Public constructor
	 *
	 * @param   Container   $container   The DIC object
	 * @param   Webservice  $webservice  The webservice object
	 */
	public function __construct(Container $container, Webservice $webservice)
	{
		$this->setContainer($container);
		$this->webservice = $webservice;

		// Constant that is checked in included files to prevent direct access.
		define('_JEXEC', 1);

		// Don't let the session load twice! So inject ours into Joomla
		/** @var \Joomla\Session\Session $session */
		$session =  $container->get("session");
		$data = array(
			'session' => false,
			'session_name' => $session->getName()
		);

		$applicationConfig = new Registry($data);
		$client = $webservice->options->get('webserviceClient', 'site');

		// Get the CMS base data and load the application
		if ($client == 'administrator')
		{
			define('JPATH_BASE',      JPATH_CMS . DIRECTORY_SEPARATOR . 'administrator');
			require_once JPATH_BASE . '/includes/defines.php';
			require_once JPATH_BASE . '/includes/framework.php';
			$app = new \JApplicationAdministrator(null, $applicationConfig);
		}
		else
		{
			define('JPATH_BASE',      JPATH_CMS);
			require_once JPATH_BASE . '/includes/defines.php';
			require_once JPATH_BASE . '/includes/framework.php';
			$app = new \JApplicationSite(null, $applicationConfig);
		}

		/** @var \Joomla\Language\LanguageFactory $languageFactory */
		$languageFactory = $this->getContainer()->get('Joomla\\Language\\LanguageFactory');
		$lang = $languageFactory->getLanguage();

		// Set up the Joomla language object with the instances we are using
		$joomlaLang = \JLanguage::getInstance($lang->getLanguage(), $lang->getDebug());
		$app->loadLanguage($joomlaLang);

		// Set the application and language objects into JFactory.
		\JFactory::$application = $app;
		\JFactory::$language = $joomlaLang;

		/**
		 * Set the session object into JFactory now. Note that we are injecting
		 * our framework session used here and not a JSession object.
		 */
		\JFactory::$session = $container->get("session");

		/**
		 * Set the application instance into the instances property of JApplicationCms for when some
		 * classes call JApplicationCms::getInstance() rather than JFactory::getApplication()
		 */
		$reflection = new \ReflectionClass($app);
		$property = $reflection->getProperty('instances');
		$property->setAccessible(true);
		$property->setValue($app, array($client => $app));
	}

	/**
	 * Gets a Joomla authorisation object
	 *
	 * @param   mixed  $id  Unique identifier for the user - either an id or a username
	 *
	 * @return  AuthorisationInterface
	 */
	public function getAuthorisation($id)
	{
		return new Authorise($id);
	}

	/**
	 * Load model class for data manipulation
	 *
	 * @param   string             $elementName    Element name
	 * @param   \SimpleXMLElement  $configuration  Configuration for current action
	 *
	 * @return  mixed  Model class for data manipulation
	 *
	 * @since   1.2
	 */
	public function loadModel($elementName, $configuration)
	{
		$isAdmin = XmlHelper::isAttributeTrue($configuration, 'isAdminClass');
		$this->addModelIncludePaths($isAdmin, $this->webservice->optionName);
		$this->loadExtensionLanguage($this->webservice->optionName, $isAdmin ? JPATH_ADMINISTRATOR : JPATH_SITE);
		$this->loadExtensionLibrary($this->webservice->optionName);
		$dataMode = strtolower(XmlHelper::attributeToString($configuration, 'dataMode', 'model'));

		if ($dataMode == 'helper')
		{
			return $this->getHelperObject();
		}

		if ($dataMode == 'table')
		{
			return $this->getDynamicModelObject($configuration);
		}

		if (!empty($configuration['modelClassName']))
		{
			$modelClass = (string) $configuration['modelClassName'];

			if (!empty($configuration['modelClassPath']))
			{
				require_once JPATH_SITE . '/' . $configuration['modelClassPath'];

				if (class_exists($modelClass))
				{
					return new $modelClass;
				}
			}
			else
			{
				$componentName = ucfirst(strtolower(substr($this->webservice->optionName, 4)));
				$prefix = $componentName . 'Model';

				$model = \JModelAdmin::getInstance($modelClass, $prefix);

				if ($model)
				{
					return $model;
				}
			}
		}

		if (!empty($this->viewName))
		{
			$elementName = $this->viewName;
		}

		$componentName = ucfirst(strtolower(substr($this->webservice->optionName, 4)));
		$prefix = $componentName . 'Model';

		return \JModelAdmin::getInstance($elementName, $prefix);
	}

	/**
	 * Gets instance of dynamic model object class (for table bind)
	 *
	 * @param   \SimpleXMLElement  $configuration  Configuration for current action
	 *
	 * @return mixed It will return Api dynamic model class
	 *
	 * @throws  \Exception
	 * @since   __DEPLOY_VERSION__
	 */
	private function getDynamicModelObject($configuration)
	{
		if (!empty($this->apiDynamicModelClass))
		{
			return $this->apiDynamicModelClass;
		}

		$tableName = XmlHelper::attributeToString($configuration, 'tableName', '');

		if (empty($tableName))
		{
			/** @var \Joomla\Language\LanguageFactory $languageFactory */
			$languageFactory = $this->getContainer()->get('Joomla\\Language\\LanguageFactory');
			$text = $languageFactory->getText();

			throw new \Exception($text->translate('LIB_WEBSERVICES_API_HAL_WEBSERVICE_TABLE_NAME_NOT_SET'));
		}

		$context = $this->webservice->webserviceName . '.' . $this->webservice->webserviceVersion;

		// We are not using prefix like str_replace(array('.', '-'), array('_', '_'), $context) . '_';
		$paginationPrefix = '';
		$filterFields = ConfigurationHelper::getFilterFields($configuration);
		$primaryFields = $this->webservice->getPrimaryFields($configuration);
		$fields = $this->webservice->getAllFields($configuration);

		$config = array(
			'tableName' => $tableName,
			'context'   => $context,
			'paginationPrefix' => $paginationPrefix,
			'filterFields' => $filterFields,
			'primaryFields' => $primaryFields,
			'fields' => $fields,
		);

		$baseJoomlaModelClass = '\\Joomla\\Webservices\\Integrations\\Joomla\\Model\\';
		$apiDynamicModelClassName = '';

		if ($this->webservice->operation == 'read')
		{
			$primaryKeys = array();
			$isReadItem = $this->webservice->apiFillPrimaryKeys($primaryKeys);

			$displayTarget = $isReadItem ? 'item' : 'jlist';
			$apiDynamicModelClassName = $baseJoomlaModelClass . ucfirst($displayTarget);
		}
		elseif ($this->webservice->operation == 'delete')
		{
			$apiDynamicModelClassName = $baseJoomlaModelClass . 'List';
		}

		if (!empty($apiDynamicModelClassName) && class_exists($apiDynamicModelClassName))
		{
			$this->apiDynamicModelClass = new $apiDynamicModelClassName($config);
		}

		return $this->apiDynamicModelClass;
	}

	/**
	 * Add include paths for model class
	 *
	 * @param   boolean  $isAdmin     Is client admin or site
	 * @param   string   $optionName  Option name
	 *
	 * @return  void
	 *
	 * @since   1.3
	 */
	private function addModelIncludePaths($isAdmin, $optionName)
	{
		if ($isAdmin)
		{
			$this->loadExtensionLanguage($optionName, JPATH_ADMINISTRATOR);
			$path = JPATH_ADMINISTRATOR . '/components/' . $optionName;
			\JModelLegacy::addIncludePath($path . '/models');
			\JTable::addIncludePath($path . '/tables');
			\JForm::addFormPath($path . '/models/forms');
			\JForm::addFieldPath($path . '/models/fields');
		}
		else
		{
			$this->loadExtensionLanguage($optionName);
			$path = JPATH_SITE . '/components/' . $optionName;
			\JModelLegacy::addIncludePath($path . '/models');
			\JTable::addIncludePath($path . '/tables');
			\JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/' . $optionName . '/tables');
			\JForm::addFormPath($path . '/models/forms');
			\JForm::addFieldPath($path . '/models/fields');
		}

		if (!defined('JPATH_COMPONENT'))
		{
			define('JPATH_COMPONENT', $path);
		}
	}

	/**
	 * Include library classes
	 *
	 * @param   string  $element  Option name
	 *
	 * @return  void
	 *
	 * @since   1.4
	 */
	private function loadExtensionLibrary($element)
	{
		$element = strpos($element, 'com_') === 0 ? substr($element, 4) : $element;
		\JLoader::import(strtolower($element) . '.library');
	}

	/**
	 * Load extension language file.
	 *
	 * @param   string  $option  Option name
	 * @param   string  $path    Path to language file
	 *
	 * @return  object
	 */
	private function loadExtensionLanguage($option, $path = JPATH_SITE)
	{
		/** @var \Joomla\Language\Language $lang */
		$lang = $this->getContainer()->get('Joomla\\Language\\LanguageFactory')->getLanguage();

		// Load common and local language files.
		$lang->load($option, $path, null, false, false)
		|| $lang->load($option, $path . "/components/$option", null, false, false)
		|| $lang->load($option, $path, $lang->getDefault(), false, false)
		|| $lang->load($option, $path . "/components/$option", $lang->getDefault(), false, false);

		return $this;
	}

	/**
	 * Gets instance of helper object class if exists
	 *
	 * @return  mixed It will return Api helper class or false if it does not exists
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function getHelperObject()
	{
		if (!empty($this->apiHelperClass))
		{
			return $this->apiHelperClass;
		}

		$version = $this->webservice->options->get('webserviceVersion', '');
		$helperFile = ConfigurationHelper::getWebserviceHelper($this->webservice->client, strtolower($this->webservice->webserviceName), $version, $this->webservice->webservicePath);

		if (file_exists($helperFile))
		{
			require_once $helperFile;
		}

		$webserviceName = preg_replace('/[^A-Z0-9_\.]/i', '', $this->webservice->webserviceName);
		$helperClassName = '\\JWebserviceHelper' . ucfirst($this->webservice->client) . ucfirst(strtolower($webserviceName));

		if (class_exists($helperClassName))
		{
			$this->apiHelperClass = new $helperClassName;
		}

		return $this->apiHelperClass;
	}

	/**
	 * Load Authentication Strategies. Returned array should have a key of the strategy name.
	 *
	 * @return  AuthenticationStrategyInterface[]
	 */
	public function getStrategies()
	{
		return array(
			'joomla' => new \Joomla\Webservices\Integrations\Joomla\Strategy\Joomla
		);
	}
}