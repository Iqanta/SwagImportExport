<?php

namespace Shopware\Components\SwagImportExport\DbAdapters;

use Shopware\Models\Customer\Customer;
use Shopware\Components\SwagImportExport\Utils\DataHelper;
use Shopware\Components\SwagImportExport\Utils\DbAdapterHelper;
use \Shopware\Components\SwagImportExport\Utils\SnippetsHelper as SnippetsHelper;
use Shopware\Components\SwagImportExport\Exception\AdapterException;

class CustomerDbAdapter implements DataDbAdapter
{

    /**
     * Shopware\Components\Model\ModelManager
     */
    protected $manager;
    protected $repository;
    protected $customerMap;
    protected $billingMap;
    protected $shippingMap;

    /**
     * @var array
     */
    protected $unprocessedData;

    /**
     * @var array
     */
    protected $logMessages;

    public function getDefaultColumns()
    {
        $default = array();

        $default = array_merge($default, $this->getCustomerColumns());
        
        $default = array_merge($default, $this->getBillingColumns());

        $default = array_merge($default, $this->getShippingColumns());
        
        return $default;
    }

    public function getCustomerColumns()
    {
        $columns = array(
            'customer.id as id',
            'customer.hashPassword as password',
            'unhashedPassword',
            'customer.encoderName as encoder',
            'customer.email as email',
            'customer.active as active',
            'customer.accountMode as accountMode',
            'customer.paymentId as paymentID',
            'customer.firstLogin as firstLogin',
            'customer.lastLogin as lastLogin',
            'customer.sessionId as sessionId',
            'customer.newsletter as newsletter',
            'customer.validation as validation',
            'customer.affiliate as affiliate',
            'customer.groupKey as customergroup',
            'customer.paymentPreset as paymentPreset',
            'customer.languageId as language',
            'customer.shopId as subshopID',
            'customer.referer as referer',
            'customer.priceGroupId as priceGroupId',
            'customer.internalComment as internalComment',
            'customer.failedLogins as failedLogins',
            'customer.lockedUntil as lockedUntil',
        );
        
        // Attributes
        $attributes = $this->getAttributesByTableName('s_user_attributes');

        $attributesSelect = '';
        if ($attributes) {
            $prefix = 'attribute';
            $attributesSelect = array();
            foreach ($attributes as $attribute) {
                if ($attribute === 'userID') {
                    continue;
                }
                //underscore to camel case
                //exmaple: underscore_to_camel_case -> underscoreToCamelCase
                $catAttr = preg_replace("/\_(.)/e", "strtoupper('\\1')", $attribute);

                $attributesSelect[] = sprintf('%s.%s as attrCustomer%s', $prefix, $catAttr, ucwords($catAttr));
            }
        }
        
        if ($attributesSelect && !empty($attributesSelect)) {
            $columns = array_merge($columns, $attributesSelect);
        }
        
        return $columns;
    }

    public function getUnprocessedData()
    {
        return $this->unprocessedData;
    }

    public function getBillingColumns()
    {
        $columns = array(
            'billing.company as billingCompany',
            'billing.department as billingDepartment',
            'billing.salutation as billingSalutation',
            'billing.number as customerNumber',
            'billing.firstName as billingFirstname',
            'billing.lastName as billingLastname',
            'billing.street as billingStreet',
            'billing.zipCode as billingZipcode',
            'billing.city as billingCity',
            'billing.phone as billingPhone',
            'billing.fax as billingFax',
            'billing.countryId as billingCountryID',
            'billing.stateId as billingStateID',
            'billing.vatId as ustid',
            'billing.birthday as birthday',
            'billing.additionalAddressLine1 as billingAdditionalAddressLine1',
            'billing.additionalAddressLine2 as billingAdditionalAddressLine2',
        );

        // Attributes
        $attributes = $this->getAttributesByTableName('s_user_billingaddress_attributes');

        $attributesSelect = '';
        if ($attributes) {
            $prefix = 'billingAttribute';
            $attributesSelect = array();
            foreach ($attributes as $attribute) {
                if ($attribute === 'billingID') {
                    continue;
                }
                //underscore to camel case
                //exmaple: underscore_to_camel_case -> underscoreToCamelCase
                $catAttr = preg_replace("/\_(.)/e", "strtoupper('\\1')", $attribute);

                $attributesSelect[] = sprintf('%s.%s as attrBilling%s', $prefix, $catAttr, ucwords($catAttr));
            }
        }
        
        if ($attributesSelect && !empty($attributesSelect)) {
            $columns = array_merge($columns, $attributesSelect);
        }

        return $columns;
    }

