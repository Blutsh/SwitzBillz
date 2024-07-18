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

use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CombinedAddress;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\HtmlOutput\HtmlOutput;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;

/**
 * Class SwitzBillzPreviewModuleFrontController
 * This class handles the preview of the QR Bill for the SwitzBillz module from the front office.
 */
class SwitzBillzPreviewModuleFrontController extends ModuleFrontController
{
    /**
     * Initialize content and handle AJAX request for QR Bill preview
     */
    public function initContent()
    {
        parent::initContent();

        if (Tools::isSubmit('ajax')) {
            $this->ajaxRequest();
        }
    }

    /**
     * Handle the AJAX request to generate and preview the QR Bill
     */
    public function ajaxRequest()
    {
        try {
            // Retrieve form parameters from request
            $companyName = Tools::getValue('SWITZBILLZ_COMPANY_NAME');
            $companyStreet = Tools::getValue('SWITZBILLZ_COMPANY_STREET');
            $companyCity = Tools::getValue('SWITZBILLZ_COMPANY_CITY');
            $companyCountry = Tools::getValue('SWITZBILLZ_COMPANY_COUNTRY');
            $qrrIban = Tools::getValue('SWITZBILLZ_QRR_IBAN');
            $besrId = Tools::getValue('SWITZBILLZ_BESR_ID');
            $additionalInfo = Tools::getValue('SWITZBILLZ_ADDITIONAL_INFO');
            $additionalInfoCustom = Tools::getValue('SWITZBILLZ_ADDITIONAL_INFO_CUSTOM');
            $references = Tools::getValue('SWITZBILLZ_REFERENCES');
            $referenceType = Tools::getValue('SWITZBILLZ_REFERENCE_TYPE');
            $amount = 100.00; // Example amount
            $currency = 'CHF'; // Example currency

            // Handle additional information based on the selected type
            switch ($additionalInfo) {
                case 'custom':
                    $additionalInfo = $additionalInfoCustom;
                    break;
                case 'invoice_ref':
                    $additionalInfo = 'REF - QITSOQUID';
                    break;
                default:
                    $additionalInfo = '';
            }

            // Determine the reference number based on the selected type
            switch ($referenceType) {
                case 'order_id':
                    $referenceNumber = '66';
                    break;
                case 'invoice_number':
                    $referenceNumber = '87';
                    break;
                case 'custom_reference':
                    $referenceNumber = preg_replace('/\D/', '0', $references);
                    break;
                default:
                    throw new Exception('Invalid reference type selected.');
            }

            // Ensure the reference number is numeric and not longer than 15 digits
            if (!ctype_digit($referenceNumber)) {
                $referenceNumber = preg_replace('/\D/', '0', $referenceNumber);
            }
            if (strlen($referenceNumber) > 15) {
                $referenceNumber = substr($referenceNumber, -15);
            }
            if (strlen($referenceNumber) < 15) {
                $referenceNumber = str_pad($referenceNumber, 15, '0', STR_PAD_RIGHT);
            }

            // Create a new QR Bill instance
            $qrBill = QrBill::create();

            // Set creditor information
            $qrBill->setCreditor(CombinedAddress::create(
                $companyName,
                $companyStreet,
                $companyCity,
                $companyCountry
            ));

            // Set creditor account
            $qrBill->setCreditorInformation(CreditorInformation::create($qrrIban));

            // Set debtor information (example data, replace with real data if needed)
            $qrBill->setUltimateDebtor(StructuredAddress::createWithStreet(
                'John Doe',
                'Musterstrasse 1',
                '',
                '8000',
                'Zurich',
                'CH'
            ));

            // Set payment amount and currency
            $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create(
                $currency,
                $amount
            ));

            // Set payment reference and additional information
            $referenceNumber = QrPaymentReferenceGenerator::generate(
                $besrId, // Using BESR-ID from configuration
                $referenceNumber // Example reference number
            );

            $qrBill->setPaymentReference(PaymentReference::create(
                PaymentReference::TYPE_QR,
                $referenceNumber
            ));
            $qrBill->setAdditionalInformation(AdditionalInformation::create($additionalInfo));

            // Check for violations
            $violations = $qrBill->getViolations();
            if (count($violations) > 0) {
                $violationMessages = [];
                foreach ($violations as $violation) {
                    $violationMessages[] = $violation->getMessage();
                }
                echo json_encode(['status' => 'error', 'errors' => $violationMessages]);
                exit;
            }

            // Generate QR code HTML output
            $htmlOutput = new HtmlOutput($qrBill, 'en');
            $qrCodeHtml = $htmlOutput->setPrintable(false)->getPaymentPart();

            // Return JSON response
            echo json_encode(['status' => 'success', 'qrCodeHtml' => $qrCodeHtml]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        exit;
    }
}
