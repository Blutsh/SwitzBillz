<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Configuration;
use Country;
use Currency;
use Language;
use Media;
use Order;
use PrestaShop\Module\SwitzBillz\Controller\SwitzBillzAdminPdfController;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Exception\ColumnNotFoundException;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Tools;
use Validate;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Class SwitzBillz
 * This class represents the SwitzBillz payment module for PrestaShop.
 * It handles installation, uninstallation, and hooks to integrate with PrestaShop.
 */
class SwitzBillz extends PaymentModule
{
    /**
     * Constructor
     * Initializes the module with essential information.
     */
    public function __construct()
    {
        $this->name = 'switzbillz';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Blutch';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '1.7.8.0',
            'max' => _PS_VERSION_,
        ];
        $this->module_key = '13147f55006576977c42ecabe884c9cf';

        parent::__construct();

        $this->controllers = ['validation', 'preview', 'downloadqrbill'];
        $this->displayName = $this->trans('SwitzBillz', [], 'Modules.Switzbillz.Switzbillz');
        $this->description = $this->trans('Add Swiss QR Invoices to your shop', [], 'Modules.Switzbillz.Switzbillz');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', [], 'Modules.Switzbillz.Switzbillz');
    }

    /**
     * Install the module
     * Registers the necessary hooks and order states.
     *
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->registerHook('actionOrderGridDefinitionModifier')
            && $this->registerHook('actionEmailSendBefore')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusUpdate')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->installPaymentMethod()
            && $this->installOrderState();
    }

    /**
     * Uninstall the module
     * Unregisters the hooks and removes the order states.
     *
     * @return bool
     */
    public function uninstall()
    {
        $this->uninstallOrderState();

        return parent::uninstall();
    }

    /**
     * Check if the module uses the new translation system
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/script.js');
            Media::addJsDef([
                'previewQrCodeLink' => $this->context->link->getModuleLink($this->name, 'preview'),
            ]);
        }
    }

    /**
     * Install a custom order state for "QR Bill Sent"
     *
     * @return bool
     */
    private function installOrderState()
    {
        $orderState = new OrderState();
        $languages = Language::getLanguages(false);
        $orderState->name = [];

        foreach ($languages as $language) {
            $orderState->name[$language['id_lang']] = $this->trans('QR Bill Sent', [], 'Modules.Switzbillz.Switzbillz');
        }

        $orderState->send_email = false;
        $orderState->color = '#4169E1';
        $orderState->hidden = false;
        $orderState->delivery = false;
        $orderState->logable = false;
        $orderState->invoice = false;

        if ($orderState->add()) {
            Configuration::updateValue('SWITZBILLZ_QR_BILL_SENT_STATE_ID', $orderState->id);
            copy(_PS_ROOT_DIR_ . '/img/os/1.gif', _PS_ROOT_DIR_ . '/img/os/' . $orderState->id . '.gif');
        } else {
            return false;
        }

        return true;
    }

    /**
     * Uninstall the custom order state for "QR Bill Sent"
     */
    private function uninstallOrderState()
    {
        $orderStateId = Configuration::get('SWITZBILLZ_QR_BILL_SENT_STATE_ID');
        if ($orderStateId) {
            $orderState = new OrderState($orderStateId);
            if (Validate::isLoadedObject($orderState)) {
                $orderState->delete();
            }
            Configuration::deleteByName('SWITZBILLZ_QR_BILL_SENT_STATE_ID');
        }
    }

    /**
     * Hook to validate the order and generate an invoice if necessary
     *
     * @param array $params
     */
    public function hookActionValidateOrder($params)
    {
        if (!$this->active) {
            return;
        }

        $order = $params['order'];

        // Check if the payment method is SwitzBillz
        if ($order->module == $this->name) {
            // Check if an invoice already exists
            if (!$order->hasInvoice()) {
                // Generate invoice
                $order->setInvoice(true);
            }
        }
    }

    /**
     * Hook to handle order status updates and ensure invoice generation
     *
     * @param array $params
     */
    public function hookActionOrderStatusUpdate($params)
    {
        if (!$this->active) {
            return;
        }

        $order = new Order((int) $params['id_order']);
        $newOrderState = $params['newOrderStatus'];

        // Define the paid state ID (update with the actual paid state ID from your system)
        $paidStateId = Configuration::get('PS_OS_PAYMENT');

        // Check if the payment method is SwitzBillz
        if ($order->module == $this->name && $newOrderState->id == $paidStateId) {
            // Ensure an invoice is generated
            if (!$order->hasInvoice()) {
                $order->setInvoice(true);
            }
        }
    }

    /**
     * Hook to modify the email content before sending
     *
     * @param array $params
     */
    public function hookActionEmailSendBefore($params)
    {
        if (!$this->active) {
            return;
        }

        // Define the order confirmation template
        $orderConfirmationTemplate = 'order_conf';

        // Get the "QR Bill Sent" order state ID
        $qrBillSentStateId = Configuration::get('SWITZBILLZ_QR_BILL_SENT_STATE_ID');

        // Check if the email being sent is the order confirmation email
        if ($params['template'] === $orderConfirmationTemplate) {
            // Get the order ID from the email parameters
            $orderId = (int) $params['templateVars']['{id_order}'];

            // Load the order object
            $order = new Order($orderId);

            // Check if the order state is "QR Bill Sent"
            if ($order->current_state == $qrBillSentStateId) {
                // Generate custom PDF invoice
                $pdfController = new SwitzBillzAdminPdfController();
                $response = $pdfController->generatePDF($orderId);
                $pdfContent = $response->getContent();

                // Add it as an attachment to the email
                $params['fileAttachment'] = [
                    [
                        'content' => $pdfContent,
                        'mime' => 'application/pdf',
                        'name' => 'QR_invoice.pdf',
                    ],
                ];
            }
        }
    }

    /**
     * Hook to display additional information on the order detail page
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayOrderDetail($params)
    {
        if (!$this->active) {
            return;
        }

        $order = new Order($params['order']->id);

        if ($order->module == $this->name) {
            $this->context->smarty->assign([
                'orderId' => $order->id,
                'qrBillDownloadLink' => $this->context->link->getModuleLink('switzbillz', 'downloadQrBill', ['orderId' => $order->id]),
                'downloadQrBillText' => $this->trans('Download QR Bill', [], 'Modules.Switzbillz.Switzbillz'),
            ]);

            return $this->fetch('module:switzbillz/views/templates/hook/display_order_detail.tpl');
        }
    }

    /**
     * Hook to modify the order grid definition by adding a custom action
     *
     * @param array $params
     */
    public function hookActionOrderGridDefinitionModifier(array $params)
    {
        if (!$this->active) {
            return;
        }

        $gridDefinition = $params['definition'];
        $actionsColumn = $this->getActionsColumn($gridDefinition);
        $actions = $actionsColumn->getOption('actions');

        if ($actions instanceof RowActionCollection) {
            $actions->add(
                (new LinkRowAction('switzbillz_button'))
                    ->setName($this->trans('View QR Bill', [], 'Modules.Switzbillz.Switzbillz'))
                    ->setIcon('qr_code_scanner')
                    ->setOptions([
                        'route' => 'switzbillz_generate_pdf',
                        'route_param_name' => 'orderId',
                        'route_param_field' => 'id_order',
                    ])
            );
        }

        $actionsColumn->setOptions([
            'actions' => $actions,
        ]);
    }

    /**
     * Hook to provide payment options for the module
     *
     * @param array $params
     *
     * @return array
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return [];
        }

        $paymentOptions = [
            $this->createPaymentOption(),
        ];

        return $paymentOptions;
    }

    /**
     * Create a payment option for the module
     *
     * @return PaymentOption
     */
    private function createPaymentOption()
    {
        $newOption = new PaymentOption();
        $newOption->setModuleName($this->name)
            ->setCallToActionText($this->trans('Pay by QR Bill', [], 'Modules.Switzbillz.Switzbillz'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:switzbillz/views/templates/hook/payment_infos.tpl'));

        return $newOption;
    }

    /**
     * Hook to handle the payment return process
     *
     * @param array $params
     *
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }

        $order = $params['order'];

        $this->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'total_to_pay' => Tools::displayPrice($order->getOrdersTotalPaid(), new Currency($order->id_currency), false),
            'status' => 'ok',
            'id_order' => $order->id,
        ]);

        return $this->fetch('module:switzbillz/views/templates/hook/payment_return.tpl');
    }

    /**
     * Render the module configuration form
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitSwitzBillzModule')) {
            $companyName = Tools::getValue('SWITZBILLZ_COMPANY_NAME');
            $companyStreet = Tools::getValue('SWITZBILLZ_COMPANY_STREET');
            $companyCity = Tools::getValue('SWITZBILLZ_COMPANY_CITY');
            $companyCountry = Tools::getValue('SWITZBILLZ_COMPANY_COUNTRY');
            $qrrIban = Tools::getValue('SWITZBILLZ_QRR_IBAN');
            $besrId = Tools::getValue('SWITZBILLZ_BESR_ID');
            $additionalInfo = Tools::getValue('SWITZBILLZ_ADDITIONAL_INFO');
            $additionalInfoCustom = Tools::getValue('SWITZBILLZ_ADDITIONAL_INFO_CUSTOM');
            $references = Tools::getValue('SWITZBILLZ_REFERENCES');
            $referenceType = Tools::getValue('SWITZBILLZ_REFERENCE_TYPE'); // Ensure this value is retrieved

            Configuration::updateValue('SWITZBILLZ_COMPANY_NAME', $companyName);
            Configuration::updateValue('SWITZBILLZ_COMPANY_STREET', $companyStreet);
            Configuration::updateValue('SWITZBILLZ_COMPANY_CITY', $companyCity);
            Configuration::updateValue('SWITZBILLZ_COMPANY_COUNTRY', $companyCountry);
            Configuration::updateValue('SWITZBILLZ_QRR_IBAN', $qrrIban);
            Configuration::updateValue('SWITZBILLZ_BESR_ID', $besrId);
            Configuration::updateValue('SWITZBILLZ_ADDITIONAL_INFO', $additionalInfo);
            Configuration::updateValue('SWITZBILLZ_ADDITIONAL_INFO_CUSTOM', $additionalInfoCustom);
            Configuration::updateValue('SWITZBILLZ_REFERENCES', $references);
            Configuration::updateValue('SWITZBILLZ_REFERENCE_TYPE', $referenceType); // Save the reference type

            $output .= $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Switzbillz.Switzbillz'));
        }

        $output .= $this->renderForm();

        return $output;
    }

    /**
     * Render the configuration form for the module
     *
     * @return string
     */
    private function renderForm()
    {
        // Get the list of countries
        $countries = Country::getCountries($this->context->language->id);

        // Prepare countries for the select input
        $countryOptions = [];
        foreach ($countries as $country) {
            $countryOptions[] = [
                'id_option' => $country['iso_code'],
                'name' => $country['name'],
            ];
        }

        // Define the descriptive text
        $descriptiveText = $this->trans('Please fill in the form below to configure your company details and generate a QR Bill. Make sure to preview & Validate the QR Bill before saving.', [], 'Modules.Switzbillz.Switzbillz');

        // Assign the descriptive text to the Smarty template
        $this->context->smarty->assign('descriptiveText', $descriptiveText);

        // Fetch the rendered content
        $descriptiveTextHtml = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/descriptive_text.tpl');

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Modules.Switzbillz.Switzbillz'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'free',
                        'name' => 'SWITZBILLZ_DESCRIPTIVE_TEXT',
                        'label' => '',
                        'col' => 'col-lg-9', // Optionally specify a column size
                        'desc' => $descriptiveTextHtml,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Your Company Name', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_COMPANY_NAME',
                        'size' => 20,
                        'required' => true,
                        'desc' => $this->trans('Enter your company name.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Your Company Street', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_COMPANY_STREET',
                        'size' => 20,
                        'required' => true,
                        'desc' => $this->trans('Enter your company street address.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Your Company City', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_COMPANY_CITY',
                        'size' => 20,
                        'required' => true,
                        'desc' => $this->trans('Enter your company city.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Your Company Country', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_COMPANY_COUNTRY',
                        'required' => true,
                        'options' => [
                            'query' => $countryOptions,
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                        'desc' => $this->trans('Select your company country.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('QR IBAN', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_QRR_IBAN',
                        'size' => 20,
                        'required' => true,
                        'desc' => $this->trans('Enter your QR IBAN.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('QR-IID', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_BESR_ID',
                        'size' => 20,
                        'required' => false,
                        'desc' => $this->trans('Enter your QR-IID. (Leave empty if Postfinance QR-IBAN)', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Additional Information', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_ADDITIONAL_INFO',
                        'required' => true,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 'invoice_ref',
                                    'name' => $this->trans('Invoice Unique Reference', [], 'Modules.Switzbillz.Switzbillz'),
                                ],
                                [
                                    'id_option' => 'custom',
                                    'name' => $this->trans('Custom', [], 'Modules.Switzbillz.Switzbillz'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                        'desc' => $this->trans('Select additional information type.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Custom Additional Information', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_ADDITIONAL_INFO_CUSTOM',
                        'size' => 20,
                        'desc' => $this->trans('Enter custom additional information.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Reference Type', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_REFERENCE_TYPE',
                        'required' => true,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => 'order_id',
                                    'name' => $this->trans('Order ID', [], 'Modules.Switzbillz.Switzbillz'),
                                ],
                                [
                                    'id_option' => 'invoice_number',
                                    'name' => $this->trans('Invoice Number', [], 'Modules.Switzbillz.Switzbillz'),
                                ],
                                [
                                    'id_option' => 'custom_reference',
                                    'name' => $this->trans('Custom Reference', [], 'Modules.Switzbillz.Switzbillz'),
                                ],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                        'desc' => $this->trans('Select the reference type to use.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Custom Reference', [], 'Modules.Switzbillz.Switzbillz'),
                        'name' => 'SWITZBILLZ_REFERENCES',
                        'size' => 20,
                        'desc' => $this->trans('Enter custom reference.', [], 'Modules.Switzbillz.Switzbillz'),
                    ],
                ],
                'buttons' => [
                    [
                        'title' => $this->trans('Preview & Validate QR Bill', [], 'Modules.Switzbillz.Switzbillz'),
                        'icon' => 'process-icon-preview',
                        'class' => 'btn btn-default btn-preview-qr',
                        'type' => 'button',
                        'id' => 'previewQrCodeButton',
                        'name' => 'previewQrCodeButton',
                        'js' => 'previewQrCode()',
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                    'class' => 'btn btn-default pull-right',
                    'id' => 'submitSwitzBillzModule',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSwitzBillzModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        $formHtml = $helper->generateForm([$fieldsForm]);

        return $formHtml;
    }

    /**
     * Get the configuration form values
     *
     * @return array
     */
    private function getConfigFormValues()
    {
        return [
            'SWITZBILLZ_COMPANY_NAME' => Tools::getValue('SWITZBILLZ_COMPANY_NAME', Configuration::get('SWITZBILLZ_COMPANY_NAME')),
            'SWITZBILLZ_COMPANY_STREET' => Tools::getValue('SWITZBILLZ_COMPANY_STREET', Configuration::get('SWITZBILLZ_COMPANY_STREET')),
            'SWITZBILLZ_COMPANY_CITY' => Tools::getValue('SWITZBILLZ_COMPANY_CITY', Configuration::get('SWITZBILLZ_COMPANY_CITY')),
            'SWITZBILLZ_COMPANY_COUNTRY' => Tools::getValue('SWITZBILLZ_COMPANY_COUNTRY', Configuration::get('SWITZBILLZ_COMPANY_COUNTRY')),
            'SWITZBILLZ_QRR_IBAN' => Tools::getValue('SWITZBILLZ_QRR_IBAN', Configuration::get('SWITZBILLZ_QRR_IBAN')),
            'SWITZBILLZ_BESR_ID' => Tools::getValue('SWITZBILLZ_BESR_ID', Configuration::get('SWITZBILLZ_BESR_ID')),
            'SWITZBILLZ_ADDITIONAL_INFO' => Tools::getValue('SWITZBILLZ_ADDITIONAL_INFO', Configuration::get('SWITZBILLZ_ADDITIONAL_INFO')),
            'SWITZBILLZ_ADDITIONAL_INFO_CUSTOM' => Tools::getValue('SWITZBILLZ_ADDITIONAL_INFO_CUSTOM', Configuration::get('SWITZBILLZ_ADDITIONAL_INFO_CUSTOM')),
            'SWITZBILLZ_REFERENCES' => Tools::getValue('SWITZBILLZ_REFERENCES', Configuration::get('SWITZBILLZ_REFERENCES')),
            'SWITZBILLZ_REFERENCE_TYPE' => Tools::getValue('SWITZBILLZ_REFERENCE_TYPE', Configuration::get('SWITZBILLZ_REFERENCE_TYPE')),
            'SWITZBILLZ_DESCRIPTIVE_TEXT' => Tools::getValue('SWITZBILLZ_DESCRIPTIVE_TEXT'),
        ];
    }

    /**
     * Get the column by its ID from the grid definition
     *
     * @param object $gridDefinition
     * @param string $id
     *
     * @return object
     *
     * @throws ColumnNotFoundException
     */
    private function getColumnById($gridDefinition, string $id)
    {
        foreach ($gridDefinition->getColumns() as $column) {
            if ($id === $column->getId()) {
                return $column;
            }
        }
        throw new ColumnNotFoundException(sprintf('Column with id "%s" not found in grid definition.', $id));
    }

    /**
     * Get the actions column from the grid definition
     *
     * @param object $gridDefinition
     *
     * @return object
     *
     * @throws ColumnNotFoundException
     */
    private function getActionsColumn($gridDefinition)
    {
        try {
            return $this->getColumnById($gridDefinition, 'actions');
        } catch (ColumnNotFoundException $e) {
            throw $e;
        }
    }

    /**
     * Install the payment method
     *
     * @return bool
     */
    private function installPaymentMethod()
    {
        $paymentOptions = $this->getPaymentOptions();
        $this->context->smarty->assign('paymentOptions', $paymentOptions);

        return true;
    }

    /**
     * Get the payment options for the module
     *
     * @return array
     */
    private function getPaymentOptions()
    {
        return [
            [
                'logo' => Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'),
                'call_to_action_text' => $this->trans('Pay with QR Bill', [], 'Modules.Switzbillz.Switzbillz'),
                'action' => $this->context->link->getModuleLink($this->name, 'payment', [], true),
            ],
        ];
    }
}
