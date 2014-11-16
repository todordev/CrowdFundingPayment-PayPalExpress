<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('crowdfunding.payment.plugin');

/**
 * CrowdFunding PayPal Express payment plugin.
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 */
class plgCrowdFundingPaymentPayPalExpress extends CrowdFundingPaymentPlugin
{
    protected $paymentService = "paypal";

    protected $textPrefix = "PLG_CROWDFUNDINGPAYMENT_PAYPALEXPRESS";
    protected $debugType = "PAYPALEXPRESS_PAYMENT_PLUGIN_DEBUG";

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param object                   $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payment", $context) != 0) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/paypalexpress";

        $html   = array();
        $html[] = '<h4><img src="' . $pluginURI . '/images/paypal_icon.png" width="36" height="32" alt="PayPal" />' . JText::_($this->textPrefix . "_TITLE") . '</h4>';
        $html[] = '<p>' . JText::_($this->textPrefix . "_INFO") . '</p>';
        $html[] = '<div class="clearfix"> </div>';

        $html[] = '<form action="' . JRoute::_("index.php?option=com_crowdfunding&task=payments.checkout") . '" method="post">';

        $html[] = '<input type="hidden" name="payment_service" value="PayPal" />';
        $html[] = '<input type="hidden" name="pid" value="' . $item->id . '" />';
        $html[] = JHtml::_('form.token');

        $this->prepareLocale($html);

        $html[] = '<img alt="" border="0" width="1" height="1" src="https://www.paypal.com/en_US/i/scr/pixel.gif" >';
        $html[] = '</form>';

        if ($this->params->get('paypal_sandbox', 1)) {
            $html[] = '<p class="sticky">' . JText::_($this->textPrefix . "_WORKS_SANDBOX") . '</p>';
        }

