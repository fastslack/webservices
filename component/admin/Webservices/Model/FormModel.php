<?php
/**
 * Webservices component for Joomla! CMS
 *
 * @copyright  Copyright (C) 2004 - 2016 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

namespace Webservices\Model;

use Joomla\Event\Event;
use Joomla\Event\Dispatcher;
use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherAwareTrait;
use Joomla\Event\DispatcherInterface;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;

use Webservices\Helper;

/**
 * Webservice Model
 *
 * @package     Joomla!
 * @subpackage  Webservices
 * @since       1.0
 */
class FormModel extends FormModelBase
{
	/**
	 * @var SimpleXMLElement
	 */
	public $xmlFile;

	/**
	 * @var SimpleXMLElement
	 */
	public $defaultXmlFile;

	/**
	 * @var string
	 */
	public $operationXml;

	/**
	 * @var array
	 */
	public $formData = array();

	/**
	 * @var array
	 */
	public $fields;

	/**
	 * @var array
	 */
	public $resources;

	/**
	 * The form name.
	 *
	 * @var  string
	 */
	protected $formName;

	/**
	 * Constructor.
	 *
	 * @param   array  $config  Configuration array
	 *
	 * @throws  RuntimeException
	 */
	public function __construct($config = array())
	{
		// Guess the option from the class name (Option)Model(View).
		if (empty($this->option))
		{
			$r = null;

			if (!preg_match('/(.*)Model/i', get_class($this), $r))
			{
				throw new Exception(JText::_('JLIB_APPLICATION_ERROR_MODEL_GET_NAME'), 500);
			}

			$this->option = 'com_' . strtolower($r[1]);

			$sub = explode("\\", $this->option);

			$this->option = $sub[0];
			$this->name = $sub[2];
		}

		if (is_null($this->context))
		{
			$this->context = strtolower($this->option . '.edit.' . $this->getName());
		}

		if (is_null($this->formName))
		{
			$this->formName = strtolower($this->getName());
		}

		$registry = new Registry;

		parent::__construct($this->context, $registry, Helper::createDbo());

		$this->defaultXmlFile = new \SimpleXMLElement(file_get_contents(JPATH_COMPONENT_ADMINISTRATOR . '/Webservices/Model/Forms/webservice_defaults.xml'));
	}

	/**
	 * Method to get the model name
	 *
	 * The model name. By default parsed using the classname or it can be set
	 * by passing a $config['name'] in the class constructor
	 *
	 * @return  string  The name of the model
	 *
	 * @since   12.2
	 * @throws  Exception
	 */
	public function getName()
	{
		if (empty($this->name))
		{
			$r = null;

			if (!preg_match('/Model(.*)/i', get_class($this), $r))
			{
				throw new Exception(JText::_('JLIB_APPLICATION_ERROR_MODEL_GET_NAME'), 500);
			}

			$this->name = strtolower($r[1]);

			$this->name = str_replace("\\", "", $this->name);
		}

		return $this->name;
	}

	/**
	 * Method to load a operation form template.
	 *
	 * @return  string  Xml
	 */
	public function loadFormOperationXml()
	{
		if (is_null($this->operationXml))
		{
			$this->operationXml = @file_get_contents(JPATH_COMPONENT_ADMINISTRATOR . '/Webservices/Model/Forms/webservice_operation.xml');
		}

		return $this->operationXml;
	}

