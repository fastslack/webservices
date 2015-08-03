<?php
/**
 * @package     Redcore.Backend
 * @subpackage  Controllers
 *
 * @copyright   Copyright (C) 2008 - 2015 redCOMPONENT.com. All rights reserved.
 * @license     GNU General Public License version 2 or later, see LICENSE.
 */

defined('_JEXEC') or die;

/**
 * Webservice Controller
 *
 * @package     Redcore.Backend
 * @subpackage  Controllers
 * @since       1.4
 */
class WebservicesControllerWebservice extends JControllerForm
{
	/**
	 * Method to get new Task HTML
	 *
	 * @return  void
	 */
	public function ajaxGetTask()
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		$taskName = $input->getString('taskName', '');
		$model = $this->getModel();
		$model->formData['task-' . $taskName] = $model->bindPathToArray('//operations/taskResources', $model->defaultXmlFile);
		$model->setFieldsAndResources('task-' . $taskName, '//operations/taskResources', $model->defaultXmlFile);

		if (!empty($taskName))
		{
			echo JLayoutHelper::render(
				'webservice.operation',
				array(
					'view' => $model,
					'options' => array(
						'operation' => 'task-' . $taskName,
						'form'      => $model->getForm($model->formData, false),
						'tabActive' => ' active in ',
						'fieldList' => array('defaultValue', 'isRequiredField', 'isPrimaryField'),
					)
				)
			);
		}

		$app->close();
	}

	/**
	 * Method to get new Field HTML
	 *
	 * @return  void
	 */
	public function ajaxGetField()
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		$operation = $input->getString('operation', 'read');
		$fieldList = $input->getString('fieldList', '');
		$fieldList = explode(',', $fieldList);

		echo JLayoutHelper::render(
			'webservice.fields.field',
			array(
				'view' => $this,
				'options' => array(
					'operation' => $operation,
					'fieldList' => $fieldList,
				)
			)
		);

		$app->close();
	}

	/**
	 * Method to get new Fields from Database Table in HTML
	 *
	 * @return  void
	 */
	public function ajaxGetFieldFromDatabase()
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		$operation = $input->getString('operation', 'read');
		$fieldList = $input->getString('fieldList', '');
		$fieldList = explode(',', $fieldList);
		$tableName = $input->getCmd('tableName', '');

		if (!empty($tableName))
		{
			$db = JFactory::getDbo();
			$columns = $db->getTableColumns('#__' . $tableName, false);

			if ($columns)
			{
				foreach ($columns as $columnKey => $column)
				{
					$form = array(
						'name' => $column->Field,
						'transform' => WebservicesHelper::getTransformElementByDbType($column->Type),
						'defaultValue' => $column->Default,
						'isPrimaryField' => $column->Key == 'PRI' ? 'true' : 'false',
						'description' => $column->Comment,
					);

					echo JLayoutHelper::render(
						'webservice.fields.field',
						array(
							'view' => $this,
							'options' => array(
								'operation' => $operation,
								'fieldList' => $fieldList,
								'form' => $form,
							)
						)
					);
				}
			}
		}

		$app->close();
	}

	/**
	 * Method to get new Resources from Database Table in HTML
	 *
	 * @return  void
	 */
	public function ajaxGetResourceFromDatabase()
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		$operation = $input->getString('operation', 'read');
		$fieldList = $input->getString('fieldList', '');
		$fieldList = explode(',', $fieldList);
		$tableName = $input->getCmd('tableName', '');

		if (!empty($tableName))
		{
			$db = JFactory::getDbo();
			$columns = $db->getTableColumns('#__' . $tableName, false);

			if ($columns)
			{
				foreach ($columns as $columnKey => $column)
				{
					$form = array(
						'displayName' => $column->Field,
						'transform' => WebservicesHelper::getTransformElementByDbType($column->Type),
						'resourceSpecific' => 'rcwsGlobal',
						'fieldFormat' => '{' . $column->Field . '}',
						'description' => $column->Comment,
					);

					echo JLayoutHelper::render(
						'webservice.resources.resource',
						array(
							'view' => $this,
							'options' => array(
								'operation' => $operation,
								'fieldList' => $fieldList,
								'form' => $form,
							)
						)
					);
				}
			}
		}

		$app->close();
	}

	/**
	 * Method to get new Field HTML
	 *
	 * @return  void
	 */
	public function ajaxGetConnectWebservice()
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		$operation = $input->getString('operation', 'read');
		$fieldList = $input->getString('fieldList', '');
		$webserviceId = $input->getString('webserviceId', '');

		if (!empty($webserviceId))
		{
			$model = $this->getModel();
			$item = $model->getItem($webserviceId);

			$link = '/index.php?option=' . $item->name;
			$link .= '&amp;webserviceVersion=' . $item->version;
			$link .= '&amp;webserviceClient=' . $item->client;
			$link .= '&amp;id={' . $item->name . '_id}';

			$form = array(
				'displayName' => $item->name,
				'linkTitle' => $item->title,
				'transform' => 'string',
				'resourceSpecific' => 'rcwsGlobal',
				'displayGroup' => '_links',
				'linkTemplated' => 'true',
				'fieldFormat' => $link,
				'description' => JText::sprintf('COM_WEBSERVICES_WEBSERVICE_RESOURCE_ADD_CONNECTION_DESCRIPTION_LABEL', $item->name, '{' . $item->name . '_id}'),
			);

			echo JLayoutHelper::render(
				'webservice.resources.resource',
				array(
					'view' => $this,
					'options' => array(
						'operation' => $operation,
						'fieldList' => $fieldList,
						'form' => $form,
					)
				)
			);
		}

		$app->close();
	}

	/**
	 * Method to get new Field HTML
	 *
	 * @return  void
	 */
	public function ajaxGetResource()
	{
		$app = JFactory::getApplication();
		$input = $app->input;

		$operation = $input->getString('operation', 'read');
		$fieldList = $input->getString('fieldList', '');

		echo JLayoutHelper::render(
			'webservice.resources.resource',
			array(
				'view' => $this,
				'options' => array(
					'operation' => $operation,
					'fieldList' => $fieldList,
				)
			)
		);

		$app->close();
	}
}