        return implode("\n", $html);
    }

    /**
     * Process payment transaction.
     *
     * @param string $context
     * @param object $item
     * @param Joomla\Registry\Registry $params
     *
     * @return null|array
     */
    public function onPaymentsCheckout($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payments.checkout.paypal", $context) != 0) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        $output = array();

        $cancelUrl = $this->getCancelUrl($item->slug, $item->catslug);
        $returnUrl = $this->getDoCheckoutUrl($item->id);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_CANCEL_URL"), $this->debugType, $cancelUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $returnUrl) : null;

        // Get country and locale code.
        jimport("crowdfunding.country");
        $countryId = $this->params->get("paypal_country");

        $country = new CrowdFundingCountry(JFactory::getDbo());
        $country->load($countryId);

        $localeCode = $country->getCode4();

        // Create transport object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $options->set("urls.return", $returnUrl);
        $options->set("urls.cancel", $cancelUrl);

        $this->prepareCredentials($options);

        $options->set("locale.code", $localeCode);

        $options->set("style.logo_image", JString::trim($this->params->get("paypal_image_url")));

        $options->set("payment.action", "Order");
        $options->set("payment.amount", $item->amount);
        $options->set("payment.currency", $item->currency);

        $title = JText::sprintf($this->textPrefix . "_INVESTING_IN_S", htmlentities($item->title, ENT_QUOTES, "UTF-8"));
        $options->set("payment.description", $title);

        // Get intention.
        $userId  = JFactory::getUser()->get("id");
        $aUserId = $app->getUserState("auser_id");

        $intention = $this->getIntention(array(
            "user_id"    => $userId,
            "auser_id"   => $aUserId,
            "project_id" => $item->id
        ));

        // Prepare custom data
        $custom = array(
            "intention_id" => $intention->getId(),
            "gateway"      => "PayPal"
        );

        $custom = base64_encode(json_encode($custom));
        $options->set("payment.custom", $custom);

        // Get API url.
        $apiUrl = $this->getApiUrl();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_EXPRESS_CHECKOUT_OPTIONS"), $this->debugType, $options->toArray()) : null;

        jimport("itprism.payment.paypal.express");
        $express = new ITPrismPayPalExpress($apiUrl, $options);

        $express->setTransport($http);

        $response = $express->setExpressCheckout();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_EXPRESS_CHECKOUT_RESPONSE"), $this->debugType, $response) : null;

        $token = JArrayHelper::getValue($response, "TOKEN");
        if (!$token) {
            return null;
        }

        // Store token to the intention.
        $intention->setUniqueKey($token);
        $intention->store();

        // Get PayPal checkout URL.
        if ($this->params->get('paypal_sandbox', 1)) {
            $output["redirect_url"] = $this->params->get("paypal_sandbox_url", "https://www.sandbox.paypal.com/cgi-bin/webscr") . "?cmd=_express-checkout&amp;useraction=commit&token=" . rawurlencode($token);
        } else {
            $output["redirect_url"] = $this->params->get("paypal_url", "https://www.paypal.com/cgi-bin/webscr") . "?cmd=_express-checkout&amp;useraction=commit&token=" . rawurlencode($token);
        }

        return $output;
    }

    /**
     * Process payment transaction doing checkout.
     *
     * @param string $context
     * @param object $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsDoCheckout($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payments.docheckout.paypal", $context) != 0) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        $output  = array();
        $token   = $app->input->get("token");
        $payerId = $app->input->get("PayerID");

        // Load intention by token.
        $intention = $this->getIntention(array(
            "unique_key" => $token
        ));

        // Validate project ID and transaction.
        if ($item->id != $intention->getProjectId()) {
            return null;
        }

        $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);
        $notifyUrl = $this->getNotifyUrl();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $returnUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_NOTIFY_URL"), $this->debugType, $notifyUrl) : null;

        // Create transport object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $options->set("urls.notify", $notifyUrl);

        $this->prepareCredentials($options);

        $options->set("authorization.token", $token);
        $options->set("authorization.payer_id", $payerId);

        $options->set("payment.action", "Order");
        $options->set("payment.amount", number_format($item->amount, 2));
        $options->set("payment.currency", $item->currency);

        // Get API url.
        $apiUrl = $this->getApiUrl();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_DOCHECKOUT_OPTIONS"), $this->debugType, $options) : null;

        jimport("itprism.payment.paypal.express");
        $express = new ITPrismPayPalExpress($apiUrl, $options);

        $express->setTransport($http);

        $response = $express->doExpressCheckoutPayment();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_DOCHECKOUT_RESPONSE"), $this->debugType, $response) : null;

        $output["redirect_url"] = $returnUrl;

        return $output;
    }

    /**
     * Capture payments.
     *
     * @param string $context
     * @param object $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsCapture($context, &$item, &$params)
    {
        $allowedContext = array("com_crowdfunding.payments.capture.paypal", "com_crowdfundingfinance.payments.capture.paypal");
        if (!in_array($context, $allowedContext)) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationAdministrator */

        if (!$app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // Create transport object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $this->prepareCredentials($options);

        $options->set("payment.authorization_id", $item->txn_id);
        $options->set("payment.amount", number_format($item->txn_amount, 2));
        $options->set("payment.currency", $item->txn_currency);
        $options->set("payment.complete_type", "Complete");

        // Get API url.
        $apiUrl = $this->getApiUrl();

        try {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_DOCAPTURE_OPTIONS"), $this->debugType, $options) : null;

            jimport("itprism.payment.paypal.express");
            $express = new ITPrismPayPalExpress($apiUrl, $options);

            $express->setTransport($http);

            $response = $express->doCapture();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_DOCAPTURE_RESPONSE"), $this->debugType, $response) : null;

        } catch (Exception $e) {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_ERROR_DOCAPTURE"), $this->debugType, $e->getMessage()) : null;

            $message = array(
                "text" => JText::sprintf($this->textPrefix . "_CAPTURED_UNSUCCESSFULLY", $item->txn_id),
                "type" => "error"
            );

            return $message;

        }

        $message = array(
            "text" => JText::sprintf($this->textPrefix . "_CAPTURED_SUCCESSFULLY", $item->txn_id),
            "type" => "message"
        );

        return $message;
    }

    /**
     * Void payments.
     *
     * @param string $context
     * @param object $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsVoid($context, &$item, &$params)
    {
        $allowedContext = array("com_crowdfunding.payments.void.paypal", "com_crowdfundingfinance.payments.void.paypal");
        if (!in_array($context, $allowedContext)) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationAdministrator */

        if (!$app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // Create transport object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new JRegistry;
        /** @var  $options Joomla\Registry\Registry */

        $this->prepareCredentials($options);

        $options->set("payment.authorization_id", $item->txn_id);

        // Get API url.
        $apiUrl = $this->getApiUrl();

        try {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_DOVOID_OPTIONS"), $this->debugType, $options) : null;

            jimport("itprism.payment.paypal.express");
            $express = new ITPrismPayPalExpress($apiUrl, $options);

            $express->setTransport($http);

            $response = $express->doVoid();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_DOVOID_RESPONSE"), $this->debugType, $response) : null;

        } catch (Exception $e) {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_ERROR_DOVOID"), $this->debugType, $e->getMessage()) : null;

            $message = array(
                "text" => JText::sprintf($this->textPrefix . "_VOID_UNSUCCESSFULLY", $item->txn_id),
                "type" => "error"
            );

            return $message;

        }

        $message = array(
            "text" => JText::sprintf($this->textPrefix . "_VOID_SUCCESSFULLY", $item->txn_id),
            "type" => "message"
        );

        return $message;
    }

    /**
     * This method processes transaction data that comes from PayPal instant notifier.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.paypal", $context) != 0) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentRaw */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $app->input->getMethod();
        if (strcmp("POST", $requestMethod) != 0) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_REQUEST_METHOD"),
                $this->debugType,
                JText::sprintf($this->textPrefix . "_ERROR_INVALID_TRANSACTION_REQUEST_METHOD", $requestMethod)
            );

            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESPONSE"), $this->debugType, $_POST) : null;

        // Decode custom data
        $custom = JArrayHelper::getValue($_POST, "custom");
        $custom = json_decode(base64_decode($custom), true);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_CUSTOM"), $this->debugType, $custom) : null;

        // Validate payment services.
        if (!$this->isPayPalGateway($custom)) {
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PAYMENT_GATEWAY"),
                $this->debugType,
                array("custom" => $custom, "_POST" => $_POST)
            );

            return null;
        }

        // Get PayPal URL
        if ($this->params->get('paypal_sandbox', 1)) {
            $url = JString::trim($this->params->get('paypal_sandbox_url', "https://www.sandbox.paypal.com/cgi-bin/webscr"));
        } else {
            $url = JString::trim($this->params->get('paypal_url', "https://www.paypal.com/cgi-bin/webscr"));
        }

        jimport("itprism.payment.paypal.ipn");
        $paypalIPN       = new ITPrismPayPalIpn($url, $_POST);
        $loadCertificate = (bool)$this->params->get("paypal_load_certificate", 0);
        $paypalIPN->verify($loadCertificate);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_IPN_OBJECT"), $this->debugType, $paypalIPN) : null;

        // Prepare the array that will be returned by this method
        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "payment_service" => "PayPal"
        );

        if ($paypalIPN->isVerified()) {

            // Get currency
            jimport("crowdfunding.currency");
            $currencyId = $params->get("project_currency");
            $currency   = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId, $params);

            // Get intention data
            $intentionId = JArrayHelper::getValue($custom, "intention_id", 0, "int");

            jimport("crowdfunding.intention");
            $intention = new CrowdFundingIntention(JFactory::getDbo());
            $intention->load($intentionId);

            // Get payment session as intention.
            if (!$intention->getId()) {

                $keys = array("intention_id" => $intentionId);

                jimport("crowdfunding.payment.session");
                $intention = new CrowdFundingPaymentSession(JFactory::getDbo());
                $intention->load($keys);
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_INTENTION"), $this->debugType, $intention->getProperties()) : null;

            // Validate transaction data
            $validData = $this->validateData($_POST, $currency->getAbbr(), $intention);
            if (is_null($validData)) {
                return $result;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_VALID_DATA"), $this->debugType, $validData) : null;

            // Get project.
            jimport("crowdfunding.project");
            $projectId = JArrayHelper::getValue($validData, "project_id");
            $project   = CrowdFundingProject::getInstance(JFactory::getDbo(), $projectId);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

            // Check for valid project
            if (!$project->getId()) {

                // Log data in the database
                $this->log->add(
                    JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT"),
                    $this->debugType,
                    $validData
                );

                return $result;
            }

            // Set the receiver of funds
            $validData["receiver_id"] = $project->getUserId();

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transactionData = $this->storeTransaction($validData, $project);
            if (is_null($transactionData)) {
                return $result;
            }

            // Update the number of distributed reward.
            $rewardId = JArrayHelper::getValue($transactionData, "reward_id");
            $reward   = null;
            if (!empty($rewardId)) {
                $reward = $this->updateReward($transactionData);

                // Validate the reward.
                if (!$reward) {
                    $transactionData["reward_id"] = 0;
                }
            }


            //  Prepare the data that will be returned

            $result["transaction"] = JArrayHelper::toObject($transactionData);

            // Generate object of data based on the project properties
            $properties        = $project->getProperties();
            $result["project"] = JArrayHelper::toObject($properties);

            // Generate object of data based on the reward properties
            if (!empty($reward)) {
                $properties       = $reward->getProperties();
                $result["reward"] = JArrayHelper::toObject($properties);
            }

            // Generate data object, based on the intention properties.
            $properties       = $intention->getProperties();
            $result["payment_session"] = JArrayHelper::toObject($properties);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

            // Remove intention.
            $txnStatus = (isset($result["transaction"]->txn_status)) ? $result["transaction"]->txn_status : null;
            $this->removeIntention($intention, $txnStatus);
            unset($intention);

        } else {

            // Log error
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                $this->debugType,
                array("error message" => $paypalIPN->getError(), "paypalIPN" => $paypalIPN, "_POST" => $_POST)
            );

        }

        return $result;
    }

    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param string $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry $params Component parameters
     * @param object $project Project data
     * @param object $reward Reward data
     * @param object $paymentSession Payment session data.
     *
     * @return void
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify.paypal", $context) != 0) {
            return;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentRaw */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return;
        }

        // Send mails
        $this->sendMails($project, $transaction, $params);
    }

    /**
     * Validate PayPal transaction
     *
     * @param array  $data
     * @param string $currency
     * @param object  $intention
     *
     * @return array|null
     */
    protected function validateData($data, $currency, $intention)
    {
        $txnDate = JArrayHelper::getValue($data, "payment_date");
        $date    = new JDate($txnDate);

        // Get additional information from transaction.
        $extraData = $this->getExtraData($data);

        // Prepare transaction data
        $transaction = array(
            "investor_id"      => (int)$intention->getUserId(),
            "project_id"       => (int)$intention->getProjectId(),
            "reward_id"        => ($intention->isAnonymous()) ? 0 : (int)$intention->getRewardId(),
            "service_provider" => "PayPal",
            "txn_id"           => JArrayHelper::getValue($data, "txn_id", "", "string"),
            "parent_txn_id"    => JArrayHelper::getValue($data, "parent_txn_id", "", "string"),
            "txn_amount"       => JArrayHelper::getValue($data, "mc_gross", 0, "float"),
            "txn_currency"     => JArrayHelper::getValue($data, "mc_currency", "", "string"),
            "txn_status"       => JString::strtolower(JArrayHelper::getValue($data, "payment_status", "", "string")),
            "txn_date"         => $date->toSql(),
            "status_reason"    => $this->getStatusReason($data),
            "extra_data"       => $extraData
        );


        // Check Project ID and Transaction ID
        if (!$transaction["project_id"] or !$transaction["txn_id"]) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                $this->debugType,
                $transaction
            );

            return null;
        }


        // Check currency
        if (strcmp($transaction["txn_currency"], $currency) != 0) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_CURRENCY"),
                $this->debugType,
                array("TRANSACTION DATA" => $transaction, "CURRENCY" => $currency)
            );

            return null;
        }


        // Check receiver
        $allowedReceivers = array(
            JString::strtolower(JArrayHelper::getValue($data, "business")),
            JString::strtolower(JArrayHelper::getValue($data, "receiver_email")),
            JString::strtolower(JArrayHelper::getValue($data, "receiver_id"))
        );

        if ($this->params->get("paypal_sandbox", 1)) {
            $receiver = JString::strtolower(JString::trim($this->params->get("paypal_sandbox_business_name")));
        } else {
            $receiver = JString::strtolower(JString::trim($this->params->get("paypal_business_name")));
        }

        if (!in_array($receiver, $allowedReceivers)) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_RECEIVER"),
                $this->debugType,
                array("TRANSACTION DATA" => $data, "RECEIVER" => $receiver, "RECEIVER DATA" => $allowedReceivers)
            );

            return null;
        }

        return $transaction;
    }


    /**
     * Save transaction.
     *
     * @param array     $transactionData
     * @param CrowdFundingProject $project
     *
     * @return null|array
     */
    protected function storeTransaction($transactionData, $project)
    {
        $transaction = $this->getTransaction($transactionData);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction record.
        if ($transaction->getId()) { // Update existed transaction record.

            // If the current status is completed,
            // stop the process to prevent overwriting data.
            if ($transaction->isCompleted()) {
                return null;
            }

            $txnStatus = JArrayHelper::getValue($transactionData, "txn_status");

            switch ($txnStatus) {

                case "completed":
                    $this->processCompleted($transaction, $project, $transactionData);
                    break;

                case "voided":
                    $this->processVoided($transaction, $project, $transactionData);
                    break;
            }

            return null;

        } else { // Create the new transaction data.

            // Store the transaction data.
            $transaction->bind($transactionData);
            $transaction->store();

            // Add funds to the project.
            if ($transaction->isCompleted() or $transaction->isPending()) {
                $amount = JArrayHelper::getValue($transactionData, "txn_amount");
                $project->addFunds($amount);
                $project->updateFunds();
            }

            // Set transaction ID.
            $transactionData["id"] = $transaction->getId();

            return $transactionData;
        }

    }

    /**
     * @param CrowdFundingTransaction $transaction
     * @param CrowdFundingProject $project
     * @param array $data
     *
     * @return bool
     */
    protected function processCompleted(&$transaction, &$project, &$data)
    {
        // Set a flag that shows the project is NOT funded.
        // If the status had not been completed or pending ( it might be failed, voided, created,...),
        // the project funds has not been increased. So, I will set this flag to how this below.
        $projectFunded = true;
        if (!$transaction->isCompleted() and !$transaction->isPending()) {
            $projectFunded = false;
        }

        // Merge existed extra data with the new one.
        $this->mergeExtraData($transaction, $data);

        // Update the transaction data.
        // If the current status is pending and the new status is completed,
        // only store the transaction data, updating the status to completed.
        $transaction->bind($data);
        $transaction->store();

        if (!$projectFunded and ($transaction->isCompleted() or $transaction->isPending())) {
            $amount = JArrayHelper::getValue($data, "txn_amount");
            $project->addFunds($amount);
            $project->updateFunds();
        }

        return true;
    }

    /**
     * @param CrowdFundingTransaction $transaction
     * @param CrowdFundingProject $project
     * @param array $data
     *
     * @return bool
     */
    protected function processVoided(&$transaction, &$project, &$data)
    {
        // It is possible only to void a transaction with status "pending".
        if (!$transaction->isPending()) {
            return false;
        }

        // Merge existed extra data with the new one.
        $this->mergeExtraData($transaction, $data);

        // Set transaction data to canceled.
        $data["txn_status"] = "canceled";

        // Update the transaction data.
        // If the current status is pending and the new status is completed,
        // only store the transaction data, updating the status to completed.
        $transaction->bind($data);
        $transaction->store();

        $amount = JArrayHelper::getValue($data, "txn_amount");
        $project->removeFunds($amount);
        $project->updateFunds();

        return true;
    }

    /**
     * Create and return transaction object.
     *
     * @param array $data
     *
     * @return CrowdFundingTransaction
     */
    protected function getTransaction($data)
    {
        // Prepare keys used for getting transaction from DB.
        if (isset($data["parent_txn_id"])) {
            $keys = array(
                "txn_id" => JArrayHelper::getValue($data, "parent_txn_id")
            );
        } else {
            $keys = array(
                "txn_id" => JArrayHelper::getValue($data, "txn_id")
            );
        }

        // Get transaction by ID
        jimport("crowdfunding.transaction");
        $transaction = new CrowdFundingTransaction(JFactory::getDbo());
        $transaction->load($keys);

        return $transaction;
    }

    protected function getDoCheckoutUrl($projectId)
    {
        $page = JString::trim($this->params->get('paypal_docheckout_url'));

        $uri    = JUri::getInstance();
        $domain = $uri->toString(array("host"));

        if (false == strpos($page, $domain)) {
            $page = JUri::root() . $page;
        }

        $page .= "&pid=" . (int)$projectId;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_DOCHECKOUT_URL"), $this->debugType, $page) : null;

        return $page;
    }

    protected function getNotifyUrl()
    {
        $page = JString::trim($this->params->get('paypal_notify_url'));

        $uri    = JUri::getInstance();
        $domain = $uri->toString(array("host"));

        if (false == strpos($page, $domain)) {
            $page = JUri::root() . $page;
        }

        if (false === strpos($page, "payment_service=PayPal")) {
            $page .= "&payment_service=PayPal";
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_NOTIFY_URL"), $this->debugType, $page) : null;

        return $page;
    }

    protected function getReturnUrl($slug, $catslug)
    {
        $page = JString::trim($this->params->get('paypal_return_url'));
        if (!$page) {
            $uri  = JUri::getInstance();
            $page = $uri->toString(array("scheme", "host")) . JRoute::_(CrowdFundingHelperRoute::getBackingRoute($slug, $catslug, "share"), false);
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $page) : null;

        return $page;
    }

    protected function getCancelUrl($slug, $catslug)
    {
        $page = JString::trim($this->params->get('paypal_cancel_url'));
        if (!$page) {
            $uri  = JUri::getInstance();
            $page = $uri->toString(array("scheme", "host")) . JRoute::_(CrowdFundingHelperRoute::getBackingRoute($slug, $catslug, "default"), false);
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_CANCEL_URL"), $this->debugType, $page) : null;

        return $page;
    }

    protected function isPayPalGateway($custom)
    {
        $paymentGateway = JArrayHelper::getValue($custom, "gateway");

        if (strcmp("PayPal", $paymentGateway) != 0) {
            return false;
        }

        return true;
    }

    protected function prepareLocale(&$html)
    {
        // Get country
        jimport("crowdfunding.country");
        $countryId = $this->params->get("paypal_country");
        $country   = new CrowdFundingCountry(JFactory::getDbo());
        $country->load($countryId);

        $code  = $country->getCode();
        $code4 = $country->getCode4();

        $button    = $this->params->get("paypal_button_type", "btn_buynow_LG");
        $buttonUrl = $this->params->get("paypal_button_url");

        // Generate a button
        if (!$this->params->get("paypal_button_default", 0)) {

            if (!$buttonUrl) {

                if (strcmp("US", $code) == 0) {
                    $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/' . $code4 . '/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . "_BUTTON_ALT") . '">';
                } else {
                    $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/' . $code4 . '/' . $code . '/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . "_BUTTON_ALT") . '">';
                }

            } else {
                $html[] = '<input type="image" name="submit" border="0" src="' . $buttonUrl . '" alt="' . JText::_($this->textPrefix . "_BUTTON_ALT") . '">';
            }

        } else { // Default button

            $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/en_US/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . "_BUTTON_ALT") . '">';

        }

        // Set locale
        $html[] = '<input type="hidden" name="lc" value="' . $code . '" />';

    }

    /**
     * Remove an intention record or create a payment session record.
     *
     * @param CrowdFundingIntention|CrowdFundingPaymentSession $intention
     * @param string                                           $txnStatus
     */
    protected function removeIntention($intention, $txnStatus)
    {
        // If status is NOT completed create a payment session.
        if (strcmp("completed", $txnStatus) != 0) {

            // If intention object is instance of CrowdFundingIntention,
            // create a payment session record and remove intention record.
            // If it is NOT instance of CrowdFundingIntention, do NOT remove the record,
            // because it will be used again when PayPal sends a response with status "completed".
            if ($intention instanceof CrowdFundingIntention) {

                jimport("crowdfunding.payment.session");
                $paymentSession = new CrowdFundingPaymentSession(JFactory::getDbo());
                $paymentSession
                    ->setUserId($intention->getUserId())
                    ->setAnonymousUserId($intention->getAnonymousUserId())
                    ->setProjectId($intention->getProjectId())
                    ->setRewardId($intention->getRewardId())
                    ->setRecordDate($intention->getRecordDate())
                    ->setGateway($intention->getGateway())
                    ->setGatewayData($intention->getGatewayData())
                    ->setIntentionId($intention->getId())
                    ->setUniqueKey($intention->getUniqueKey())
                    ->setSessionId($intention->getSessionId());

                $paymentSession->store();

                // DEBUG DATA
                JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PAYMENT_SESSION"), $this->debugType, $paymentSession->getProperties()) : null;

                // Remove intention object.
                $intention->delete();
            }

            // If transaction status is completed, remove intention record.
        } elseif (strcmp("completed", $txnStatus) == 0) {
            $intention->delete();
        }

    }

    protected function getStatusReason($data)
    {
        $pendingReason = JArrayHelper::getValue($data, "pending_reason");
        if (!is_null($pendingReason)) {
            return $pendingReason;
        }

        return "";
    }

    protected function getExtraData($data)
    {
        // Get transaction type.
        $trackId = JArrayHelper::getValue($data, "ipn_track_id");

        $keys = array(
            "payment_date", "parent_txn_id", "payment_status", "txn_type", "payment_type",
            "pending_reason", "transaction_entity", "custom", "notify_version", "ipn_track_id"
        );

        $extraData = array($trackId => array());
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $extraData[$trackId][$key] = $data[$key];
            }
        }

        return $extraData;
    }

    /**
     * Prepare credentials for sandbox or for the live server.
     *
     * @param Joomla\Registry\Registry $options
     */
    protected function prepareCredentials(&$options)
    {
        $options->set("api.version", 109);

        if ($this->params->get("paypal_sandbox", 1)) {
            $options->set("credentials.username", JString::trim($this->params->get("paypal_sandbox_api_username")));
            $options->set("credentials.password", JString::trim($this->params->get("paypal_sandbox_api_password")));
            $options->set("credentials.signature", JString::trim($this->params->get("paypal_sandbox_api_signature")));
        } else {
            $options->set("credentials.username", JString::trim($this->params->get("paypal_api_username")));
            $options->set("credentials.password", JString::trim($this->params->get("paypal_api_password")));
            $options->set("credentials.signature", JString::trim($this->params->get("paypal_api_signature")));
        }
    }

    /**
     * Return PayPal API URL.
     *
     * @return string
     */
    protected function getApiUrl()
    {
        if ($this->params->get("paypal_sandbox", 1)) {
            return JString::trim($this->params->get("paypal_sandbox_api_url"));
        } else {
            return JString::trim($this->params->get("paypal_api_url"));
        }
    }
}
