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

namespace PrestaShop\Module\SwitzBillz\Controller;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Address;
use Currency;
use Customer;
use Karriere\PdfMerge\PdfMerge;
use Language;
use Order;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CombinedAddress;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class SwitzBillzAdminPdfController
 * This class handles the generation of PDF documents including QR bills for the SwitzBillz module
 */
class SwitzBillzAdminPdfController extends FrameworkBundleAdminController
{
    /**
     * Generate a PDF that includes both the PrestaShop invoice and the QR Bill
     *
     * @param int $orderId The ID of the order for which to generate the PDF
     *
     * @return Response The generated PDF as a response
     *
     * @throws \Exception If no invoice is found for the order
     */
    public function generatePDF(int $orderId): Response
    {
        // Get context
        $context = \Context::getContext();

        // Load order and related objects
        $order = new \Order($orderId);
        $orderInvoices = $order->getInvoicesCollection();
        $orderInvoice = null;

        if ($orderInvoices && $orderInvoices->count() > 0) {
            // Assuming the first invoice for the order
            $orderInvoice = $orderInvoices[0];
        } else {
            throw new \Exception('No invoice found for the order.');
        }

        $customer = new \Customer($order->id_customer);
        $address = new \Address($order->id_address_invoice);
        $currency = new \Currency($order->id_currency);
        $totalAmount = $order->getOrdersTotalPaid();

        // Generate PrestaShop PDF
        $prestaPDF = tempnam(_PS_TMP_IMG_DIR_, 'presta_');
        $pdf = new \PDF($orderInvoice, \PDF::TEMPLATE_INVOICE, $context->smarty);
        $pdfInline = $pdf->render(false);

        // Write the pdf string to a file
        file_put_contents($prestaPDF, $pdfInline);

        // Generate QR Bill
        $qrBill = $this->generateQrBill($order, $customer, $address, $totalAmount, $currency);

        // Get the customer's language ID
        $customer_language_id = $customer->id_lang;

        // Load the Language object using the language ID
        $language = new \Language($customer_language_id);

        // Get the language code (e.g., 'en', 'fr', etc.)
        $language_code = $language->iso_code;

        // if language code is not 'de' or 'fr' or 'it' or 'en', set it to 'en'
        if (!in_array($language_code, ['de', 'fr', 'it', 'en'])) {
            $language_code = 'en';
        }

        // Generate pdf of the qr bill
        $tcPdf = new \TCPDF('P', 'mm', 'A4', true, 'ISO-8859-1');
        $tcPdf->setPrintHeader(false);
        $tcPdf->setPrintFooter(false);
        $tcPdf->AddPage();
        $output = new TcPdfOutput($qrBill, $language_code, $tcPdf);
        $output->setPrintable(false)->getPaymentPart();

        $qrPDF = tempnam(_PS_TMP_IMG_DIR_, 'qr_');
        $tcPdf->Output($qrPDF, 'F');

        // Merge PrestaShop PDF and QR Bill PDF
        $pdfMerge = new PdfMerge();
        $pdfMerge->add($prestaPDF);
        $pdfMerge->add($qrPDF);

        $mergedPdfPath = tempnam(_PS_TMP_IMG_DIR_, 'merged_');
        $pdfMerge->merge($mergedPdfPath);

        // Read the merged PDF file content
        $mergedPdfContent = \Tools::file_get_contents($mergedPdfPath);

        // Clean up temporary files
        unlink($prestaPDF);
        unlink($qrPDF);
        unlink($mergedPdfPath);

        // Return the merged PDF content as the response
        return new Response(
            $mergedPdfContent,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="invoice_' . $orderInvoice->getInvoiceNumberFormatted($context->language->id) . '.pdf"',
            ]
        );
    }

    /**
     * Generate a QR Bill for the given order
     *
     * @param \Order $order The order object
     * @param \Customer $customer The customer object
     * @param \Address $address The address object
     * @param float $totalAmount The total amount of the order
     * @param \Currency $currency The currency object
     *
     * @return QrBill The generated QR Bill object
     *
     * @throws \Exception If no invoice is found for the order or if there is an invalid configuration
     */
    private function generateQrBill($order, $customer, $address, $totalAmount, $currency)
    {
        // Fetch configuration settings
        $companyName = \Configuration::get('SWITZBILLZ_COMPANY_NAME');
        $companyStreet = \Configuration::get('SWITZBILLZ_COMPANY_STREET');
        $companyCity = \Configuration::get('SWITZBILLZ_COMPANY_CITY');
        $companyCountry = \Configuration::get('SWITZBILLZ_COMPANY_COUNTRY');
        $qrrIban = \Configuration::get('SWITZBILLZ_QRR_IBAN');
        $besrId = \Configuration::get('SWITZBILLZ_BESR_ID');
        $additionalInfo = \Configuration::get('SWITZBILLZ_ADDITIONAL_INFO');
        $additionalInfoCustom = \Configuration::get('SWITZBILLZ_ADDITIONAL_INFO_CUSTOM');
        $references = \Configuration::get('SWITZBILLZ_REFERENCES');
        $referenceType = \Configuration::get('SWITZBILLZ_REFERENCE_TYPE', 'order_id'); // Provide a default value

        // Load the order invoice
        $orderInvoices = $order->getInvoicesCollection();
        $orderInvoice = null;

        if ($orderInvoices && $orderInvoices->count() > 0) {
            // Assuming the first invoice for the order
            $orderInvoice = $orderInvoices[0];
        } else {
            throw new \Exception('No invoice found for the order.');
        }

        switch ($additionalInfo) {
            case 'custom':
                $additionalInfo = $additionalInfoCustom;
                break;
            case 'invoice_ref':
                $additionalInfo = 'REF - ' . $order->getUniqReference();
                break;
            default:
                $additionalInfo = '';
        }

        // Determine the reference number based on the selected type
        switch ($referenceType) {
            case 'order_id':
                $referenceNumber = $order->id;
                break;
            case 'invoice_number':
                $referenceNumber = preg_replace('/\D/', '0', $orderInvoice->number);
                break;
            case 'custom_reference':
                $referenceNumber = preg_replace('/\D/', '0', $references);
                break;
            default:
                throw new \Exception('Invalid reference type selected.');
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

        // Ensure the country code is in the correct format
        $country = new \Country($address->id_country);
        $countryCode = $country->iso_code;

        if (!ctype_alpha($countryCode) || strlen($countryCode) !== 2) {
            throw new \Exception('Invalid country code format.');
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

        // Set debtor information
        $qrBill->setUltimateDebtor(StructuredAddress::createWithStreet(
            $customer->firstname . ' ' . $customer->lastname,
            $address->address1,
            $address->address2,
            $address->postcode,
            $address->city,
            $countryCode
        ));

        // Set payment amount and currency
        $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create(
            $currency->iso_code,
            $totalAmount
        ));

        // Set payment reference and additional information
        $referenceNumber = QrPaymentReferenceGenerator::generate(
            $besrId, // Using BESR-ID from configuration
            $referenceNumber // Sanitized reference number
        );

        $qrBill->setPaymentReference(PaymentReference::create(
            PaymentReference::TYPE_QR,
            $referenceNumber
        ));
        $qrBill->setAdditionalInformation(AdditionalInformation::create($additionalInfo));

        return $qrBill;
    }
}
