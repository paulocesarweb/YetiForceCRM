<?php

/**
 * Synchronize orders.
 *
 * @package Integration
 *
 * @copyright YetiForce Sp. z o.o
 * @license   YetiForce Public License 3.0 (licenses/LicenseEN.txt or yetiforce.com)
 * @author    Arkadiusz Dudek <a.dudek@yetiforce.com>
 * @author    Mariusz Krzaczkowski <m.krzaczkowski@yetiforce.com>
 */

namespace App\Integrations\Magento\Synchronizator;

/**
 * Category class.
 */
class Order extends Record
{
	/**
	 * {@inheritdoc}
	 */
	public function process()
	{
		$this->lastScan = $this->config->getLastScan('order');
		if (!$this->lastScan['start_date'] || (0 === (int) $this->lastScan['id'] && $this->lastScan['start_date'] === $this->lastScan['end_date'])) {
			$this->config->setScan('order');
			$this->lastScan = $this->config->getLastScan('order');
		}
		if ($this->import()) {
			$this->config->setEndScan('order', $this->lastScan['start_date']);
		}
	}

	/**
	 * Import orders from magento.
	 *
	 * @return bool
	 */
	public function import(): bool
	{
		$allChecked = false;
		try {
			if ($orders = $this->getOrdersFromApi()) {
				foreach ($orders as $id => $order) {
					if (empty($order)) {
						\App\Log::error('Empty order details', 'Integrations/Magento');
						continue;
					}
					$className = $this->config->get('order_map_class') ?: '\App\Integrations\Magento\Synchronizator\Maps\Order';
					$mapModel = new $className($this);
					$mapModel->setData($order);
					$dataCrm = $mapModel->getDataCrm();
					if ($dataCrm) {
						try {
							if ($crmId = $mapModel->getCrmId($order['entity_id'])) {
								$this->updateOrderInCrm($crmId, $mapModel);
							} else {
								$parentOrder = $dataCrm['parent_id'];
								$dataCrm['parent_id'] = $this->syncAccount($dataCrm);
								$dataCrm['contactid'] = $this->syncContact($dataCrm);
								$dataCrm['accountid'] = $dataCrm['parent_id'];
								$dataCrm['parent_id'] = $parentOrder;
								unset($dataCrm['birthday'],$dataCrm['leadsource'],$dataCrm['mobile'],$dataCrm['mobile_extra'],$dataCrm['phone'],$dataCrm['phone_extra'],$dataCrm['salutationtype']);
								$dataCrm['magento_id'] = $order['entity_id'];
								$mapModel->setDataCrm($dataCrm);
								$this->createOrderInCrm($mapModel);
							}
						} catch (\Throwable $ex) {
							\App\Log::error('Error during saving customer: ' . PHP_EOL . $ex->__toString() . PHP_EOL, 'Integrations/Magento');
						}
					} else {
						\App\Log::error('Empty map customer details', 'Integrations/Magento');
					}
					$this->config->setScan('order', 'id', $id);
				}
			} else {
				$allChecked = true;
			}
		} catch (\Throwable $ex) {
			\App\Log::error('Error during import customer: ' . PHP_EOL . $ex->__toString() . PHP_EOL, 'Integrations/Magento');
		}
		return $allChecked;
	}

	/**
	 * Method to get orders form Magento.
	 *
	 * @param array $ids
	 *
	 * @throws \App\Exceptions\AppException
	 * @throws \ReflectionException
	 *
	 * @return array
	 */
	public function getOrdersFromApi(array $ids = []): array
	{
		$items = [];
		$data = \App\Json::decode($this->connector->request('GET', $this->config->get('store_code') . '/V1/orders?' . $this->getSearchCriteria($ids, $this->config->get('orderLimit'))));
		if (!empty($data['items'])) {
			foreach ($data['items'] as $item) {
				$items[$item['entity_id']] = $item;
			}
		}
		return $items;
	}

	/**
	 * Create order in crm.
	 *
	 * @param \App\Integrations\Magento\Synchronizator\Maps\Inventory $mapModel
	 *
	 * @return mixed|int
	 */
	public function createOrderInCrm(Maps\Inventory $mapModel)
	{
		$recordModel = \Vtiger_Record_Model::getCleanInstance('SSingleOrders');
		if ($this->config->get('storage_id')) {
			$recordModel->set('istorageaddressid', $this->config->get('storage_id'));
		}
		$recordModel->set('magento_server_id', $this->config->get('id'));
		$fields = $recordModel->getModule()->getFields();
		foreach ($mapModel->dataCrm as $key => $value) {
			if (isset($fields[$key])) {
				$recordModel->set($key, $value);
			}
		}
		if (!$this->saveInventoryCrm($recordModel, $mapModel)) {
			\App\Log::error('Error during parse inventory order id: [' . $mapModel->data['entity_id'] . ']', 'Integrations/Magento');
		}
		$recordModel->save();
		return $recordModel->getId();
	}

	/**
	 * Method to update order in YetiForce.
	 *
	 * @param int                                                     $id
	 * @param \App\Integrations\Magento\Synchronizator\Maps\Inventory $mapModel
	 *
	 * @throws \Exception
	 */
	public function updateOrderInCrm(int $id, Maps\Inventory $mapModel): void
	{
		try {
			$recordModel = \Vtiger_Record_Model::getInstanceById($id, 'SSingleOrders');
			$fields = $recordModel->getModule()->getFields();
			foreach ($mapModel->getDataCrm(true) as $key => $value) {
				if (isset($fields[$key])) {
					$recordModel->set($key, $value);
				}
			}
			//$recordModel->save();
		} catch (\Throwable $ex) {
			\App\Log::error('Error during updating yetiforce order: (magento id: [' . $mapModel->data['entity_id'] . '])' . $ex->getMessage(), 'Integrations/Magento');
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function getSearchCriteria($ids, int $pageSize = 10): string
	{
		$searchCriteria[] = parent::getSearchCriteria($ids, $pageSize);
		$searchCriteria[] = 'searchCriteria[filter_groups][3][filters][0][value]=' . $this->config->get('store_id');
		$searchCriteria[] = 'searchCriteria[filter_groups][3][filters][0][field]=store_id';
		$searchCriteria[] = 'searchCriteria[filter_groups][3][filters][0][conditionType]=eq';
		return implode('&', $searchCriteria);
	}
}