    public function getShippingColumns()
    {
        $columns = array(
            'shipping.company as shippingCompany',
            'shipping.department as shippingDepartment',
            'shipping.salutation as shippingSalutation',
            'shipping.firstName as shippingFirstname',
            'shipping.lastName as shippingLastname',
            'shipping.street as shippingStreet',
            'shipping.zipCode as shippingZipcode',
            'shipping.city as shippingCity',
            'shipping.countryId as shippingCountryID',
            'shipping.stateId as shippingStateID',
            'shipping.additionalAddressLine1 as shippingAdditionalAddressLine1',
            'shipping.additionalAddressLine2 as shippingAdditionalAddressLine2',
        );

        // Attributes
        $attributes = $this->getAttributesByTableName('s_user_shippingaddress_attributes');

        $attributesSelect = '';
        if ($attributes) {
            $prefix = 'shippingAttribute';
            $attributesSelect = array();
            
            foreach ($attributes as $attribute) {
                if ($attribute === 'shippingID') {
                    continue;
                }
                //underscore to camel case
                //exmaple: underscore_to_camel_case -> underscoreToCamelCase
                $catAttr = preg_replace("/\_(.)/e", "strtoupper('\\1')", $attribute);

                $attributesSelect[] = sprintf('%s.%s as attrShipping%s', $prefix, $catAttr, ucwords($catAttr));
            }
        }

        if ($attributesSelect && !empty($attributesSelect)) {
            $columns = array_merge($columns, $attributesSelect);
        }
        
        return $columns;
    }

