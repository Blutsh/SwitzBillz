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

use PrestaShop\Module\SwitzBillz\Controller\SwitzBillzAdminPdfController;

/**
 * Class SwitzBillzDownloadQrBillModuleFrontController
 * This class handles the download of QR Bill PDFs for the SwitzBillz module from the front office.
 */
class SwitzBillzDownloadQrBillModuleFrontController extends ModuleFrontController
{
    /**
     * Initialize content and handle the QR Bill PDF download
     */
    public function initContent()
    {
        parent::initContent();

        // Retrieve the order ID from the request
        $orderId = (int) Tools::getValue('orderId');

        if ($orderId) {
            $order = new Order($orderId);
            // Check if the order is valid and the payment module is SwitzBillz
            if (Validate::isLoadedObject($order) && $order->module == $this->module->name) {
                // Generate the PDF
                $pdfController = new SwitzBillzAdminPdfController();
                $response = $pdfController->generatePDF($orderId);

                // Send the PDF as a download
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="QR_invoice_' . $orderId . '.pdf"');
                echo $response->getContent();
                exit;
            }
        }

        // Redirect to the homepage if the order ID is not valid or the order is not loaded
        Tools::redirect('index.php');
    }
}