	/**
	 * Method to get a form object.
	 *
	 * @param   array    $data      Data for the form.
	 * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
	 *
	 * @return  mixed  A JForm object on success, false on failure
	 */
	public function getForm($data = array(), $loadData = true)
	{
		// Get the form.
		$form = $this->loadForm(
			$this->context . '.' . $this->formName, $this->formName,
			array(
				'control' => 'jform',
				'load_data' => $loadData
			)
		);

		if ($form)
		{
			// Load dynamic form for operations
			$form->load(str_replace('"operation"', '"create"', $this->loadFormOperationXml()));
			$form->load(str_replace('"operation"', '"read-list"', $this->loadFormOperationXml()));
			$form->load(str_replace('"operation"', '"read-item"', $this->loadFormOperationXml()));
			$form->load(str_replace('"operation"', '"update"', $this->loadFormOperationXml()));
			$form->load(str_replace('"operation"', '"delete"', $this->loadFormOperationXml()));

			if (!empty($data))
			{
				foreach ($data as $operationName => $operation)
				{
					if (substr($operationName, 0, strlen('task-')) === 'task-')
					{
						$form->load(str_replace('"operation"', '"' . $operationName . '"', $this->loadFormOperationXml()));
					}
				}
			}

			if (!empty($this->xmlFile) && $tasks = $this->xmlFile->xpath('//operations/task'))
			{
				$tasks = $tasks[0];

				foreach ($tasks as $taskName => $task)
				{
					$form->load(str_replace('"operation"', '"task-' . $taskName . '"', $this->loadFormOperationXml()));
				}
			}

			$form->bind($this->formData);
		}

		if (empty($form))
		{
			return false;
		}

		return $form;
	}

	/**
	 * Method to get the data that should be injected in the form.
	 *
	 * @return  array  The default data is an empty array.
	 */
	protected function loadFormData()
	{
		// Check the session for previously entered form data.
		$data = \JFactory::getApplication()->getUserState(
			$this->context . '.data',
			array()
		);

		if (empty($data))
		{
			$dataDb = $this->getItem();
			$data = $this->bindXMLToForm();

			$dataArray = \JArrayHelper::fromObject($dataDb);
			$dataEmpty = array('main' => array());
			$data = array_merge($dataEmpty, $data);

			$data['main'] = array_merge($dataArray, $data['main']);
		}

		return $data;
	}

	/**
	 * Method to get a table object, load it if necessary.
	 *
	 * @param   string  $type    The table name. Optional.
	 * @param   string  $prefix  The class prefix. Optional.
	 * @param   array   $config  Configuration array for model. Optional.
	 *
	 * @return  JTable  A JTable object
	 *
	 * @since   1.0
	 */
	public function getTable($type = 'WebserviceTable', $prefix = '', $config = array())
	{
		$ret = new \Webservices\Table\WebserviceTable(Helper::createDbo());

		return $ret;
	}

	/**
	 * Load item object
	 *
	 * @param   integer  $pk  The id of the primary key.
	 *
	 * @return  mixed    Object on success, false on failure.
	 *
	 * @since   1.2
	 */
	public function getItem($pk = null)
	{
		$pk = (!empty($pk)) ? $pk : (int) \JFactory::getApplication()->input->get('id');

		if (!$item = $this->_getItem($pk))
		{
			return $item;
		}

		if (!empty($item->id) && is_null($this->xmlFile))
		{
			try
			{
				$this->xmlFile = \Joomla\Webservices\Webservices\ConfigurationHelper::loadWebserviceConfiguration(
					$item->name, $item->version, $item->path, $item->client
				);
			}
			catch (Exception $e)
			{
				JFactory::getApplication()->enqueueMessage(JText::sprintf('COM_WEBSERVICES_WEBSERVICE_ERROR_LOADING_XML', $e->getMessage()), 'error');
			}
		}

		// Add default webservice parameters since this is new webservice
		if (empty($this->xmlFile))
		{
			$this->xmlFile = $this->defaultXmlFile;
		}

		return $item;
	}