    public function read($ids, $columns)
    {
        $manager = $this->getManager();
        
        foreach ($columns as $key => $value) {
            if ($value == 'unhashedPassword') {
                unset($columns[$key]);
            }
        }
        
        $builder = $this->getBuilder($columns, $ids);
        $query = $builder->getQuery();

        $query->setHydrationMode(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

        $paginator = $manager->createPaginator($query);

        $customers = $paginator->getIterator()->getArrayCopy();
        
        $result['default'] = DbAdapterHelper::decodeHtmlEntities($customers);

        return $result;
    }

    public function readRecordIds($start, $limit, $filter)
    {
        $manager = $this->getManager();

        $builder = $manager->createQueryBuilder();

        $builder->select('customer.id')
                ->from('\Shopware\Models\Customer\Customer', 'customer');

        $builder->setFirstResult($start)
                ->setMaxResults($limit);

        if (!empty($filter)) {
            $builder->addFilter($filter);
        }

        $records = $builder->getQuery()->getResult();

        $result = array();
        if ($records) {
            foreach ($records as $value) {
                $result[] = $value['id'];
            }
        }

        return $result;
    }

    public function write($records)
    {
        $records = Shopware()->Events()->filter(
                'Shopware_Components_SwagImportExport_DbAdapters_CustomerDbAdapter_Write',
                $records,
                array('subject' => $this)
        );

        $manager = $this->getManager();
        $passwordManager = Shopware()->PasswordEncoder();
        $db = Shopware()->Db();

        foreach ($records['default'] as $record) {
            try {

                $customer = null;

                if (isset($record['id']) && !empty($record['id'])) {
                    $customer = $this->getRepository()->findOneBy(array('id' => $record['id']));
                }

                if (!$customer) {
                    if (!isset($record['email']) || empty($record['email'])) {
                        $message = SnippetsHelper::getNamespace()
                            ->get('adapters/customer/email_required', 'User email is required field.');
                        throw new AdapterException($message);
                    }

                    $accountMode = (int) (isset($record['accountMode']) ? $record['accountMode'] : 1);

                    $filter = array('email' => $record['email'], 'accountMode' => $accountMode);

                    if (isset($record['subshopID']) && !empty($record['subshopID'])){
                        $filter['shopId'] = $record['subshopID'];
                    }

                    $customer = $this->getRepository()->findBy($filter);

                    //checks for multiple email address
                    if (count($customer) > 1) {
                        $message = SnippetsHelper::getNamespace()
                            ->get('adapters/customer/multiple_email', 'There are existing email address/es with %s. Please provide subshopID');
                        throw new AdapterException(sprintf($message, $record['email']));
                    }

                    $customer = $customer[0];
                }

                //set new password
                if (isset($record['unhashedPassword']) && !empty($record['unhashedPassword'])
                    && (!isset($record['password']) || empty($record['password']))) {

                    if (!isset($record['encoder']) || empty($record['encoder'])) {
                        $record['encoder'] = $passwordManager->getDefaultPasswordEncoderName();
                    }

                    $encoder = $passwordManager->getEncoderByName($record['encoder']);

                    $record['password'] = $encoder->encodePassword($record['unhashedPassword']);

                    unset($record['unhashedPassword']);
                }

                if ($customer) {
                    //if password is provided the encoder also must be set
                    if (($record['password'] && !$record['encoder']) || (!$record['password'] && $record['encoder'])){
                        $message = SnippetsHelper::getNamespace()
                            ->get('adapters/customer/password_and_encoder_required', 'Password and encoder must be provided for email %s');
                        throw new AdapterException(sprintf($message, $record['email']));
                    }
                } else {
                    $this->checkRequiredFields($record);
                    $customer = new Customer();
                }

                if (!isset($record['active']) || $record['active'] === '') {
                    $record['active'] = 1;
                }

                if (!isset($record['paymentID']) || $record['paymentID'] === '') {
                    $paymentId = $this->preparePayment($record['subshopID']);
                    $record['paymentID'] = $paymentId;
                }

                $customerData = $this->prepareCustomer($record);

                $customerData['billing'] = $this->prepareBilling($record);

                $customerData['shipping'] = $this->prepareShipping($record, $customer->getId(), $customerData['billing']);

                $customer->fromArray($customerData);

                $violations = $this->getManager()->validate($customer);

                if ($violations->count() > 0) {
                    $message = SnippetsHelper::getNamespace()
                                    ->get('adapters/customer/no_valid_customer_entity', 'No valid user entity for email %s');
                    throw new AdapterException(sprintf($message, $record['email']));
                }

                $manager->persist($customer);
                $manager->flush();

                if (isset($customerData['encoderName']) && $customerData['encoderName']) {
                    $customerId = $customer->getId();

                    $data['encoder'] = lcfirst($customerData['encoderName']);
                    $whereUser = array('id=' . $customerId);
                    $db->update('s_user', $data, $whereUser);
                }

                $manager->clear();
            } catch (AdapterException $e) {
                $message = $e->getMessage();
                $this->saveMessage($message);
            }
        }
    }

    protected function prepareCustomer(&$record)
    {
        if ($this->customerMap === null) {
            $columns = $this->getCustomerColumns();

            foreach ($columns as $column) {

                $map = DataHelper::generateMappingFromColumns($column);
                $this->customerMap[$map[0]] = $map[1];
            }
        }

        $customerData = array();

        $shopId = isset($record['subshopID']) && !empty($record['subshopID']) ? $record['subshopID'] : 1;
        $shop = $this->getManager()->find('Shopware\Models\Shop\Shop', $shopId);

        if(!$shop){
            $message = SnippetsHelper::getNamespace()
                ->get('adapters/shop_not_found', 'Shop with id %s was not found');
            throw new AdapterException(sprintf($message, $shopId));
        }

        $customerData['shop'] = $shop;

        foreach ($record as $key => $value) {
            if (preg_match('/^attrCustomer/', $key)) {
                $newKey = lcfirst(preg_replace('/^attrCustomer/', '', $key));
                $customerData['attribute'][$newKey] = $value;
                unset($record[$key]);
            } else if (isset($this->customerMap[$key])) {
                $customerData[$this->customerMap[$key]] = $value;
                unset($record[$key]);
            }
        }

        if (isset($customerData['groupKey']) && !empty($customerData['groupKey'])) {
            $customerData['group'] = Shopware()->Models()
                    ->getRepository('Shopware\Models\Customer\Group')
                    ->findOneBy(array('key' => $customerData['groupKey']));
            if (!$customerData['group']) {
                $message = SnippetsHelper::getNamespace()
                    ->get('adapters/customerGroup_not_found', 'Customer Group by key %s not found');
                throw new \Exception(sprintf($message, $customerData['groupKey']));
            }
        }

        if (isset($customerData['hashPassword']) && !empty($customerData['hashPassword'])){
            $customerData['rawPassword'] = $customerData['hashPassword'];
        }

        unset($record['hashPassword']);

        return $customerData;
    }

    protected function prepareBilling(&$record)
    {
        if ($this->billingMap === null) {
            $columns = $this->getBillingColumns();

            foreach ($columns as $column) {

                $map = DataHelper::generateMappingFromColumns($column);
                $this->billingMap[$map[0]] = $map[1];
            }
        }

        $billingData = array();

        foreach ($record as $key => $value) {
            //prepares the attributes
            if (preg_match('/^attrBilling/', $key)) {
                $newKey = lcfirst(preg_replace('/^attrBilling/', '', $key));
                $billingData['attribute'][$newKey] = $value;
                unset($record[$key]);
            } else if (isset($this->billingMap[$key])) {
                $billingData[$this->billingMap[$key]] = $value;
                unset($record[$key]);
            }
        }

        return $billingData;
    }

    protected function prepareShipping(&$record, $customerId, $billing)
    {
        if ($this->shippingMap === null) {
            $columns = $this->getShippingColumns();

            foreach ($columns as $column) {

                $map = DataHelper::generateMappingFromColumns($column);
                $this->shippingMap[$map[0]] = $map[1];
            }
        }

        $shippingData = array();

        //use shipping as billing
        if (empty($customerId) && empty($record['shippingFirstname']) && empty($params['shippingLastname'])) {
            foreach ($this->shippingMap as $mapKey => $addressKey) {
                if (isset($record[$mapKey])) {
                    $shippingData[$addressKey] = $billing[$addressKey];
                    unset($record[$mapKey]);
                }
            }

            return $shippingData;
        }

        foreach ($record as $key => $value) {
            //prepares the attributes
            if (preg_match('/^attrShipping/', $key)) {
                $newKey = lcfirst(preg_replace('/^attrShipping/', '', $key));
                $shippingData['attribute'][$newKey] = $value;
                unset($record[$key]);
            } else if (isset($this->shippingMap[$key])) {
                $shippingData[$this->shippingMap[$key]] = $value;
                unset($record[$key]);
            }
        }

        return $shippingData;
    }
    
    protected function preparePayment($subShopID)
    {
        //on missing shopId return defaultPaymentId
        if (!isset($subShopID) || $subShopID === '') {
            return Shopware()->Config()->get('sDEFAULTPAYMENT');
        }
        
        //get defaultPaymentId for subShiopId = $subShopID
        $defaultPaymentId = $this->getSubShopDefaultPaymentId($subShopID);
        if ($defaultPaymentId) {
            return unserialize($defaultPaymentId['value']);
        }
        
        //get defaultPaymentId for mainShiopId
        $defaultPaymentId = $this->getMainShopDefaultPaymentId($subShopID);
        if ($defaultPaymentId) {
            return unserialize($defaultPaymentId['value']);
        }
        return Shopware()->Config()->get('sDEFAULTPAYMENT');
    }
    
    protected function getSubShopDefaultPaymentId($subShopID)
    {
        $query =  "SELECT value.value
                   FROM s_core_config_elements AS element
                   JOIN s_core_config_values AS value ON value.element_id = element.id
                   WHERE value.shop_id = ?
                         AND element.name = ?";
        
        return Shopware()->Db()->fetchRow($query, array($subShopID, 'defaultpayment'));
    }
    
    protected function getMainShopDefaultPaymentId($subShopID)
    {
        $query =  "SELECT value.value
                   FROM s_core_config_elements AS element
                   JOIN s_core_config_values AS value ON value.element_id = element.id
                   WHERE value.shop_id = (SELECT main_id FROM s_core_shops WHERE id = ?)
                         AND element.name = ?";
        
        return Shopware()->Db()->fetchRow($query, array($subShopID, 'defaultpayment'));
    }

    public function saveMessage($message)
    {
        $errorMode = Shopware()->Config()->get('SwagImportExportErrorMode');

        if ($errorMode === false) {
            throw new \Exception($message);
        }

        $this->setLogMessages($message);
    }

    public function getLogMessages()
    {
        return $this->logMessages;
    }

    public function setLogMessages($logMessages)
    {
        $this->logMessages[] = $logMessages;
    }
    
    /**
     * @return array
     */
    public function getSections()
    {
        return array(
            array('id' => 'default', 'name' => 'default ')
        );
    }
    
    public function getAttributesByTableName($tableName)
    {
        $stmt = Shopware()->Db()->query("SHOW COLUMNS FROM $tableName");
        $columns = $stmt->fetchAll();

        $columnNames = array();
        foreach ($columns as $column) {
            if ($column['Field'] !== 'id') {
                $columnNames[] = $column['Field'];
            }
        }

        return $columnNames;
    }

    /**
     * @param string $section
     * @return mix
     */
    public function getColumns($section)
    {
        $method = 'get' . ucfirst($section) . 'Columns';
        
        if (method_exists($this, $method)) {
            return $this->{$method}();
        }

        return false;
    }

    /**
     * Returns category repository
     * 
     * @return Shopware\Models\Customer\Customer
     */
    public function getRepository()
    {
        if ($this->repository === null) {
            $this->repository = $this->getManager()->getRepository('Shopware\Models\Customer\Customer');
        }
        return $this->repository;
    }

    /**
     * Returns entity manager
     * 
     * @return Shopware\Components\Model\ModelManager
     */
    public function getManager()
    {
        if ($this->manager === null) {
            $this->manager = Shopware()->Models();
        }

        return $this->manager;
    }

    public function getBuilder($columns, $ids)
    {
        $builder = $this->getManager()->createQueryBuilder();
        $builder->select($columns)
                ->from('\Shopware\Models\Customer\Customer', 'customer')
                ->join('customer.billing', 'billing')
                ->leftJoin('customer.shipping', 'shipping')
                ->leftJoin('customer.orders', 'orders', 'WITH', 'orders.status <> -1 AND orders.status <> 4')
                ->leftJoin('billing.attribute', 'billingAttribute')
                ->leftJoin('shipping.attribute', 'shippingAttribute')
                ->leftJoin('customer.attribute', 'attribute')
                ->groupBy('customer.id')
                ->where('customer.id IN (:ids)')
                ->setParameter('ids', $ids);

        return $builder;
    }

    protected function checkRequiredFields($record)
    {
        $requiredFields = array(
            'password' => 'Password must be provided for email %s',
            'encoder' => 'To create a new user with email: %s, unhashedPassword must be provided and password must be empty.',
            'customergroup' => 'Customer group must be provided for user with email: %s.',
            'billingSalutation' => 'Billing salutation must be provided for user with email: %s.',
            'billingFirstname' => 'Billing first name must be provided for user with email: %s.',
            'billingLastname' => 'Billing last name must be provided for user with email: %s.',
            'billingStreet' => 'Billing street must be provided for user with email: %s.',
            'billingStreetnumber' => 'Billing street number must be provided for user with email: %s.',
            'billingZipcode' => 'Billing zip code must be provided for user with email: %s.',
            'billingCity' => 'Billing city must be provided for user with email: %s.',
            'billingCountryID' => 'Billing countryId must be provided for user with email: %s.'
        );

        foreach ($requiredFields as $requiredField => $snippet) {
            if (!isset($record[$requiredField]) || empty($record[$requiredField])) {
                $message = SnippetsHelper::getNamespace()
                    ->get("adapters/customer/{$requiredField}_required", $snippet);
                throw new AdapterException(sprintf($message, $record['email']));
            }
        }
    }
}
