<?php

/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Ebizmarts_MailChimp_Model_Api_Customers
{

    const BATCH_LIMIT = 500;
    const DEFAULT_OPT_IN = true;

    public function CreateBatchJson($mailchimpStoreId)
    {
        //create missing customers first
        $collection = mage::getModel('customer/customer')->getCollection()
            ->addAttributeToSelect('mailchimp_sync_delta')
            ->addAttributeToSelect('firstname')
            ->addAttributeToSelect('lastname')
            ->addAttributeToFilter(array(array('attribute' => 'mailchimp_sync_delta', 'null' => true), array('attribute' => 'mailchimp_sync_delta', 'eq' => '')), '', 'left');
        $collection->getSelect()->limit(self::BATCH_LIMIT);


        //if all synced, start updating old ones
        if ($collection->getSize() == 0) {
            $collection = mage::getModel('customer/customer')->getCollection()
                ->addAttributeToSelect('mailchimp_sync_delta')
                ->addAttributeToFilter(array(array('attribute' => 'mailchimp_sync_delta', 'lt' => new Zend_Db_Expr('updated_at'))), '', 'left');
            $collection->getSelect()->limit(self::BATCH_LIMIT);
        }

        $batchJson = "";
        $operationsCount = 0;
        $batchId = Ebizmarts_MailChimp_Model_Config::IS_CUSTOMER . '_' . date('Y-m-d-H-i-s');

        foreach ($collection as $customer) {
            $data = $this->_buildCustomerData($customer);
            $customerJson = "";

            //enconde to JSON
            try {
                $customerJson = json_encode($data);

            } catch (Exception $e) {
                //json encode failed
                Mage::helper('mailchimp')->log("Customer ".$customer->getId()." json encode failed");
            }

            if (!empty($customerJson)) {
                $operationsCount += 1;
                if ($operationsCount > 1) {
                    $batchJson .= ',';
                }
                $batchJson .= '{"method": "POST",';
                $batchJson .= '"path": "/ecommerce/stores/' . $mailchimpStoreId . '/customers",';
                $batchJson .= '"operation_id": "' . $batchId . '_' . $customer->getId() . '",';
                $batchJson .= '"body": "' . addcslashes($customerJson, '"') . '"';
                $batchJson .= '}';

                //update customers delta
                $customer->setData("mailchimp_sync_delta", Varien_Date::now());
                $customer->setData("mailchimp_sync_error", "");
                $customer->save();
            }
        }
        return $batchJson;
    }

    protected function _buildCustomerData($customer)
    {
        $data = array();
        $data["id"] = $customer->getId();
        $data["email_address"] = $customer->getEmail();
        $data["first_name"] = $customer->getFirstname();
        $data["last_name"] = $customer->getLastname();
        $data["opt_in_status"] = self::DEFAULT_OPT_IN;

        //customer orders data
        $orderCollection = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('status', 'complete')
            ->addAttributeToFilter('customer_id', array('eq' => $customer->getId()));
        $totalOrders = 0;
        $totalAmountSpent = 0;
        foreach ($orderCollection as $order) {
            $totalOrders += 1;
            $totalAmountSpent += (int)$order->getGrandTotal();
        }
        $data["orders_count"] = $totalOrders;
        $data["total_spent"] = $totalAmountSpent;

        //addresses data
        foreach ($customer->getAddresses() as $address) {
            if (!array_key_exists("address", $data)) //send only first address
            {
                $data["address"] = [
                    "address1" => $address->getStreet()[0],
                    "address2" => $address->getStreet()[1] ? $address->getStreet()[1] : "",
                    "city" => $address->getCity(),
                    "province" => $address->getRegion() ? $address->getRegion() : "",
                    "province_code" => $address->getRegionCode() ? $address->getRegionCode() : "",
                    "postal_code" => $address->getPostcode(),
                    "country" => Mage::getModel('directory/country')->loadByCode($address->getCountry())->getName(),
                    "country_code" => $address->getCountry()
                ];

                //company
                if ($address->getCompany()) {
                    $data["company"] = $address->getCompany();
                }
                break;
            }
        }

        return $data;
    }

    public function Update($customer)
    {
        try {

            $apiKey = Mage::helper('mailchimp')->getConfigValue(Ebizmarts_MailChimp_Model_Config::GENERAL_APIKEY);
            if ($apiKey) {
                $mailchimpStoreId = Mage::helper('mailchimp')->getStoreId();

                $data = $this->_buildCustomerData($customer);

                $mailchimpApi = new Ebizmarts_Mailchimp($apiKey);
                $mailchimpApi->ecommerce->customers->modify(
                    $mailchimpStoreId,
                    $data["id"],
                    $data["opt_in_status"],
                    $data["company"],
                    $data["first_name"],
                    $data["last_name"],
                    $data["orders_count"],
                    $data["total_spent"],
                    $data["address"]
                );

                //update customers delta
                $customer->setData("mailchimp_sync_delta", Varien_Date::now());
                $customer->setData("mailchimp_sync_error", "");
                $customer->save();

            }else{
                throw new Mailchimp_Error('You must provide a MailChimp API key');
            }
        } catch (Mailchimp_Error $e)
        {
            Mage::helper('mailchimp')->log($e->getFriendlyMessage());

            //update customers delta
            $customer->setData("mailchimp_sync_delta", Varien_Date::now());
            $customer->setData("mailchimp_sync_error", $e->getFriendlyMessage());
            $customer->save();

        } catch (Exception $e)
        {
            Mage::helper('mailchimp')->log($e->getMessage());

            //update customers delta
            $customer->setData("mailchimp_sync_delta", Varien_Date::now());
            $customer->setData("mailchimp_sync_error", $e->getMessage());
            $customer->save();
        }
    }
}