	/**
	 * Method to get a single record.
	 *
	 * @param   integer  $pk  The id of the primary key.
	 *
	 * @return  mixed    Object on success, false on failure.
	 *
	 * @since   12.2
	 */
	public function _getItem($pk = null)
	{
		$pk = (!empty($pk)) ? $pk : (int) $this->getState()->get($this->getName() . '.id');
		$table = $this->getTable();

		if ($pk > 0)
		{
			// Attempt to load the row.
			$return = $table->load($pk);

			// Check for a table object error.
			if ($return === false && $table->getError())
			{
				$this->setError($table->getError());

				return false;
			}
		}

		// Convert to the JObject before adding other data.
		$properties = $table->getProperties(1);
		$item = \JArrayHelper::toObject($properties, 'JObject');

		if (property_exists($item, 'params'))
		{
			$registry = new Registry;
			$registry->loadString($item->params);
			$item->params = $registry->toArray();
		}

		return $item;
	}

	/**
	 * Method to save the form data.
	 *
	 * @param   array  $data  The form data.
	 *
	 * @return  boolean  True on success, False on error.
	 *
	 * @since   12.2
	 */
	public function save($data)
	{
		$dispatcher = new Dispatcher;
		$table      = $this->getTable();
		$context    = $this->option . '.' . $this->name;

		if ((!empty($data['tags']) && $data['tags'][0] != ''))
		{
			$table->newTags = $data['tags'];
		}

		$key = $table->getKeyName();
		$pk = (!empty($data[$key])) ? $data[$key] : (int) $this->getState($this->getName() . '.id');
		$isNew = true;

		// Include the plugins for the save events.
		//\JPluginHelper::importPlugin($this->events_map['save']);

		// Allow an exception to be thrown.
		try
		{
			// Load the row if saving an existing record.
			if ($pk > 0)
			{
				$table->load($pk);
				$isNew = false;
			}

			// Bind the data.
			if (!$table->bind($data))
			{
				$this->setError($table->getError());

				return false;
			}

			// Prepare the row for saving
			$this->prepareTable($table);

			// Check the data.
			if (!$table->check())
			{
				$this->setError($table->getError());

				return false;
			}

			// Store the data.
			if (!$table->store())
			{
				$this->setError($table->getError());

				return false;
			}
		}
		catch (\Exception $e)
		{
			$this->setError($e->getMessage());

			return false;
		}

		$state = new Registry;

		if (isset($table->$key))
		{
			$state->set($this->getName() . '.id', $table->$key);
		}

		$state->set($this->getName() . '.new', $isNew);

		$this->setState($state);

		if ($this->associationsContext && \JLanguageAssociations::isEnabled())
		{
			$associations = $data['associations'];

			// Unset any invalid associations
			foreach ($associations as $tag => $id)
			{
				if (!(int) $id)
				{
					unset($associations[$tag]);
				}
			}

			// Show a notice if the item isn't assigned to a language but we have associations.
			if ($associations && ($table->language == '*'))
			{
				\JFactory::getApplication()->enqueueMessage(
					\JText::_(strtoupper($this->option) . '_ERROR_ALL_LANGUAGE_ASSOCIATED'),
					'notice'
				);
			}

			// Adding self to the association
			$associations[$table->language] = (int) $table->$key;

			// Deleting old association for these items
			$db    = $this->getDbo();
			$query = $db->getQuery(true)
				->delete($db->qn('#__associations'))
				->where($db->qn('context') . ' = ' . $db->quote($this->associationsContext))
				->where($db->qn('id') . ' IN (' . implode(',', $associations) . ')');
			$db->setQuery($query);
			$db->execute();

			if ((count($associations) > 1) && ($table->language != '*'))
			{
				// Adding new association for these items
				$key   = md5(json_encode($associations));
				$query = $db->getQuery(true)
					->insert('#__associations');

				foreach ($associations as $id)
				{
					$query->values($id . ',' . $db->quote($this->associationsContext) . ',' . $db->quote($key));
				}

				$db->setQuery($query);
				$db->execute();
			}
		}

	  return true;
	}

	/**
	 * Prepare and sanitise the table data prior to saving.
	 *
	 * @param   JTable  $table  A reference to a JTable object.
	 *
	 * @return  void
	 *
	 * @since   12.2
	 */
	protected function prepareTable($table)
	{
	  // Derived class will provide its own implementation if required.
	}
}
