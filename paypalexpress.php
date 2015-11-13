<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('EmailTemplates.init');

/**
 * Crowdfunding PayPal Express payment plugin.
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentPayPalExpress extends Crowdfunding\Payment\Plugin
{
    protected $extraDataKeys  = array(
        'payment_date', 'parent_txn_id', 'payment_status', 'txn_type', 'payment_type',
        'pending_reason', 'transaction_entity', 'custom', 'notify_version', 'ipn_track_id'
    );

    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);

        $this->serviceProvider = 'PayPal Express';
        $this->serviceAlias    = 'paypalexpress';
        $this->textPrefix     .= '_' . \JString::strtoupper($this->serviceAlias);
        $this->debugType      .= '_' . \JString::strtoupper($this->serviceAlias);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string                   $context This string gives information about that where it has been executed the trigger.
     * @param stdClass                 $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = 'plugins/crowdfundingpayment/paypalexpress';

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="' . $pluginURI . '/images/paypal_icon.png" width="36" height="32" alt="PayPal" />' . JText::_($this->textPrefix . '_TITLE') . '</h4>';

        $html[] = '<form action="' . JRoute::_('index.php?option=com_crowdfunding') . '" method="post">';

        $html[] = '<input type="hidden" name="payment_service" value="'.$this->serviceAlias.'" />';
        $html[] = '<input type="hidden" name="task" value="payments.checkout" />';
        $html[] = '<input type="hidden" name="pid" value="' . $item->id . '" />';
        $html[] = JHtml::_('form.token');

        $this->prepareLocale($html);

        $html[] = '<img alt="" border="0" width="1" height="1" src="https://www.paypal.com/en_US/i/scr/pixel.gif" />';
        $html[] = '</form>';

        $html[] = '<p class="bg-info p-10-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_INFO') . '</p>';

        if ($this->params->get('paypal_sandbox', 1)) {
            $html[] = '<div class="bg-info p-10-5"><span class="fa fa-info-circle"></span> ' . JText::_($this->textPrefix . '_WORKS_SANDBOX') . '</div>';
        }

        $html[] = '</div>'; // Close "well".

        return implode("\n", $html);
    }

    /**
     * Process payment transaction.
     *
     * @param string $context
     * @param stdClass $item
     * @param Joomla\Registry\Registry $params
     *
     * @return null|array
     */
    public function onPaymentsCheckout($context, &$item, &$params)
    {
        if (strcmp('com_crowdfunding.payments.checkout.'.$this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $output = array();

        $cancelUrl = $this->getCancelUrl($item->slug, $item->catslug);
        $returnUrl = $this->getDoCheckoutUrl($item->id);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CANCEL_URL'), $this->debugType, $cancelUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RETURN_URL'), $this->debugType, $returnUrl) : null;

        // Get country and locale code.
        $countryId = $this->params->get('paypal_country');

        $country = new Crowdfunding\Country(JFactory::getDbo());
        $country->load($countryId);

        $localeCode = $country->getCode4();

        // Create transport object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $options->set('urls.return', $returnUrl);
        $options->set('urls.cancel', $cancelUrl);

        $this->prepareCredentials($options);

        $options->set('locale.code', $localeCode);

        $options->set('style.logo_image', JString::trim($this->params->get('paypal_image_url')));

        $options->set('payment.action', 'Order');
        $options->set('payment.amount', $item->amount);
        $options->set('payment.currency', $item->currencyCode);

        $title = JText::sprintf($this->textPrefix . '_INVESTING_IN_S', htmlentities($item->title, ENT_QUOTES, 'UTF-8'));
        $options->set('payment.description', $title);

        // Get payment session.
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            'session_id'    => $paymentSessionLocal->session_id
        ));

        // Prepare custom data
        $custom = array(
            'payment_session_id' => $paymentSession->getId(),
            'gateway'            => $this->serviceAlias
        );

        $custom = base64_encode(json_encode($custom));
        $options->set('payment.custom', $custom);

        // Get API url.
        $apiUrl = $this->getApiUrl();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_EXPRESS_CHECKOUT_OPTIONS'), $this->debugType, $options->toArray()) : null;

        $express = new Prism\Payment\PayPal\Express($apiUrl, $options);

        $express->setTransport($http);

        $response = $express->setExpressCheckout();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_EXPRESS_CHECKOUT_RESPONSE'), $this->debugType, $response) : null;

        $token = Joomla\Utilities\ArrayHelper::getValue($response, 'TOKEN');
        if (!$token) {
            return null;
        }

        // Store token to the payment session.
        $paymentSession->setUniqueKey($token);
        $paymentSession->storeUniqueKey();

        // Get PayPal checkout URL.
        if ($this->params->get('paypal_sandbox', 1)) {
            $output['redirect_url'] = $this->params->get('paypal_sandbox_url', 'https://www.sandbox.paypal.com/cgi-bin/webscr') . '?cmd=_express-checkout&amp;useraction=commit&token=' . rawurlencode($token);
        } else {
            $output['redirect_url'] = $this->params->get('paypal_url', 'https://www.paypal.com/cgi-bin/webscr') . '?cmd=_express-checkout&amp;useraction=commit&token=' . rawurlencode($token);
        }

        return $output;
    }

    /**
     * Process payment transaction doing checkout.
     *
     * @param string $context
     * @param stdClass $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsDoCheckout($context, &$item, &$params)
    {
        if (strcmp('com_crowdfunding.payments.docheckout.'.$this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $output  = array();
        $token   = $this->app->input->get('token');
        $payerId = $this->app->input->get('PayerID');

        // Load payment session by token.
        $paymentSession = $this->getPaymentSession(array(
            'unique_key' => $token
        ));

        // Validate project ID and transaction.
        if ((int)$item->id !== $paymentSession->getProjectId()) {
            return null;
        }

        $notifyUrl = $this->getCallbackUrl();
        $returnUrl = $this->getReturnUrl($item->slug, $item->catslug);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_NOTIFY_URL'), $this->debugType, $notifyUrl) : null;
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RETURN_URL'), $this->debugType, $returnUrl) : null;

        // Create transport object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $options->set('urls.notify', $notifyUrl);

        $this->prepareCredentials($options);

        $options->set('authorization.token', $token);
        $options->set('authorization.payer_id', $payerId);

        $options->set('payment.action', 'Order');
        $options->set('payment.amount', number_format($item->amount, 2));
        $options->set('payment.currency', $item->currencyCode);

        // Get API url.
        $apiUrl = $this->getApiUrl();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCHECKOUT_OPTIONS'), $this->debugType, $options) : null;

        $express = new Prism\Payment\PayPal\Express($apiUrl, $options);

        $express->setTransport($http);

        $response = $express->doExpressCheckoutPayment();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCHECKOUT_RESPONSE'), $this->debugType, $response) : null;

        $output['redirect_url'] = $returnUrl;

        return $output;
    }

    /**
     * Capture payments.
     *
     * @param string $context
     * @param stdClass $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsCapture($context, &$item, &$params)
    {
        $allowedContext = array('com_crowdfunding.payments.capture.'.$this->serviceAlias, 'com_crowdfundingfinance.payments.capture.'.$this->serviceAlias);
        if (!in_array($context, $allowedContext, true)) {
            return null;
        }

        if (!$this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Create transport object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $this->prepareCredentials($options);

        $options->set('payment.authorization_id', $item->txn_id);
        $options->set('payment.amount', number_format($item->txn_amount, 2));
        $options->set('payment.currency', $item->txn_currency);
        $options->set('payment.complete_type', 'Complete');

        // Get API url.
        $apiUrl = $this->getApiUrl();

        try {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCAPTURE_OPTIONS'), $this->debugType, $options) : null;

            $express = new Prism\Payment\PayPal\Express($apiUrl, $options);

            $express->setTransport($http);

            $response = $express->doCapture();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCAPTURE_RESPONSE'), $this->debugType, $response) : null;

        } catch (Exception $e) {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_ERROR_DOCAPTURE'), $this->debugType, $e->getMessage()) : null;

            $message = array(
                'text' => JText::sprintf($this->textPrefix . '_CAPTURED_UNSUCCESSFULLY', $item->txn_id),
                'type' => 'error'
            );

            return $message;
        }

        $message = array(
            'text' => JText::sprintf($this->textPrefix . '_CAPTURED_SUCCESSFULLY', $item->txn_id),
            'type' => 'message'
        );

        return $message;
    }

    /**
     * Void payments.
     *
     * @param string $context
     * @param stdClass $item
     * @param Joomla\Registry\Registry $params
     *
     * @return array|null
     */
    public function onPaymentsVoid($context, &$item, &$params)
    {
        $allowedContext = array('com_crowdfunding.payments.void.' .$this->serviceAlias, 'com_crowdfundingfinance.payments.void.'.$this->serviceAlias);
        if (!in_array($context, $allowedContext, true)) {
            return null;
        }

        if (!$this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        // Create transport object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $transport = new JHttpTransportCurl($options);
        $http      = new JHttp($options, $transport);

        // Create payment object.
        $options = new Joomla\Registry\Registry;
        /** @var  $options Joomla\Registry\Registry */

        $this->prepareCredentials($options);

        $options->set('payment.authorization_id', $item->txn_id);

        // Get API url.
        $apiUrl = $this->getApiUrl();

        try {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOVOID_OPTIONS'), $this->debugType, $options) : null;

            $express = new Prism\Payment\PayPal\Express($apiUrl, $options);

            $express->setTransport($http);

            $response = $express->doVoid();

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOVOID_RESPONSE'), $this->debugType, $response) : null;

        } catch (Exception $e) {

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_ERROR_DOVOID'), $this->debugType, $e->getMessage()) : null;

            $message = array(
                'text' => JText::sprintf($this->textPrefix . '_VOID_UNSUCCESSFULLY', $item->txn_id),
                'type' => 'error'
            );

            return $message;
        }

        $message = array(
            'text' => JText::sprintf($this->textPrefix . '_VOID_SUCCESSFULLY', $item->txn_id),
            'type' => 'message'
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
        if (strcmp('com_crowdfunding.notify.'.$this->serviceAlias, $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentRaw */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Validate request method
        $requestMethod = $this->app->input->getMethod();
        if (strcmp('POST', $requestMethod) !== 0) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_REQUEST_METHOD'),
                $this->debugType,
                JText::sprintf($this->textPrefix . '_ERROR_INVALID_TRANSACTION_REQUEST_METHOD', $requestMethod)
            );

            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESPONSE'), $this->debugType, $_POST) : null;

        // Decode custom data
        $custom = Joomla\Utilities\ArrayHelper::getValue($_POST, 'custom');
        $custom = json_decode(base64_decode($custom), true);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_CUSTOM'), $this->debugType, $custom) : null;

        // Validate payment services.
        $gateway = Joomla\Utilities\ArrayHelper::getValue($custom, 'gateway');
        if (!$this->isValidPaymentGateway($gateway)) {
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_PAYMENT_GATEWAY'),
                $this->debugType,
                array('custom' => $custom, '_POST' => $_POST)
            );

            return null;
        }

        // Get PayPal URL
        if ($this->params->get('paypal_sandbox', 1)) {
            $url = JString::trim($this->params->get('paypal_sandbox_url', 'https://www.sandbox.paypal.com/cgi-bin/webscr'));
        } else {
            $url = JString::trim($this->params->get('paypal_url', 'https://www.paypal.com/cgi-bin/webscr'));
        }

        $paypalIPN       = new Prism\Payment\PayPal\Ipn($url, $_POST);
        $loadCertificate = (bool)$this->params->get('paypal_load_certificate', 0);
        $paypalIPN->verify($loadCertificate);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_IPN_OBJECT'), $this->debugType, $paypalIPN) : null;

        // Prepare the array that will be returned by this method
        $result = array(
            'project'          => null,
            'reward'           => null,
            'transaction'      => null,
            'payment_session'  => null,
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias
        );

        if ($paypalIPN->isVerified()) {

            // Get currency
            $currencyId = $params->get('project_currency');
            $currency   = Crowdfunding\Currency::getInstance(JFactory::getDbo(), $currencyId);

            // Get payment session data
            $paymentSessionId = Joomla\Utilities\ArrayHelper::getValue($custom, 'payment_session_id', 0, 'int');
            $paymentSession   = $this->getPaymentSession(array('id' => $paymentSessionId));

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION'), $this->debugType, $paymentSession->getProperties()) : null;

            // Validate transaction data
            $validData = $this->validateData($_POST, $currency->getCode(), $paymentSession);
            if ($validData === null) {
                return $result;
            }

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_VALID_DATA'), $this->debugType, $validData) : null;

            // Get project.
            $projectId = Joomla\Utilities\ArrayHelper::getValue($validData, 'project_id');
            $project   = Crowdfunding\Project::getInstance(JFactory::getDbo(), $projectId);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PROJECT_OBJECT'), $this->debugType, $project->getProperties()) : null;

            // Check for valid project
            if (!$project->getId()) {

                // Log data in the database
                $this->log->add(
                    JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'),
                    $this->debugType,
                    $validData
                );

                return $result;
            }

            // Set the receiver of funds
            $validData['receiver_id'] = $project->getUserId();

            // Save transaction data.
            // If it is not completed, return empty results.
            // If it is complete, continue with process transaction data
            $transactionData = $this->storeTransaction($validData, $project);
            if ($transactionData === null) {
                return $result;
            }

            // Update the number of distributed reward.
            $rewardId = Joomla\Utilities\ArrayHelper::getValue($transactionData, 'reward_id', 0, 'int');
            $reward   = null;
            if ($rewardId > 0) {
                $reward = $this->updateReward($transactionData);

                // Validate the reward.
                if (!$reward) {
                    $transactionData['reward_id'] = 0;
                }
            }


            //  Prepare the data that will be returned

            $result['transaction'] = Joomla\Utilities\ArrayHelper::toObject($transactionData);

            // Generate object of data based on the project properties
            $properties        = $project->getProperties();
            $result['project'] = Joomla\Utilities\ArrayHelper::toObject($properties);

            // Generate object of data based on the reward properties
            if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
                $properties       = $reward->getProperties();
                $result['reward'] = Joomla\Utilities\ArrayHelper::toObject($properties);
            }

            // Generate data object, based on the payment session properties.
            $properties       = $paymentSession->getProperties();
            $result['payment_session'] = Joomla\Utilities\ArrayHelper::toObject($properties);

            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RESULT_DATA'), $this->debugType, $result) : null;

            // Remove payment session.
            $txnStatus = (isset($result['transaction']->txn_status)) ? $result['transaction']->txn_status : null;
            $this->closePaymentSession($paymentSession, $txnStatus);

        } else {

            // Log error
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                array('error message' => $paypalIPN->getError(), 'paypalIPN' => $paypalIPN, '_POST' => $_POST)
            );

        }

        return $result;
    }

    /**
     * Validate PayPal transaction
     *
     * @param array  $data
     * @param string $currency
     * @param Crowdfunding\Payment\Session  $paymentSession
     *
     * @return array|null
     */
    protected function validateData($data, $currency, $paymentSession)
    {
        $parentId = Joomla\Utilities\ArrayHelper::getValue($data, 'parent_txn_id', '', 'string');
        if ($parentId !== '') {
            $transaction = new Crowdfunding\Transaction(JFactory::getDbo());
            $transaction->load(array('txn_id' => $parentId));

            $investorId = (int)$transaction->getInvestorId();
            $projectId  = (int)$transaction->getProjectId();
            $rewardId   = (int)$transaction->getRewardId();

        } else {
            $investorId = (int)$paymentSession->getUserId();
            $projectId = (int)$paymentSession->getProjectId();
            $rewardId = ($paymentSession->isAnonymous()) ? 0 : (int)$paymentSession->getRewardId();
        }

        $txnDate  = Joomla\Utilities\ArrayHelper::getValue($data, 'payment_date');
        $date     = new JDate($txnDate);

        // Get additional information from transaction.
        $extraData = $this->prepareExtraData($data);

        // Prepare transaction data
        $transaction = array(
            'investor_id'      => $investorId,
            'project_id'       => $projectId,
            'reward_id'        => $rewardId,
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias,
            'txn_id'           => Joomla\Utilities\ArrayHelper::getValue($data, 'txn_id', '', 'string'),
            'parent_txn_id'    => $parentId,
            'txn_amount'       => Joomla\Utilities\ArrayHelper::getValue($data, 'mc_gross', 0, 'float'),
            'txn_currency'     => Joomla\Utilities\ArrayHelper::getValue($data, 'mc_currency', '', 'string'),
            'txn_status'       => JString::strtolower(Joomla\Utilities\ArrayHelper::getValue($data, 'payment_status', '', 'string')),
            'txn_date'         => $date->toSql(),
            'status_reason'    => $this->getStatusReason($data),
            'extra_data'       => $extraData
        );

        // Check Project ID and Transaction ID
        if (!$transaction['project_id'] or !$transaction['txn_id']) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_DATA'),
                $this->debugType,
                $transaction
            );

            return null;
        }

        // Check currency
        if (strcmp($transaction['txn_currency'], $currency) !== 0) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_TRANSACTION_CURRENCY'),
                $this->debugType,
                array('TRANSACTION DATA' => $transaction, 'CURRENCY' => $currency)
            );

            return null;
        }

        // Check receiver
        $allowedReceivers = array(
            JString::strtolower(Joomla\Utilities\ArrayHelper::getValue($data, 'business')),
            JString::strtolower(Joomla\Utilities\ArrayHelper::getValue($data, 'receiver_email')),
            JString::strtolower(Joomla\Utilities\ArrayHelper::getValue($data, 'receiver_id'))
        );

        if ($this->params->get('paypal_sandbox', 1)) {
            $receiver = JString::strtolower(JString::trim($this->params->get('paypal_sandbox_business_name')));
        } else {
            $receiver = JString::strtolower(JString::trim($this->params->get('paypal_business_name')));
        }

        if (!in_array($receiver, $allowedReceivers, true)) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . '_ERROR_INVALID_RECEIVER'),
                $this->debugType,
                array('TRANSACTION DATA' => $data, 'RECEIVER' => $receiver, 'RECEIVER DATA' => $allowedReceivers)
            );

            return null;
        }

        return $transaction;
    }


    /**
     * Save transaction.
     *
     * @param array     $transactionData
     * @param Crowdfunding\Project $project
     *
     * @return null|array
     */
    protected function storeTransaction($transactionData, $project)
    {
        $transaction = $this->getTransaction($transactionData);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_OBJECT'), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction record.
        if ($transaction->getId()) { // Update existed transaction record.

            // If the current status is completed,
            // stop the process to prevent overwriting data.
            if ($transaction->isCompleted()) {
                return null;
            }

            $txnStatus = Joomla\Utilities\ArrayHelper::getValue($transactionData, 'txn_status');

            JDEBUG ? $this->log->add('txn_status', $this->debugType, $txnStatus) : null;

            switch ($txnStatus) {

                case 'completed':
                    $this->processCompleted($transaction, $project, $transactionData);
                    break;

                case 'voided':
                    $this->processVoided($transaction, $project, $transactionData);
                    break;
            }

            return null;

        } else { // Create the new transaction data.

            // Store the transaction data.
            $transaction->bind($transactionData, array('extra_data'));
            $transaction->addExtraData($transactionData['extra_data']);
            $transaction->store();

            // Add funds to the project.
            if ($transaction->isCompleted() or $transaction->isPending()) {
                $amount = Joomla\Utilities\ArrayHelper::getValue($transactionData, 'txn_amount');
                $project->addFunds($amount);
                $project->storeFunds();
            }

            // Set transaction ID.
            $transactionData['id'] = $transaction->getId();

            return $transactionData;
        }
    }

    /**
     * @param Crowdfunding\Transaction $transaction
     * @param Crowdfunding\Project $project
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

        // Update the transaction data.
        // If the current status is pending and the new status is completed,
        // only store the transaction data, updating the status to completed.
        $transaction->bind($data, array('extra_data'));
        $transaction->addExtraData($data['extra_data']);
        $transaction->store();

        if (!$projectFunded and ($transaction->isCompleted() or $transaction->isPending())) {
            $amount = Joomla\Utilities\ArrayHelper::getValue($data, 'txn_amount');
            $project->addFunds($amount);
            $project->storeFunds();
        }

        return true;
    }

    /**
     * @param Crowdfunding\Transaction $transaction
     * @param Crowdfunding\Project $project
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

        // Set transaction data to canceled.
        $data['txn_status'] = 'canceled';

        // Update the transaction data.
        // If the current status is pending and the new status is completed,
        // only store the transaction data, updating the status to completed.
        $transaction->bind($data, array('extra_data'));
        $transaction->addExtraData($data['extra_data']);
        $transaction->store();

        $amount = Joomla\Utilities\ArrayHelper::getValue($data, 'txn_amount');
        $project->removeFunds($amount);
        $project->storeFunds();

        return true;
    }

    /**
     * Create and return transaction object.
     *
     * @param array $data
     *
     * @return Crowdfunding\Transaction
     */
    protected function getTransaction($data)
    {
        $keys = array(
            'txn_id' => Joomla\Utilities\ArrayHelper::getValue($data, 'txn_id')
        );
        
        // Prepare keys used for getting transaction from DB.
        if (array_key_exists('parent_txn_id', $data)) {
            $keys = array(
                'txn_id' => Joomla\Utilities\ArrayHelper::getValue($data, 'parent_txn_id')
            );
        }

        // Get transaction by ID
        $transaction = new Crowdfunding\Transaction(JFactory::getDbo());
        $transaction->load($keys);

        return $transaction;
    }

    protected function getDoCheckoutUrl($projectId)
    {
        $page = JString::trim($this->params->get('docheckout_url'));

        $uri    = JUri::getInstance();
        $domain = $uri->toString(array('host'));

        if (false === strpos($page, $domain)) {
            $page = JUri::root() . $page;
        }

        $page .= '&pid=' . (int)$projectId;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_DOCHECKOUT_URL'), $this->debugType, $page) : null;

        return $page;
    }

    protected function prepareLocale(&$html)
    {
        // Get country
        $countryId = $this->params->get('paypal_country');
        $country   = new Crowdfunding\Country(JFactory::getDbo());
        $country->load($countryId);

        $code  = $country->getCode();
        $code4 = $country->getCode4();

        $button    = $this->params->get('paypal_button_type', 'btn_buynow_LG');
        $buttonUrl = $this->params->get('paypal_button_url');

        // Generate a button
        if (!$this->params->get('paypal_button_default', 0)) {

            if (!$buttonUrl) {

                if (strcmp("US", $code) == 0) {
                    $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/' . $code4 . '/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '" />';
                } else {
                    $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/' . $code4 . '/' . $code . '/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '" />';
                }

            } else {
                $html[] = '<input type="image" name="submit" border="0" src="' . $buttonUrl . '" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '" />';
            }

        } else { // Default button

            $html[] = '<input type="image" name="submit" border="0" src="https://www.paypalobjects.com/en_US/i/btn/' . $button . '.gif" alt="' . JText::_($this->textPrefix . '_BUTTON_ALT') . '" />';

        }

        // Set locale
        $html[] = '<input type="hidden" name="lc" value="' . $code . '" />';
    }

    protected function getStatusReason($data)
    {
        $pendingReason = Joomla\Utilities\ArrayHelper::getValue($data, 'pending_reason');
        if ($pendingReason !== null) {
            return $pendingReason;
        }

        return '';
    }

    /**
     * Prepare credentials for sandbox or for the live server.
     *
     * @param Joomla\Registry\Registry $options
     */
    protected function prepareCredentials(&$options)
    {
        $options->set('api.version', 109);

        if ($this->params->get('paypal_sandbox', 1)) {
            $options->set('credentials.username', JString::trim($this->params->get('paypal_sandbox_api_username')));
            $options->set('credentials.password', JString::trim($this->params->get('paypal_sandbox_api_password')));
            $options->set('credentials.signature', JString::trim($this->params->get('paypal_sandbox_api_signature')));
        } else {
            $options->set('credentials.username', JString::trim($this->params->get('paypal_api_username')));
            $options->set('credentials.password', JString::trim($this->params->get('paypal_api_password')));
            $options->set('credentials.signature', JString::trim($this->params->get('paypal_api_signature')));
        }
    }

    /**
     * Return PayPal API URL.
     *
     * @return string
     */
    protected function getApiUrl()
    {
        if ($this->params->get('paypal_sandbox', 1)) {
            return JString::trim($this->params->get('paypal_sandbox_api_url'));
        } else {
            return JString::trim($this->params->get('paypal_api_url'));
        }
    }
}
